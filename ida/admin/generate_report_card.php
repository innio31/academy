<?php
// ida/admin/exam_generate_cards.php — Step 4: Generate & Preview Report Cards
// Calculates class positions, averages, highest/lowest. Renders all 5 templates.
// Print single card, bulk-print all, or proceed to publish.
// ─────────────────────────────────────────────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /ida/login.php");
    exit();
}
if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

$school_id       = SCHOOL_ID;
$school_name_const = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Require record_id ─────────────────────────────────────────────────────────
$record_id   = isset($_GET['record_id'])   ? (int)$_GET['record_id']   : 0;
$preview_sid = isset($_GET['student_id'])  ? (int)$_GET['student_id']  : 0;
$print_all   = isset($_GET['print_all'])   && $_GET['print_all'] === '1';
$print_one   = isset($_GET['print_one'])   && $_GET['print_one'] === '1';

if (!$record_id) {
    header("Location: exam_record_setup.php");
    exit();
}

$success_msg = '';
$error_msg   = '';
if (!empty($_SESSION['flash_success'])) {
    $success_msg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ── Load exam record ──────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
    $stmt->execute([$record_id, $school_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $record = null;
}

if (!$record || ($record['status'] ?? '') === 'archived') {
    header("Location: exam_record_setup.php");
    exit();
}

$class   = $record['class'];
$session = $record['session'];
$term    = $record['term'];
$template = $record['template'] ?? 'classic';

// Decode score types & grading
$decoded      = json_decode($record['score_types'] ?? '{}', true);
$score_types  = $decoded['score_types']   ?? (isset($decoded[0]['label']) ? $decoded : []);
$grading_scale = $decoded['grading_scale'] ?? [
    ['grade' => 'A', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
    ['grade' => 'B', 'min' => 65, 'max' => 74, 'remark' => 'Very Good'],
    ['grade' => 'C', 'min' => 50, 'max' => 64, 'remark' => 'Good'],
    ['grade' => 'D', 'min' => 40, 'max' => 49, 'remark' => 'Pass'],
    ['grade' => 'F', 'min' => 0, 'max' => 39, 'remark' => 'Fail'],
];

// ── Load school details ───────────────────────────────────────────────────────
$school = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
    $stmt->execute([$school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { /* non-fatal */
}

$school_logo    = $school['logo_path']     ?? '/assets/loida/default.png';
$school_motto   = $school['motto']         ?? '';
$school_phone   = $school['contact_phone'] ?? '';
$school_email   = $school['contact_email'] ?? '';
$school_display = $school['school_name']   ?? $school_name_const;

// ── Helpers ───────────────────────────────────────────────────────────────────
function getGradeInfo(float $total, array $scale): array
{
    foreach ($scale as $r)
        if ($total >= (float)$r['min'] && $total <= (float)$r['max'])
            return ['grade' => $r['grade'], 'remark' => $r['remark']];
    return ['grade' => 'F', 'remark' => 'Fail'];
}

function ordinal(int $n): string
{
    if ($n <= 0) return '—';
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

function fmtDate(?string $d): string
{
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts ? date('d M Y', $ts) : '—';
}

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, admission_number, gender, dob, profile_picture, guardian_name
          FROM students
         WHERE school_id=? AND class=? AND status='active'
         ORDER BY full_name ASC
    ");
    $stmt->execute([$school_id, $class]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("generate students: " . $e->getMessage());
}

$total_students = count($students);

// ── Load subjects for this class ──────────────────────────────────────────────
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name
          FROM subjects s
          JOIN subject_classes sc ON sc.subject_id=s.id AND sc.school_id=?
         WHERE sc.class=? AND (s.school_id=? OR s.is_central=1)
         ORDER BY s.subject_name ASC
    ");
    $stmt->execute([$school_id, $class, $school_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("generate subjects: " . $e->getMessage());
}

// ── Load ALL scores for this class/session/term ───────────────────────────────
// Structure: $all_scores[student_id][subject_id] = row
$all_scores = [];
try {
    $student_ids = array_column($students, 'id');
    if (!empty($student_ids)) {
        $ph = implode(',', array_fill(0, count($student_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, subject_id, score_data, total_score, grade, subject_position
              FROM student_scores
             WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
            $all_scores[(int)$row['student_id']][(int)$row['subject_id']] = $row;
        }
    }
} catch (Exception $e) {
    error_log("generate scores: " . $e->getMessage());
}

// ── Load comments, traits, positions ─────────────────────────────────────────
$all_comments    = [];  // [student_id => row]
$all_affective   = [];
$all_psychomotor = [];
$all_positions   = [];  // [student_id => row] from student_positions

try {
    if (!empty($student_ids)) {
        $ph = implode(',', array_fill(0, count($student_ids), '?'));

        $stmt = $pdo->prepare("SELECT * FROM student_comments WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $all_comments[(int)$r['student_id']] = $r;

        $stmt = $pdo->prepare("SELECT * FROM affective_traits WHERE session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $all_affective[(int)$r['student_id']] = $r;

        $stmt = $pdo->prepare("SELECT * FROM psychomotor_skills WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $all_psychomotor[(int)$r['student_id']] = $r;

        $stmt = $pdo->prepare("SELECT * FROM student_positions WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $all_positions[(int)$r['student_id']] = $r;
    }
} catch (Exception $e) {
    error_log("generate comments/traits: " . $e->getMessage());
}

// ── Handle POST: recalculate & save class positions ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'calculate_positions') {

    $pdo->beginTransaction();
    try {
        // Build per-student totals across ALL subjects
        $student_totals = [];
        foreach ($students as $stu) {
            $sid   = (int)$stu['id'];
            $total = 0.0;
            $count = 0;
            foreach ($subjects as $sub) {
                $subid = (int)$sub['id'];
                if (isset($all_scores[$sid][$subid])) {
                    $total += (float)$all_scores[$sid][$subid]['total_score'];
                    $count++;
                }
            }
            $avg = $count > 0 ? round($total / count($subjects), 2) : 0; // avg over all subjects (not just entered)
            $student_totals[$sid] = ['total' => $total, 'avg' => $avg, 'count' => $count];
        }

        // Sort by total desc for ranking
        arsort($student_totals);   // sort by value (array), need custom
        uasort($student_totals, fn($a, $b) => $b['total'] <=> $a['total']);

        $sequential = (int)($record['sequential_positions'] ?? 0);
        $rank       = 1;
        $prev_total = null;
        $display_rank = 1;

        foreach ($student_totals as $sid => $data) {
            if ($prev_total !== null && $data['total'] < $prev_total) {
                $display_rank = $rank;
            }

            // Upsert student_positions
            $chk = $pdo->prepare("SELECT id FROM student_positions WHERE school_id=? AND student_id=? AND session=? AND term=? LIMIT 1");
            $chk->execute([$school_id, $sid, $session, $term]);
            $sp_id = $chk->fetchColumn();

            // Get promoted_to if already set
            $promo = $all_positions[$sid]['promoted_to'] ?? null;

            if ($sp_id) {
                $pdo->prepare("
                    UPDATE student_positions
                       SET class_position=?,total_marks=?,average=?,updated_at=NOW()
                     WHERE id=?
                ")->execute([$display_rank, $data['total'], $data['avg'], $sp_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO student_positions
                        (school_id,student_id,session,term,class_position,total_marks,average,promoted_to,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
                ")->execute([$school_id, $sid, $session, $term, $display_rank, $data['total'], $data['avg'], $promo]);
            }

            $prev_total = $data['total'];
            $rank++;
        }

        $pdo->commit();

        // Activity log
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id,user_type,activity,school_id) VALUES (?,?,?,?)")
                ->execute([$admin_id, 'admin', "Calculated class positions: {$class} {$term} Term {$session}", $school_id]);
        } catch (Exception $e) { /* skip */
        }

        $_SESSION['flash_success'] = "Class positions calculated for all {$total_students} students.";
        header("Location: exam_generate_cards.php?record_id={$record_id}" . ($preview_sid ? "&student_id={$preview_sid}" : ""));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("calculate_positions: " . $e->getMessage());
        $error_msg = "Error calculating positions: " . htmlspecialchars($e->getMessage());
    }
}

// ── Reload positions after possible recalc ────────────────────────────────────
try {
    if (!empty($student_ids)) {
        $ph   = implode(',', array_fill(0, count($student_ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM student_positions WHERE school_id=? AND session=? AND term=? AND student_id IN ($ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $all_positions[(int)$r['student_id']] = $r;
    }
} catch (Exception $e) { /* non-fatal */
}

// ── Class stats for report card ───────────────────────────────────────────────
$class_averages   = array_column($all_positions, 'average');
$highest_avg      = !empty($class_averages) ? max($class_averages) : 0;
$lowest_avg       = !empty($class_averages) ? min($class_averages) : 0;
$class_avg_mean   = !empty($class_averages) ? round(array_sum($class_averages) / count($class_averages), 1) : 0;
$positions_exist  = !empty($all_positions);

// Subject class-wide stats (highest/lowest per subject)
$subject_stats = [];
foreach ($subjects as $sub) {
    $subid  = (int)$sub['id'];
    $totals = [];
    foreach ($students as $stu) {
        $sid = (int)$stu['id'];
        if (isset($all_scores[$sid][$subid]))
            $totals[] = (float)$all_scores[$sid][$subid]['total_score'];
    }
    $subject_stats[$subid] = [
        'highest' => !empty($totals) ? max($totals) : null,
        'lowest'  => !empty($totals) ? min($totals) : null,
        'avg'     => !empty($totals) ? round(array_sum($totals) / count($totals), 1) : null,
    ];
}

// ── Default preview student ───────────────────────────────────────────────────
if (!$preview_sid && !empty($students)) {
    $preview_sid = (int)$students[0]['id'];
}

// Locate preview student in array
$preview_student = null;
$preview_idx     = 0;
foreach ($students as $i => $s) {
    if ((int)$s['id'] === $preview_sid) {
        $preview_student = $s;
        $preview_idx = $i;
        break;
    }
}
$prev_stu = $students[$preview_idx - 1] ?? null;
$next_stu = $students[$preview_idx + 1] ?? null;

// ── Completion stats ──────────────────────────────────────────────────────────
$students_with_scores    = count(array_unique(array_keys($all_scores)));
$students_with_comments  = count($all_comments);
$students_with_positions = count($all_positions);

// ── Template colour schemes ───────────────────────────────────────────────────
$tpl_themes = [
    'classic' => ['header_bg' => $primary_color,   'accent' => $secondary_color, 'text' => '#ffffff'],
    'modern'  => ['header_bg' => '#2d6a4f',         'accent' => '#40916c',        'text' => '#ffffff'],
    'vibrant' => ['header_bg' => '#6a0572',         'accent' => '#c9184a',        'text' => '#ffffff'],
    'minimal' => ['header_bg' => '#f8f9fa',         'accent' => '#343a40',        'text' => '#343a40'],
    'elegant' => ['header_bg' => '#3d2314',         'accent' => '#c9a84c',        'text' => '#ffffff'],
];
$theme = $tpl_themes[$template] ?? $tpl_themes['classic'];

// Print mode: only render cards, no chrome
$is_print = $print_all || $print_one;

// ── Render report card HTML for one student ───────────────────────────────────
function renderCard(
    array $stu,
    array $subjects,
    array $all_scores,
    array $all_comments,
    array $all_affective,
    array $all_psychomotor,
    array $all_positions,
    array $subject_stats,
    array $grading_scale,
    array $score_types,
    array $record,
    array $school,
    array $theme,
    string $school_display,
    float $highest_avg,
    float $lowest_avg,
    float $class_avg_mean,
    int $total_students
): string {
    $sid      = (int)$stu['id'];
    $pos_row  = $all_positions[$sid]  ?? [];
    $comments = $all_comments[$sid]   ?? [];
    $affect   = $all_affective[$sid]  ?? [];
    $psycho   = $all_psychomotor[$sid] ?? [];

    $class_pos = (int)($pos_row['class_position'] ?? 0);
    $avg       = (float)($pos_row['average'] ?? 0);
    $total_mks = (float)($pos_row['total_marks'] ?? 0);
    $promoted  = $pos_row['promoted_to'] ?? '';

    $hdr_bg  = $theme['header_bg'];
    $accent  = $theme['accent'];
    $hdr_txt = $theme['text'];
    $logo    = htmlspecialchars($school['logo_path'] ?? '/assets/loida/default.png');
    $motto   = htmlspecialchars($school['motto']         ?? '');
    $phone   = htmlspecialchars($school['contact_phone'] ?? '');
    $is_minimal = ($record['template'] ?? 'classic') === 'minimal';

    // Subject rows
    $subject_rows = '';
    $subj_count   = 0;
    foreach ($subjects as $sub) {
        $subid  = (int)$sub['id'];
        $score  = $all_scores[$sid][$subid] ?? null;
        if (!$score) continue;

        $total  = (float)$score['total_score'];
        $g      = getGradeInfo($total, $grading_scale);
        $pos    = (int)($score['subject_position'] ?? 0);
        $stats  = $subject_stats[$subid] ?? [];
        $subj_count++;

        // Individual score columns
        $score_cols = '';
        foreach ($score_types as $st) {
            $lbl = $st['label'];
            $val = $score['score_data'][$lbl] ?? '—';
            $score_cols .= '<td style="text-align:center;padding:4px 6px;border:1px solid #e0e0e0;">'
                . ($val !== null && $val !== '' ? htmlspecialchars((string)$val) : '—') . '</td>';
        }

        $pos_txt = $pos > 0 ? ordinal($pos) : '—';
        $grade_txt = htmlspecialchars($g['grade']);
        $remark_txt = htmlspecialchars($g['remark']);

        $high_txt = $stats['highest'] !== null ? htmlspecialchars((string)$stats['highest']) : '—';
        $low_txt  = $stats['lowest']  !== null ? htmlspecialchars((string)$stats['lowest'])  : '—';

        $show_sub_pos   = (int)($record['show_subject_position']    ?? 1);
        $show_low_high  = (int)($record['show_lowest_highest_class'] ?? 0);

        $opt_pos_col = $show_sub_pos  ? "<td style='text-align:center;padding:4px 6px;border:1px solid #e0e0e0;'>{$pos_txt}</td>" : '';
        $opt_lh_cols = $show_low_high ? "<td style='text-align:center;padding:4px 6px;border:1px solid #e0e0e0;'>{$high_txt}</td><td style='text-align:center;padding:4px 6px;border:1px solid #e0e0e0;'>{$low_txt}</td>" : '';

        $subject_rows .= "
        <tr>
            <td style='padding:4px 8px;border:1px solid #e0e0e0;font-size:11px;'>" . htmlspecialchars($sub['subject_name']) . "</td>
            {$score_cols}
            <td style='text-align:center;padding:4px 6px;border:1px solid #e0e0e0;font-weight:700;'>" . number_format($total, 1) . "</td>
            <td style='text-align:center;padding:4px 6px;border:1px solid #e0e0e0;font-weight:700;color:{$hdr_bg};'>{$grade_txt}</td>
            {$opt_pos_col}
            {$opt_lh_cols}
            <td style='padding:4px 6px;border:1px solid #e0e0e0;font-size:10px;color:#666;'>{$remark_txt}</td>
        </tr>";
    }

    if ($subj_count === 0) {
        $subject_rows = '<tr><td colspan="20" style="text-align:center;padding:12px;color:#999;">No scores recorded</td></tr>';
    }

    // Score column headers
    $score_hdrs = '';
    foreach ($score_types as $st) {
        $score_hdrs .= '<th style="background:' . $hdr_bg . ';color:' . $hdr_txt . ';padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:center;font-size:10px;white-space:nowrap;">'
            . htmlspecialchars($st['label']) . '<br><span style="font-weight:400;opacity:.8;">/' . (int)$st['max'] . '</span></th>';
    }

    $show_sub_pos_hdr   = (int)($record['show_subject_position']    ?? 1) ? '<th style="background:' . $hdr_bg . ';color:' . $hdr_txt . ';padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:center;font-size:10px;">Pos.</th>' : '';
    $show_low_high_hdrs = (int)($record['show_lowest_highest_class'] ?? 0) ? '<th style="background:' . $hdr_bg . ';color:' . $hdr_txt . ';padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:center;font-size:10px;">Highest</th><th style="background:' . $hdr_bg . ';color:' . $hdr_txt . ';padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:center;font-size:10px;">Lowest</th>' : '';

    // Student info
    $pic_src  = $stu['profile_picture']
        ? htmlspecialchars($stu['profile_picture'])
        : null;
    $pic_html = $pic_src
        ? '<img src="' . $pic_src . '" style="width:70px;height:70px;border-radius:6px;object-fit:cover;border:2px solid ' . $accent . ';">'
        : '<div style="width:70px;height:70px;border-radius:6px;background:' . $hdr_bg . ';display:flex;align-items:center;justify-content:center;color:white;font-size:22px;font-weight:700;border:2px solid ' . $accent . ';">'
        . strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(array_filter(explode(' ', $stu['full_name'])), 0, 2))))
        . '</div>';

    $dob_txt  = $stu['dob'] ? fmtDate($stu['dob']) : '—';
    $gen_txt  = $stu['gender'] ?? '—';
    $grd_txt  = htmlspecialchars($stu['class'] ?? '');
    $pos_txt_main = $class_pos > 0 ? ordinal($class_pos) . ' of ' . $total_students : '—';

    // Traits
    $af_fields = [
        'punctuality' => 'Punctuality',
        'attendance' => 'Attendance',
        'politeness' => 'Politeness',
        'honesty' => 'Honesty',
        'neatness' => 'Neatness',
        'reliability' => 'Reliability',
        'relationship' => 'Relationship',
        'self_control' => 'Self Control'
    ];
    $pm_fields = [
        'handwriting' => 'Handwriting',
        'verbal_fluency' => 'Verbal Fluency',
        'sports' => 'Sports',
        'handling_tools' => 'Handling Tools',
        'drawing_painting' => 'Drawing/Painting',
        'musical_skills' => 'Musical Skills'
    ];

    $af_rows = '';
    foreach ($af_fields as $f => $lbl) {
        $v = htmlspecialchars($affect[$f] ?? '—');
        $af_rows .= '<tr><td style="padding:3px 6px;font-size:10px;border-bottom:1px dotted #eee;">' . $lbl . '</td><td style="padding:3px 6px;font-size:10px;font-weight:700;text-align:center;border-bottom:1px dotted #eee;">' . $v . '</td></tr>';
    }
    $pm_rows = '';
    foreach ($pm_fields as $f => $lbl) {
        $v = htmlspecialchars($psycho[$f] ?? '—');
        $pm_rows .= '<tr><td style="padding:3px 6px;font-size:10px;border-bottom:1px dotted #eee;">' . $lbl . '</td><td style="padding:3px 6px;font-size:10px;font-weight:700;text-align:center;border-bottom:1px dotted #eee;">' . $v . '</td></tr>';
    }

    $days_opened  = (int)($record['days_school_opened'] ?? 0);
    $days_present = (int)($comments['days_present'] ?? 0);
    $days_absent  = (int)($comments['days_absent']  ?? ($days_opened - $days_present));
    $tc   = nl2br(htmlspecialchars($comments['teachers_comment']   ?? ''));
    $pc   = nl2br(htmlspecialchars($comments['principals_comment'] ?? ''));
    $tcn  = htmlspecialchars($comments['class_teachers_name'] ?? '');
    $pcn  = htmlspecialchars($comments['principals_name']     ?? '');

    $show_class_pos   = (int)($record['show_class_position']     ?? 1);
    $show_cum_avg     = (int)($record['show_cumulative_avg']      ?? 1);
    $show_lh_avg      = (int)($record['show_lowest_highest_avg']  ?? 1);
    $show_attendance  = (int)($record['show_attendance']          ?? 1);
    $show_affective   = (int)($record['show_affective_traits']    ?? 1);
    $show_psychomotor = (int)($record['show_psychomotor']         ?? 1);
    $show_promoted    = (int)($record['show_promoted_to']         ?? 1);

    // ── Minimal template overrides border/bg style ────────────────────────────
    $card_border = $is_minimal ? 'border:1px solid #dee2e6;' : "border-top:4px solid {$accent};";

    ob_start(); ?>
    <div class="report-card" style="width:720px;max-width:100%;margin:0 auto 40px;background:white;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.12);<?php echo $card_border; ?>font-family:'Times New Roman',Times,serif;page-break-after:always;">

        <!-- ── Header ──────────────────────────────────────────────────────── -->
        <div style="background:<?php echo $hdr_bg; ?>;padding:16px 20px;text-align:center;">
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="width:70px;vertical-align:middle;">
                        <img src="<?php echo $logo; ?>" alt="Logo"
                            style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:3px solid <?php echo $accent; ?>;"
                            onerror="this.style.display='none'">
                    </td>
                    <td style="vertical-align:middle;padding:0 12px;">
                        <div style="color:<?php echo $hdr_txt; ?>;font-size:16px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">
                            <?php echo htmlspecialchars($school_display); ?>
                        </div>
                        <?php if ($motto): ?>
                            <div style="color:<?php echo $hdr_txt; ?>;opacity:.85;font-size:11px;font-style:italic;margin-top:2px;">
                                <?php echo htmlspecialchars($motto); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($phone): ?>
                            <div style="color:<?php echo $hdr_txt; ?>;opacity:.75;font-size:10px;margin-top:2px;">
                                <?php echo htmlspecialchars($phone); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="width:80px;text-align:right;vertical-align:top;">
                        <div style="background:<?php echo $accent; ?>;color:<?php echo ($is_minimal ? '#333' : 'white'); ?>;font-size:9px;font-weight:700;padding:4px 8px;border-radius:4px;white-space:nowrap;">
                            <?php echo htmlspecialchars($term); ?> TERM<br><?php echo htmlspecialchars($session); ?>
                        </div>
                    </td>
                </tr>
            </table>
            <div style="margin-top:8px;background:rgba(255,255,255,.15);border-radius:4px;padding:4px 0;color:<?php echo $hdr_txt; ?>;font-size:11px;letter-spacing:1px;font-weight:600;text-transform:uppercase;">
                STUDENT REPORT CARD
            </div>
        </div>

        <!-- ── Student info ─────────────────────────────────────────────────── -->
        <div style="padding:12px 20px;background:<?php echo $is_minimal ? '#f8f9fa' : '#fafafa'; ?>;border-bottom:2px solid <?php echo $accent; ?>;">
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="width:78px;vertical-align:top;"><?php echo $pic_html; ?></td>
                    <td style="vertical-align:top;padding:0 14px;">
                        <table style="width:100%;border-collapse:collapse;font-size:11px;">
                            <tr>
                                <td style="padding:2px 0;color:#555;width:100px;">Student Name:</td>
                                <td style="padding:2px 0;font-weight:700;font-size:12px;text-transform:uppercase;"><?php echo htmlspecialchars($stu['full_name']); ?></td>
                                <td style="padding:2px 0;color:#555;width:90px;">Adm. Number:</td>
                                <td style="padding:2px 0;font-weight:700;"><?php echo htmlspecialchars($stu['admission_number']); ?></td>
                            </tr>
                            <tr>
                                <td style="padding:2px 0;color:#555;">Class:</td>
                                <td style="padding:2px 0;font-weight:600;"><?php echo $grd_txt; ?></td>
                                <td style="padding:2px 0;color:#555;">Gender:</td>
                                <td style="padding:2px 0;font-weight:600;"><?php echo $gen_txt; ?></td>
                            </tr>
                            <tr>
                                <td style="padding:2px 0;color:#555;">Date of Birth:</td>
                                <td style="padding:2px 0;font-weight:600;"><?php echo $dob_txt; ?></td>
                                <?php if ($show_class_pos): ?>
                                    <td style="padding:2px 0;color:#555;">Class Position:</td>
                                    <td style="padding:2px 0;font-weight:700;color:<?php echo $hdr_bg; ?>;font-size:13px;"><?php echo $pos_txt_main; ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php if ($show_promoted && $promoted): ?>
                                <tr>
                                    <td style="padding:2px 0;color:#555;">Promoted to:</td>
                                    <td style="padding:2px 0;font-weight:700;color:<?php echo $hdr_bg; ?>;" colspan="3"><?php echo htmlspecialchars($promoted); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Scores table ─────────────────────────────────────────────────── -->
        <div style="padding:0 20px 0;">
            <div style="font-size:10px;font-weight:700;color:<?php echo $hdr_bg; ?>;padding:8px 0 4px;text-transform:uppercase;letter-spacing:.5px;">Academic Performance</div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <thead>
                        <tr>
                            <th style="background:<?php echo $hdr_bg; ?>;color:<?php echo $hdr_txt; ?>;padding:5px 8px;border:1px solid rgba(255,255,255,.2);text-align:left;font-size:10px;">Subject</th>
                            <?php echo $score_hdrs; ?>
                            <th style="background:<?php echo $hdr_bg; ?>;color:<?php echo $hdr_txt; ?>;padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:center;font-size:10px;">Total<br><span style="font-weight:400;opacity:.8;">/<?php echo (int)$record['max_score']; ?></span></th>
                            <th style="background:<?php echo $hdr_bg; ?>;color:<?php echo $hdr_txt; ?>;padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:center;font-size:10px;">Grade</th>
                            <?php echo $show_sub_pos_hdr; ?>
                            <?php echo $show_low_high_hdrs; ?>
                            <th style="background:<?php echo $hdr_bg; ?>;color:<?php echo $hdr_txt; ?>;padding:5px 6px;border:1px solid rgba(255,255,255,.2);text-align:left;font-size:10px;">Remark</th>
                        </tr>
                    </thead>
                    <tbody><?php echo $subject_rows; ?></tbody>
                </table>
            </div>
        </div>

        <!-- ── Summary bar ──────────────────────────────────────────────────── -->
        <div style="display:flex;padding:10px 20px;background:<?php echo $is_minimal ? '#f8f9fa' : "rgba({$hdr_bg},0.05)"; ?>;border-top:1px solid #eee;border-bottom:1px solid #eee;margin-top:4px;gap:0;flex-wrap:wrap;">
            <?php if ($show_cum_avg): ?>
                <div style="flex:1;text-align:center;padding:4px;border-right:1px solid #ddd;min-width:80px;">
                    <div style="font-size:18px;font-weight:700;color:<?php echo $hdr_bg; ?>;"><?php echo number_format($avg, 1); ?>%</div>
                    <div style="font-size:9px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Average</div>
                </div>
            <?php endif; ?>
            <?php if ($show_class_pos): ?>
                <div style="flex:1;text-align:center;padding:4px;border-right:1px solid #ddd;min-width:80px;">
                    <div style="font-size:18px;font-weight:700;color:<?php echo $accent; ?>;"><?php echo $class_pos > 0 ? ordinal($class_pos) : '—'; ?></div>
                    <div style="font-size:9px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Position</div>
                </div>
            <?php endif; ?>
            <?php if ($show_lh_avg && $highest_avg > 0): ?>
                <div style="flex:1;text-align:center;padding:4px;border-right:1px solid #ddd;min-width:80px;">
                    <div style="font-size:15px;font-weight:700;color:#27ae60;"><?php echo number_format($highest_avg, 1); ?>%</div>
                    <div style="font-size:9px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Class Highest</div>
                </div>
                <div style="flex:1;text-align:center;padding:4px;border-right:1px solid #ddd;min-width:80px;">
                    <div style="font-size:15px;font-weight:700;color:#e74c3c;"><?php echo number_format($lowest_avg, 1); ?>%</div>
                    <div style="font-size:9px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Class Lowest</div>
                </div>
            <?php endif; ?>
            <div style="flex:1;text-align:center;padding:4px;min-width:80px;">
                <div style="font-size:15px;font-weight:700;color:#555;"><?php echo $total_students; ?></div>
                <div style="font-size:9px;color:#888;text-transform:uppercase;letter-spacing:.5px;">No. in Class</div>
            </div>
        </div>

        <!-- ── Traits & attendance ──────────────────────────────────────────── -->
        <?php if ($show_affective || $show_psychomotor || $show_attendance): ?>
            <div style="display:flex;padding:10px 20px;gap:12px;flex-wrap:wrap;border-bottom:1px solid #eee;">
                <?php if ($show_affective): ?>
                    <div style="flex:1;min-width:160px;">
                        <div style="font-size:9px;font-weight:700;color:<?php echo $hdr_bg; ?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;border-bottom:1px solid <?php echo $accent; ?>;padding-bottom:2px;">Affective Traits</div>
                        <table style="width:100%;border-collapse:collapse;"><?php echo $af_rows; ?></table>
                    </div>
                <?php endif; ?>
                <?php if ($show_psychomotor): ?>
                    <div style="flex:1;min-width:160px;">
                        <div style="font-size:9px;font-weight:700;color:<?php echo $hdr_bg; ?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;border-bottom:1px solid <?php echo $accent; ?>;padding-bottom:2px;">Psychomotor Skills</div>
                        <table style="width:100%;border-collapse:collapse;"><?php echo $pm_rows; ?></table>
                    </div>
                <?php endif; ?>
                <?php if ($show_attendance): ?>
                    <div style="min-width:130px;">
                        <div style="font-size:9px;font-weight:700;color:<?php echo $hdr_bg; ?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;border-bottom:1px solid <?php echo $accent; ?>;padding-bottom:2px;">Attendance</div>
                        <table style="border-collapse:collapse;font-size:10px;">
                            <tr>
                                <td style="padding:3px 4px;color:#555;">School opened:</td>
                                <td style="padding:3px 4px;font-weight:700;"><?php echo $days_opened; ?> days</td>
                            </tr>
                            <tr>
                                <td style="padding:3px 4px;color:#555;">Days present:</td>
                                <td style="padding:3px 4px;font-weight:700;color:#27ae60;"><?php echo $days_present; ?></td>
                            </tr>
                            <tr>
                                <td style="padding:3px 4px;color:#555;">Days absent:</td>
                                <td style="padding:3px 4px;font-weight:700;color:#e74c3c;"><?php echo $days_absent; ?></td>
                            </tr>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ── Comments ─────────────────────────────────────────────────────── -->
        <div style="padding:10px 20px;border-bottom:1px solid #eee;">
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="width:50%;vertical-align:top;padding-right:10px;">
                        <div style="font-size:9px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Class Teacher's Comment</div>
                        <div style="font-size:11px;font-style:italic;color:#333;margin-top:4px;min-height:28px;"><?php echo $tc ?: '<span style="color:#ccc;">—</span>'; ?></div>
                        <div style="margin-top:8px;border-top:1px solid #ddd;padding-top:3px;font-size:9px;color:#555;">
                            <?php echo $tcn ?: '___________________________'; ?> &nbsp;&nbsp; <span style="color:#aaa;">Signature: ___________</span>
                        </div>
                    </td>
                    <td style="width:50%;vertical-align:top;padding-left:10px;border-left:1px solid #eee;">
                        <div style="font-size:9px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Principal's Comment</div>
                        <div style="font-size:11px;font-style:italic;color:#333;margin-top:4px;min-height:28px;"><?php echo $pc ?: '<span style="color:#ccc;">—</span>'; ?></div>
                        <div style="margin-top:8px;border-top:1px solid #ddd;padding-top:3px;font-size:9px;color:#555;">
                            <?php echo $pcn ?: '___________________________'; ?> &nbsp;&nbsp; <span style="color:#aaa;">Signature: ___________</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Footer ───────────────────────────────────────────────────────── -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 20px;background:<?php echo $hdr_bg; ?>;flex-wrap:wrap;gap:6px;">
            <span style="color:<?php echo $hdr_txt; ?>;font-size:9px;opacity:.85;">
                Term closed: <?php echo fmtDate($record['current_closing_date'] ?? null); ?>
            </span>
            <span style="background:<?php echo $accent; ?>;color:<?php echo ($is_minimal ? '#333' : 'white'); ?>;font-size:9px;font-weight:700;padding:3px 10px;border-radius:12px;">
                <?php echo htmlspecialchars($session . ' ' . $term . ' Term'); ?>
            </span>
            <span style="color:<?php echo $hdr_txt; ?>;font-size:9px;opacity:.85;">
                Next term: <?php echo fmtDate($record['next_resumption_date'] ?? null); ?>
            </span>
        </div>

    </div>
<?php
    return ob_get_clean();
}

// ── Print modes: output only cards, then exit ─────────────────────────────────
if ($is_print) {
    $students_to_print = $print_all
        ? $students
        : array_filter($students, fn($s) => (int)$s['id'] === $preview_sid);
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Report Cards — <?php echo htmlspecialchars($school_display); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                background: #fff;
                font-family: 'Times New Roman', serif;
            }

            @media print {
                .no-print {
                    display: none !important;
                }

                body {
                    margin: 0;
                }

                .report-card {
                    box-shadow: none !important;
                    border-radius: 0 !important;
                }

                @page {
                    margin: 10mm;
                    size: A4;
                }
            }

            .print-toolbar {
                background: #1a3c5e;
                color: white;
                padding: 12px 20px;
                display: flex;
                gap: 12px;
                align-items: center;
            }

            .print-toolbar button {
                padding: 8px 16px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
            }

            .print-toolbar .btn-print {
                background: white;
                color: #1a3c5e;
            }

            .print-toolbar .btn-close {
                background: rgba(255, 255, 255, .2);
                color: white;
            }

            .cards-wrap {
                padding: 20px;
            }
        </style>
    </head>

    <body>
        <div class="print-toolbar no-print">
            <span style="font-size:14px;font-weight:600;">
                <?php echo $print_all ? "All {$total_students} Report Cards" : "Report Card — " . htmlspecialchars($preview_student['full_name'] ?? ''); ?>
            </span>
            <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
        <div class="cards-wrap">
            <?php foreach ($students_to_print as $stu):
                echo renderCard(
                    $stu,
                    $subjects,
                    $all_scores,
                    $all_comments,
                    $all_affective,
                    $all_psychomotor,
                    $all_positions,
                    $subject_stats,
                    $grading_scale,
                    $score_types,
                    $record,
                    $school,
                    $theme,
                    $school_display,
                    (float)$highest_avg,
                    (float)$lowest_avg,
                    (float)$class_avg_mean,
                    $total_students
                );
            endforeach; ?>
        </div>
        <script>
            // Auto-trigger print dialog when opened directly
            if (!document.referrer.includes('print_toolbar')) {
                setTimeout(() => window.print(), 600);
            }
        </script>
    </body>

    </html>
<?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($school_name_const); ?> — Generate Report Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --sidebar-w: 260px;
            --shadow: 0 2px 8px rgba(0, 0, 0, .08);
            --radius: 10px;
            --tr: all .25s ease;
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
            overflow-x: hidden;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary), var(--dark));
            color: white;
            padding: 20px 0;
            transition: transform .3s;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar.open {
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
            background: var(--secondary);
            border-radius: 8px;
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
            font-size: .7rem;
            opacity: .8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, .1);
            border-radius: 10px;
            margin: 15px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, .9);
            text-decoration: none;
            border-radius: 8px;
            transition: var(--tr);
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, .15);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, .2);
            border-left: 3px solid var(--secondary);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        .mob-btn {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--tr);
        }

        .overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .main {
            min-height: 100vh;
            padding: 20px;
            transition: var(--tr);
        }

        .top-header {
            background: white;
            padding: 18px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow);
        }

        .top-header h1 {
            color: var(--primary);
            font-size: 1.35rem;
            margin-bottom: 3px;
        }

        .top-header p {
            color: #777;
            font-size: .82rem;
        }

        .back-btn {
            background: white;
            border: 1px solid var(--light);
            color: var(--primary);
            padding: 9px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .83rem;
            text-decoration: none;
        }

        .step-bar {
            display: flex;
            background: white;
            border-radius: var(--radius);
            padding: 14px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            overflow-x: auto;
        }

        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 80px;
            position: relative;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--light);
            z-index: 0;
        }

        .step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            z-index: 1;
            position: relative;
            border: 2px solid transparent;
        }

        .s-done {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .s-cur {
            background: white;
            color: var(--primary);
            border-color: var(--primary);
        }

        .s-todo {
            background: var(--light);
            color: #999;
            border-color: var(--light);
        }

        .step-lbl {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            text-align: center;
        }

        .alert {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: .86rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }

        .alert i {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: white;
            border-radius: var(--radius);
            padding: 14px 16px;
            box-shadow: var(--shadow);
            border-top: 3px solid var(--primary);
        }

        .stat-box.green {
            border-top-color: var(--success);
        }

        .stat-box.amber {
            border-top-color: var(--warning);
        }

        .stat-box.red {
            border-top-color: var(--danger);
        }

        .stat-val {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-box.green .stat-val {
            color: var(--success);
        }

        .stat-box.amber .stat-val {
            color: var(--warning);
        }

        .stat-box.red .stat-val {
            color: var(--danger);
        }

        .stat-lbl {
            font-size: .74rem;
            color: #777;
            margin-top: 2px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 230px 1fr;
            gap: 20px;
            align-items: start;
        }

        .student-panel {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }

        .panel-hdr {
            background: var(--primary);
            color: white;
            padding: 14px 16px;
            font-size: .88rem;
            font-weight: 600;
        }

        .s-list {
            list-style: none;
            padding: 6px 0;
            max-height: 68vh;
            overflow-y: auto;
        }

        .s-list li a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            color: #333;
            text-decoration: none;
            font-size: .82rem;
            transition: background .15s;
        }

        .s-list li a:hover {
            background: #f5f6fa;
        }

        .s-list li a.active {
            background: #eef3ff;
            color: var(--primary);
            border-left: 3px solid var(--primary);
            font-weight: 600;
        }

        .s-av {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .pos-badge {
            font-size: .7rem;
            padding: 1px 6px;
            border-radius: 10px;
            background: var(--light);
            color: #555;
            margin-left: auto;
            flex-shrink: 0;
        }

        .pos-badge.set {
            background: #d4edda;
            color: #155724;
        }

        .panel-foot {
            font-size: .72rem;
            color: #888;
            padding: 8px 16px;
            border-top: 1px solid var(--light);
        }

        .action-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .action-card h2 {
            font-size: .95rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn {
            padding: 9px 18px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: .85rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            transition: var(--tr);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            opacity: .9;
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: .78rem;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .preview-wrap {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            overflow-x: auto;
        }

        .preview-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }

        .preview-nav h2 {
            font-size: .95rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checklist {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 8px;
            background: #f9f9f9;
            font-size: .83rem;
        }

        .check-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }

        .ci-ok {
            background: #d4edda;
            color: #155724;
        }

        .ci-warn {
            background: #fff3cd;
            color: #856404;
        }

        .ci-bad {
            background: #f8d7da;
            color: #721c24;
        }

        .tpl-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: .78rem;
            font-weight: 500;
            background: var(--light);
        }

        @media(min-width:768px) {

            .mob-btn,
            .overlay {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .main {
                margin-left: var(--sidebar-w);
            }
        }

        @media(max-width:960px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .student-panel {
                position: static;
            }

            .s-list {
                max-height: none;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
        }

        @media(max-width:767px) {
            .main {
                padding-top: 70px;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>

    <button class="mob-btn" id="menuBtn"><i class="fas fa-bars"></i></button>
    <div class="overlay" id="overlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name_const); ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>
        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>
        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-classes.php"><i class="fas fa-chalkboard"></i> Manage Classes</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
            <li><a href="report_card_dashboard.php" class="active"><i class="fas fa-file-invoice"></i> Process Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="sync.php"><i class="fas fa-sync-alt"></i> Sync</a></li>
            <li><a href="../ida/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main" id="mainContent">

        <div class="top-header">
            <div>
                <h1>Generate Report Cards</h1>
                <p>
                    <strong><?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?></strong>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($class); ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($session); ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($term); ?> Term
                    &nbsp;·&nbsp;
                    <span class="tpl-badge"><i class="fas fa-palette"></i> <?php echo ucfirst($template); ?> template</span>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to traits
                </a>
                <a href="exam_record_setup.php" class="back-btn">
                    <i class="fas fa-list"></i> All records
                </a>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_msg); ?></span></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i><span><?php echo $error_msg; ?></span></div>
        <?php endif; ?>

        <!-- Step bar -->
        <div class="step-bar">
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div>
                <div class="step-lbl">Setup record</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div>
                <div class="step-lbl">Enter scores</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-done"><i class="fas fa-check" style="font-size:10px"></i></div>
                <div class="step-lbl">Traits &amp; comments</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-cur">4</div>
                <div class="step-lbl">Generate cards</div>
            </div>
            <div class="step-item">
                <div class="step-circle s-todo">5</div>
                <div class="step-lbl">Publish</div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-val"><?php echo $total_students; ?></div>
                <div class="stat-lbl">Students</div>
            </div>
            <div class="stat-box <?php echo $students_with_scores >= $total_students ? 'green' : 'amber'; ?>">
                <div class="stat-val"><?php echo $students_with_scores; ?></div>
                <div class="stat-lbl">With scores</div>
            </div>
            <div class="stat-box <?php echo $students_with_comments >= $total_students ? 'green' : 'amber'; ?>">
                <div class="stat-val"><?php echo $students_with_comments; ?></div>
                <div class="stat-lbl">With comments</div>
            </div>
            <div class="stat-box <?php echo $positions_exist ? 'green' : 'red'; ?>">
                <div class="stat-val"><?php echo $students_with_positions; ?></div>
                <div class="stat-lbl">Positions set</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?php echo $class_avg_mean > 0 ? number_format($class_avg_mean, 1) . '%' : '—'; ?></div>
                <div class="stat-lbl">Class average</div>
            </div>
            <div class="stat-box green">
                <div class="stat-val"><?php echo $highest_avg > 0 ? number_format($highest_avg, 1) . '%' : '—'; ?></div>
                <div class="stat-lbl">Highest avg.</div>
            </div>
        </div>

        <!-- Checklist & calculate -->
        <div class="action-card">
            <h2><i class="fas fa-clipboard-check"></i> Pre-generation checklist</h2>
            <div class="checklist">
                <div class="check-item">
                    <div class="check-icon <?php echo $students_with_scores >= $total_students ? 'ci-ok' : 'ci-warn'; ?>">
                        <i class="fas <?php echo $students_with_scores >= $total_students ? 'fa-check' : 'fa-exclamation'; ?>"></i>
                    </div>
                    Scores entered for <?php echo $students_with_scores; ?> of <?php echo $total_students; ?> students
                    <?php if ($students_with_scores < $total_students): ?>
                        &nbsp;<a href="exam_score_entry.php?record_id=<?php echo $record_id; ?>" style="color:var(--warning);font-size:.78rem;">→ Enter missing scores</a>
                    <?php endif; ?>
                </div>
                <div class="check-item">
                    <div class="check-icon <?php echo $students_with_comments >= $total_students ? 'ci-ok' : 'ci-warn'; ?>">
                        <i class="fas <?php echo $students_with_comments >= $total_students ? 'fa-check' : 'fa-exclamation'; ?>"></i>
                    </div>
                    Traits &amp; comments completed for <?php echo $students_with_comments; ?> of <?php echo $total_students; ?> students
                    <?php if ($students_with_comments < $total_students): ?>
                        &nbsp;<a href="exam_traits_comments.php?record_id=<?php echo $record_id; ?>" style="color:var(--warning);font-size:.78rem;">→ Complete traits</a>
                    <?php endif; ?>
                </div>
                <div class="check-item">
                    <div class="check-icon <?php echo $positions_exist ? 'ci-ok' : 'ci-bad'; ?>">
                        <i class="fas <?php echo $positions_exist ? 'fa-check' : 'fa-times'; ?>"></i>
                    </div>
                    Class positions <?php echo $positions_exist ? 'calculated' : 'not yet calculated — click button below'; ?>
                </div>
            </div>
            <div class="btn-row">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="calculate_positions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calculator"></i>
                        <?php echo $positions_exist ? 'Recalculate positions & averages' : 'Calculate class positions'; ?>
                    </button>
                </form>
                <a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>&print_all=1"
                    target="_blank" class="btn btn-warning">
                    <i class="fas fa-print"></i> Print all <?php echo $total_students; ?> cards
                </a>
                <a href="exam_publish.php?record_id=<?php echo $record_id; ?>" class="btn btn-success">
                    <i class="fas fa-arrow-right"></i> Proceed to publish
                </a>
            </div>
        </div>

        <!-- Two-col: student list + preview -->
        <div class="content-grid">

            <!-- Student list -->
            <div class="student-panel">
                <div class="panel-hdr"><i class="fas fa-users"></i> Students &nbsp;<span style="font-weight:400;font-size:.78rem;opacity:.8;">(<?php echo $total_students; ?>)</span></div>
                <ul class="s-list">
                    <?php foreach ($students as $stu):
                        $s_id   = (int)$stu['id'];
                        $is_cur = $s_id === $preview_sid;
                        $pos_r  = $all_positions[$s_id] ?? null;
                        $c_pos  = $pos_r ? (int)$pos_r['class_position'] : 0;
                        $words  = array_filter(explode(' ', $stu['full_name']));
                        $inits  = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 2))));
                    ?>
                        <li>
                            <a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $s_id; ?>"
                                class="<?php echo $is_cur ? 'active' : ''; ?>">
                                <div class="s-av"><?php echo $inits; ?></div>
                                <span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.8rem;"><?php echo htmlspecialchars($stu['full_name']); ?></span>
                                <span class="pos-badge <?php echo $c_pos > 0 ? 'set' : ''; ?>">
                                    <?php echo $c_pos > 0 ? ordinal($c_pos) : '—'; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="panel-foot">
                    <span style="color:var(--success)">● <?php echo $students_with_positions; ?> positions set</span>
                    &nbsp;&nbsp;
                    <span style="color:#ccc">● <?php echo $total_students - $students_with_positions; ?> pending</span>
                </div>
            </div>

            <!-- Preview -->
            <div>
                <?php if ($preview_student): ?>
                    <div class="preview-wrap">
                        <div class="preview-nav">
                            <h2><i class="fas fa-eye"></i> Preview — <?php echo htmlspecialchars($preview_student['full_name']); ?></h2>
                            <div class="btn-row">
                                <?php if ($prev_stu): ?>
                                    <a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $prev_stu['id']; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-chevron-left"></i> Prev
                                    </a>
                                <?php endif; ?>
                                <?php if ($next_stu): ?>
                                    <a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $next_stu['id']; ?>" class="btn btn-secondary btn-sm">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>&student_id=<?php echo $preview_sid; ?>&print_one=1"
                                    target="_blank" class="btn btn-primary btn-sm">
                                    <i class="fas fa-print"></i> Print this card
                                </a>
                            </div>
                        </div>

                        <!-- Render the actual report card for preview -->
                        <div style="transform-origin:top left;overflow-x:auto;">
                            <?php echo renderCard(
                                $preview_student,
                                $subjects,
                                $all_scores,
                                $all_comments,
                                $all_affective,
                                $all_psychomotor,
                                $all_positions,
                                $subject_stats,
                                $grading_scale,
                                $score_types,
                                $record,
                                $school,
                                $theme,
                                $school_display,
                                (float)$highest_avg,
                                (float)$lowest_avg,
                                (float)$class_avg_mean,
                                $total_students
                            ); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="background:white;border-radius:var(--radius);box-shadow:var(--shadow);padding:60px 20px;text-align:center;color:#999;">
                        <i class="fas fa-file-alt" style="font-size:48px;margin-bottom:14px;opacity:.3;display:block;"></i>
                        <h3 style="font-weight:500;margin-bottom:8px;">Select a student to preview</h3>
                        <p style="font-size:.84rem;">Click any student on the left to see their report card.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /content-grid -->

        <div style="text-align:center;padding:20px;color:#999;font-size:.8rem;border-top:1px solid var(--light);margin-top:20px">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name_const); ?> — Online Portal
        </div>
    </div>

    <script>
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('overlay');
        const btn = document.getElementById('menuBtn');
        btn.addEventListener('click', () => {
            sb.classList.toggle('open');
            ov.classList.toggle('show');
            document.body.style.overflow = sb.classList.contains('open') ? 'hidden' : '';
        });
        ov.addEventListener('click', () => {
            sb.classList.remove('open');
            ov.classList.remove('show');
            document.body.style.overflow = '';
        });
    </script>

</body>

</html>
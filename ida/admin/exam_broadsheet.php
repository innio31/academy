<?php
// ida/admin/exam_broadsheet.php - Generate Class and Subject Broadsheets

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
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// ── Get parameters ────────────────────────────────────────────────────────────
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
$broadsheet_type = isset($_GET['type']) ? $_GET['type'] : 'class';
$view_mode = isset($_GET['mode']) ? $_GET['mode'] : 'minimal'; // minimal or full
$format = isset($_GET['format']) ? $_GET['format'] : 'html'; // html, pdf, csv
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if (!$record_id) {
    header("Location: exam_record_setup.php");
    exit();
}

// ── Load exam record ──────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
    $stmt->execute([$record_id, $school_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $record = null;
}

if (!$record) {
    header("Location: exam_record_setup.php");
    exit();
}

$class = $record['class'];
$session = $record['session'];
$term = $record['term'];

// ── Decode score types ────────────────────────────────────────────────────────
$decoded = json_decode($record['score_types'] ?? '{}', true);
$score_types = $decoded['score_types'] ?? [];
$grading_scale = $decoded['grading_scale'] ?? [];

if (empty($grading_scale)) {
    $grading_scale = [
        ['grade' => 'A', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B', 'min' => 65, 'max' => 74, 'remark' => 'Very Good'],
        ['grade' => 'C', 'min' => 50, 'max' => 64, 'remark' => 'Good'],
        ['grade' => 'D', 'min' => 40, 'max' => 49, 'remark' => 'Pass'],
        ['grade' => 'F', 'min' => 0, 'max' => 39, 'remark' => 'Fail'],
    ];
}

// ── Get class_id ──────────────────────────────────────────────────────────────
$class_id = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $class_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $class_id = $class_row ? (int)$class_row['id'] : 0;
} catch (Exception $e) { }

// ── Load students ─────────────────────────────────────────────────────────────
$students = [];
try {
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE school_id = ? AND class_id = ? AND status = 'active' ORDER BY full_name ASC");
        $stmt->execute([$school_id, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE school_id = ? AND class = ? AND status = 'active' ORDER BY full_name ASC");
        $stmt->execute([$school_id, $class]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { }

// ── Load subjects ─────────────────────────────────────────────────────────────
$subjects = [];
try {
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT s.id, s.subject_name FROM subjects s JOIN subject_classes sc ON sc.subject_id = s.id WHERE sc.school_id = ? AND sc.class_id = ? AND (s.school_id = ? OR s.is_central = 1) ORDER BY s.subject_name ASC");
        $stmt->execute([$school_id, $class_id, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT s.id, s.subject_name FROM subjects s JOIN subject_classes sc ON sc.subject_id = s.id WHERE sc.school_id = ? AND sc.class = ? AND (s.school_id = ? OR s.is_central = 1) ORDER BY s.subject_name ASC");
        $stmt->execute([$school_id, $class, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { }

// ── Load all scores ──────────────────────────────────────────────────────────
$scores = [];
$student_ids = array_column($students, 'id');
$subject_ids = array_column($subjects, 'id');

if (!empty($student_ids) && !empty($subject_ids)) {
    try {
        $student_ph = implode(',', array_fill(0, count($student_ids), '?'));
        $subject_ph = implode(',', array_fill(0, count($subject_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT student_id, subject_id, score_data, total_score, grade, subject_position 
            FROM student_scores 
            WHERE school_id = ? AND session = ? AND term = ? 
              AND student_id IN ($student_ph) AND subject_id IN ($subject_ph)
        ");
        $params = array_merge([$school_id, $session, $term], $student_ids, $subject_ids);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
            $scores[(int)$row['student_id']][(int)$row['subject_id']] = $row;
        }
    } catch (Exception $e) { }
}

// ── Load class positions ──────────────────────────────────────────────────────
$class_positions = [];
if (!empty($student_ids)) {
    try {
        $student_ph = implode(',', array_fill(0, count($student_ids), '?'));
        $stmt = $pdo->prepare("SELECT student_id, class_position, average, total_marks FROM student_positions WHERE school_id = ? AND session = ? AND term = ? AND student_id IN ($student_ph)");
        $stmt->execute(array_merge([$school_id, $session, $term], $student_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $class_positions[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) { }
}

// Calculate averages if not in database
foreach ($students as $student) {
    $sid = $student['id'];
    if (!isset($class_positions[$sid])) {
        $total = 0;
        $count = 0;
        if (isset($scores[$sid])) {
            foreach ($scores[$sid] as $subj_id => $data) {
                $total += (float)$data['total_score'];
                $count++;
            }
        }
        $avg = $count > 0 ? round($total / $count, 1) : 0;
        $class_positions[$sid] = ['average' => $avg, 'total_marks' => $total, 'class_position' => 0];
    }
}

// Calculate class averages
$class_average = 0;
$class_total = 0;
$class_highest = 0;
$class_lowest = 100;
foreach ($class_positions as $pos) {
    $avg = $pos['average'];
    $class_total += $avg;
    if ($avg > $class_highest) $class_highest = $avg;
    if ($avg < $class_lowest && $avg > 0) $class_lowest = $avg;
}
$class_average = count($class_positions) > 0 ? round($class_total / count($class_positions), 1) : 0;

// Helper function for ordinal
function ordinal($n) {
    if ($n <= 0) return '-';
    $n = (int)$n;
    $last_digit = $n % 10;
    $last_two = $n % 100;
    if ($last_two >= 11 && $last_two <= 13) return $n . 'th';
    switch ($last_digit) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
        default: return $n . 'th';
    }
}

// ── Handle CSV Export ─────────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="broadsheet_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $class) . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $term) . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $session) . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens the file without encoding issues
    fputs($output, "\xEF\xBB\xBF");
    
    if ($broadsheet_type === 'class') {
        // Class Broadsheet CSV
        if ($view_mode === 'minimal') {
            // Minimal: Student, Subject1, Subject2, ..., Total, Average, Position
            $headers = ['S/N', 'Student Name', 'Admission No'];
            foreach ($subjects as $sub) {
                $headers[] = $sub['subject_name'];
            }
            $headers[] = 'Total Marks';
            $headers[] = 'Average (%)';
            $headers[] = 'Position';
            fputcsv($output, $headers);
            
            $sn = 1;
            foreach ($students as $student) {
                $row = [$sn, $student['full_name'], $student['admission_number']];
                $student_total = 0;
                $student_count = 0;
                foreach ($subjects as $sub) {
                    $score = $scores[$student['id']][$sub['id']]['total_score'] ?? '';
                    $row[] = is_numeric($score) ? $score : '';
                    if (is_numeric($score)) {
                        $student_total += $score;
                        $student_count++;
                    }
                }
                $avg = $student_count > 0 ? round($student_total / $student_count, 1) : 0;
                $pos = $class_positions[$student['id']]['class_position'] ?? 0;
                $row[] = $student_total;
                $row[] = $avg;
                $row[] = ordinal($pos);
                fputcsv($output, $row);
                $sn++;
            }
        } else {
            // Full: Student, Subject1 scores breakdown (CA1, CA2, Exam, Total, Grade, Pos), etc.
            $headers = ['S/N', 'Student Name', 'Admission No'];
            foreach ($subjects as $sub) {
                foreach ($score_types as $st) {
                    $headers[] = $sub['subject_name'] . ' - ' . $st['label'];
                }
                $headers[] = $sub['subject_name'] . ' - Total';
                $headers[] = $sub['subject_name'] . ' - Grade';
                $headers[] = $sub['subject_name'] . ' - Pos';
            }
            $headers[] = 'Total Marks';
            $headers[] = 'Average (%)';
            $headers[] = 'Position';
            fputcsv($output, $headers);
            
            $sn = 1;
            foreach ($students as $student) {
                $row = [$sn, $student['full_name'], $student['admission_number']];
                $student_total = 0;
                $student_count = 0;
                foreach ($subjects as $sub) {
                    $score_data = $scores[$student['id']][$sub['id']] ?? null;
                    foreach ($score_types as $st) {
                        $val = $score_data['score_data'][$st['label']] ?? '';
                        $row[] = is_numeric($val) ? $val : '';
                    }
                    $total = $score_data['total_score'] ?? '';
                    $grade = $score_data['grade'] ?? '';
                    $pos = $score_data['subject_position'] ?? '';
                    $row[] = is_numeric($total) ? $total : '';
                    $row[] = $grade;
                    $row[] = $pos;
                    if (is_numeric($total)) {
                        $student_total += $total;
                        $student_count++;
                    }
                }
                $avg = $student_count > 0 ? round($student_total / $student_count, 1) : 0;
                $pos = $class_positions[$student['id']]['class_position'] ?? 0;
                $row[] = $student_total;
                $row[] = $avg;
                $row[] = ordinal($pos);
                fputcsv($output, $row);
                $sn++;
            }
        }
    } else {
        // Subject Broadsheet CSV
        $subject = null;
        foreach ($subjects as $sub) {
            if ($sub['id'] == $subject_id) {
                $subject = $sub;
                break;
            }
        }
        if ($subject) {
            $headers = ['S/N', 'Student Name', 'Admission No'];
            foreach ($score_types as $st) {
                $headers[] = $st['label'];
            }
            $headers[] = 'Total Score';
            $headers[] = 'Grade';
            $headers[] = 'Position';
            fputcsv($output, $headers);
            
            $sn = 1;
            // Sort students by total score for subject
            $sorted_students = $students;
            usort($sorted_students, function($a, $b) use ($scores, $subject_id) {
                $score_a = $scores[$a['id']][$subject_id]['total_score'] ?? 0;
                $score_b = $scores[$b['id']][$subject_id]['total_score'] ?? 0;
                return $score_b <=> $score_a;
            });
            
            foreach ($sorted_students as $student) {
                $score_data = $scores[$student['id']][$subject_id] ?? null;
                $row = [$sn, $student['full_name'], $student['admission_number']];
                foreach ($score_types as $st) {
                    $val = $score_data['score_data'][$st['label']] ?? '';
                    $row[] = is_numeric($val) ? $val : '';
                }
                $total = $score_data['total_score'] ?? '';
                $grade = $score_data['grade'] ?? '';
                $pos = $score_data['subject_position'] ?? '';
                $row[] = is_numeric($total) ? $total : '';
                $row[] = $grade;
                $row[] = is_numeric($pos) ? $pos : '';
                fputcsv($output, $row);
                $sn++;
            }
            
            // Add summary row
            fputcsv($output, []);
            fputcsv($output, ['Summary']);
            $class_avg = !empty($subject_scores) ? round(array_sum($subject_scores) / count($subject_scores), 1) : 0;
            fputcsv($output, ['Class Average', $class_avg]);
        }
    }
    
    fclose($output);
    exit();
}

$page_title = "Broadsheet - " . ucfirst($broadsheet_type) . " - " . $class;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school_name); ?> — <?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: <?php echo $primary_color; ?>;
            --secondary: <?php echo $secondary_color; ?>;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --shadow: 0 2px 8px rgba(0,0,0,.08);
            --radius: 10px;
        }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; color: #333; }
        
        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: linear-gradient(180deg, var(--primary), var(--dark)); color: white; padding: 20px 0; transition: transform .3s; z-index: 1000; transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }
        .mobile-toggle { position: fixed; top: 15px; left: 15px; z-index: 1001; width: 44px; height: 44px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 20px; cursor: pointer; }
        .overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 999; opacity: 0; visibility: hidden; transition: .25s; }
        .overlay.show { opacity: 1; visibility: visible; }
        .main { min-height: 100vh; padding: 20px; }
        
        @media (min-width: 768px) {
            .mobile-toggle, .overlay { display: none; }
            .sidebar { transform: translateX(0); }
            .main { margin-left: 260px; }
        }
        
        .top-header { background: white; border-radius: var(--radius); padding: 15px 20px; margin-bottom: 20px; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .top-header h1 { font-size: 1.3rem; color: var(--primary); }
        .top-header p { font-size: 0.75rem; color: #666; margin-top: 4px; }
        
        .control-bar { background: white; border-radius: var(--radius); padding: 15px 20px; margin-bottom: 20px; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .control-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .control-group label { font-size: 0.8rem; font-weight: 500; color: #555; }
        select, .btn { padding: 8px 16px; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 0.8rem; border: 1px solid #ddd; background: white; cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; border: none; }
        .btn-secondary { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-success { background: var(--success); color: white; border: none; }
        .btn-warning { background: #f39c12; color: white; border: none; }
        .btn-recalculate { background: #8e44ad; color: white; border: none; }
        .btn-recalculate:hover { background: #6c3483; }
        
        /* Broadsheet Table */
        .broadsheet-container { background: white; border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; }
        .broadsheet-title { text-align: center; margin-bottom: 20px; }
        .broadsheet-title h2 { color: var(--primary); font-size: 1.2rem; }
        .broadsheet-title p { color: #666; font-size: 0.8rem; }
        .broadsheet-stats { display: flex; justify-content: center; gap: 30px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { text-align: center; padding: 10px 20px; background: #f8f9fa; border-radius: 8px; }
        .stat-card .value { font-size: 1.3rem; font-weight: 700; color: var(--primary); }
        .stat-card .label { font-size: 0.7rem; color: #666; }

        /* Dual scroll wrapper */
        .scroll-outer { overflow: hidden; position: relative; }
        .scroll-top-bar, .scroll-bottom-bar { overflow-x: auto; overflow-y: hidden; }
        .scroll-top-bar { border-bottom: 1px solid #e0e0e0; margin-bottom: 4px; }
        .scroll-top-bar::-webkit-scrollbar,
        .scroll-bottom-bar::-webkit-scrollbar { height: 8px; }
        .scroll-top-bar::-webkit-scrollbar-thumb,
        .scroll-bottom-bar::-webkit-scrollbar-thumb { background: #b0bec5; border-radius: 4px; }
        .scroll-top-bar::-webkit-scrollbar-track,
        .scroll-bottom-bar::-webkit-scrollbar-track { background: #f1f1f1; }
        .scroll-phantom { height: 1px; /* will be set by JS to match table width */ }
        .scroll-content { overflow-x: auto; overflow-y: visible; }
        .scroll-content::-webkit-scrollbar { height: 8px; }
        .scroll-content::-webkit-scrollbar-thumb { background: #b0bec5; border-radius: 4px; }
        .scroll-content::-webkit-scrollbar-track { background: #f1f1f1; }
        
        table { width: 100%; border-collapse: collapse; font-size: 0.7rem; }
        th { background: var(--primary); color: white; padding: 10px 6px; text-align: center; font-weight: 600; border: 1px solid rgba(255,255,255,0.2); }
        td { padding: 8px 6px; text-align: center; border: 1px solid #e0e0e0; }
        td:first-child { position: sticky; left: 0; background: white; z-index: 1; }
        th:first-child { position: sticky; left: 0; background: var(--primary); z-index: 2; }
        tr:nth-child(even) td { background: #f9f9f9; }
        tr:nth-child(even) td:first-child { background: #f9f9f9; }
        .student-name { font-weight: 600; text-align: left; }
        .grade-A { background: #d4edda; color: #155724; font-weight: 600; }
        .grade-B { background: #cce5ff; color: #004085; font-weight: 600; }
        .grade-C { background: #fff3cd; color: #856404; font-weight: 600; }
        .grade-D { background: #fce4ec; color: #880e4f; font-weight: 600; }
        .grade-F { background: #f8d7da; color: #721c24; font-weight: 600; }
        
        /* Toast notification */
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 10000;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        }
        .toast-notification.error { background: #e74c3c; }
        .toast-notification.warning { background: #f39c12; }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .footer { text-align: center; padding: 20px; color: #999; font-size: 0.7rem; border-top: 1px solid var(--light); margin-top: 30px; }
        
        @media print {
            @page { size: A4 landscape; margin: 5mm; }
            .no-print, .sidebar, .mobile-toggle, .overlay, .top-header, .control-bar, button, .btn { display: none !important; }
            .main { margin: 0; padding: 0; }
            .broadsheet-container { box-shadow: none; padding: 0; }
        }
        /* Button Styles - Modern & Consistent */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 18px;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.btn i {
    font-size: 0.85rem;
}

/* Recalculate Button */
.btn-recalculate {
    background: linear-gradient(135deg, #8e44ad, #6c3483);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.btn-recalculate:hover {
    background: linear-gradient(135deg, #7d3c98, #5b2c6f);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(142, 68, 173, 0.3);
}

.btn-recalculate:active {
    transform: translateY(1px);
}

/* Print Button */
.btn-print {
    background: linear-gradient(135deg, #2c3e50, #1a252f);
    color: white;
}

.btn-print:hover {
    background: linear-gradient(135deg, #34495e, #2c3e50);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(44, 62, 80, 0.3);
}

.btn-print:active {
    transform: translateY(1px);
}

/* Export CSV Button */
.btn-csv {
    background: linear-gradient(135deg, #27ae60, #1e8449);
    color: white;
}

.btn-csv:hover {
    background: linear-gradient(135deg, #2ecc71, #27ae60);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
}

.btn-csv:active {
    transform: translateY(1px);
}

/* Export PDF Button */
.btn-pdf {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}

.btn-pdf:hover {
    background: linear-gradient(135deg, #ec7063, #e74c3c);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
}

.btn-pdf:active {
    transform: translateY(1px);
}

/* Disabled state for any button */
.btn:disabled,
.btn-recalculate:disabled,
.btn-print:disabled,
.btn-csv:disabled,
.btn-pdf:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Responsive adjustments for mobile */
@media (max-width: 768px) {
    .btn,
    .btn-recalculate,
    .btn-print,
    .btn-csv,
    .btn-pdf {
        padding: 6px 12px;
        font-size: 0.7rem;
    }
    
    .btn i,
    .btn-recalculate i,
    .btn-print i,
    .btn-csv i,
    .btn-pdf i {
        font-size: 0.75rem;
    }
}

/* Optional: Add a subtle pulse animation for the recalculate button when active */
.btn-recalculate:active {
    animation: pulse 0.3s ease;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(0.97); }
    100% { transform: scale(1); }
}
    </style>
</head>
<body>
    <div class="overlay" id="overlay"></div>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header" style="padding:0 20px 15px;">
            <div class="logo" style="display:flex;align-items:center;gap:10px;">
                <div class="logo-icon" style="width:40px;height:40px;background:var(--secondary);border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-graduation-cap"></i></div>
                <div><h3 style="font-size:0.9rem;"><?php echo htmlspecialchars($school_name); ?></h3><p style="font-size:0.7rem;">Admin Portal</p></div>
            </div>
        </div>
        <ul class="nav-links" style="list-style:none;padding:0 15px;">
            <li><a href="index.php" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-home"></i>Dashboard</a></li>
            <li><a href="exam_record_setup.php" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-file-alt"></i>Exam Records</a></li>
            <li><a href="exam_generate_cards.php?record_id=<?php echo $record_id; ?>" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-id-card"></i>Report Cards</a></li>
            <li><a href="exam_broadsheet.php?record_id=<?php echo $record_id; ?>" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;background:rgba(255,255,255,0.2);border-radius:8px;"><i class="fas fa-chart-line"></i>Broadsheet</a></li>
            <li><a href="logout.php" style="display:flex;gap:10px;padding:10px;color:white;text-decoration:none;"><i class="fas fa-sign-out-alt"></i>Logout</a></li>
        </ul>
    </nav>
    <button class="mobile-toggle" id="menuBtn"><i class="fas fa-bars"></i></button>

    <main class="main">
        <div class="top-header no-print">
            <div>
                <h1><i class="fas fa-chart-line"></i> Exam Broadsheet</h1>
                <p><?php echo htmlspecialchars($class); ?> · <?php echo htmlspecialchars($session); ?> · <?php echo htmlspecialchars($term); ?> Term</p>
            </div>
            <a href="exam_record_setup.php?class=<?php echo urlencode($class); ?>" class="btn-secondary" style="text-decoration:none;padding:8px 16px;border-radius:8px;">← Back</a>
        </div>

        <div class="control-bar no-print">
            <div class="control-group">
                <label>Broadsheet Type:</label>
                <select id="broadsheetType" onchange="changeType()">
                    <option value="class" <?php echo $broadsheet_type === 'class' ? 'selected' : ''; ?>>Class Broadsheet</option>
                    <option value="subject" <?php echo $broadsheet_type === 'subject' ? 'selected' : ''; ?>>Subject Broadsheet</option>
                </select>
                
                <div id="modeSelect" style="<?php echo $broadsheet_type === 'subject' ? 'display:none;' : ''; ?>">
                    <label>View Mode:</label>
                    <select id="viewMode" onchange="changeMode()">
                        <option value="minimal" <?php echo $view_mode === 'minimal' ? 'selected' : ''; ?>>Minimal (Totals Only)</option>
                        <option value="full" <?php echo $view_mode === 'full' ? 'selected' : ''; ?>>Full (All Scores)</option>
                    </select>
                </div>
                
                <div id="subjectSelect" style="<?php echo $broadsheet_type === 'class' ? 'display:none;' : ''; ?>">
                    <label>Subject:</label>
                    <select id="subjectId" onchange="changeSubject()">
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>" <?php echo $subject_id == $sub['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="control-group">
                <button class="btn-recalculate" id="recalculateBtn" onclick="recalculateScores()">
    <i class="fas fa-sync-alt"></i> Recalculate
</button>
<button class="btn-print" onclick="window.print()">
    <i class="fas fa-print"></i> Print
</button>
<button class="btn-csv" id="exportCsvBtn">
    <i class="fas fa-file-csv"></i> Export CSV
</button>
<button class="btn-pdf" onclick="downloadPDF()">
    <i class="fas fa-file-pdf"></i> Export PDF
</button>
            </div>
        </div>

        <div class="broadsheet-container" id="broadsheetContainer">
            <?php if ($broadsheet_type === 'class'): ?>
                <!-- CLASS BROADSHEET -->
                <div class="broadsheet-title">
                    <h2><?php echo strtoupper(htmlspecialchars($class)); ?> - CLASS PERFORMANCE BROADSHEET</h2>
                    <p><?php echo htmlspecialchars($term); ?> Term, <?php echo htmlspecialchars($session); ?></p>
                </div>
                
                <div class="broadsheet-stats">
                    <div class="stat-card"><div class="value"><?php echo count($students); ?></div><div class="label">Total Students</div></div>
                    <div class="stat-card"><div class="value"><?php echo count($subjects); ?></div><div class="label">Total Subjects</div></div>
                    <div class="stat-card"><div class="value"><?php echo number_format($class_average, 1); ?>%</div><div class="label">Class Average</div></div>
                    <div class="stat-card"><div class="value"><?php echo number_format($class_highest, 1); ?>%</div><div class="label">Highest Average</div></div>
                    <div class="stat-card"><div class="value"><?php echo number_format($class_lowest, 1); ?>%</div><div class="label">Lowest Average</div></div>
                </div>
                
                <div class="scroll-outer">
                    <div class="scroll-top-bar" id="scrollTop1"><div style="height:1px" id="phantom1"></div></div>
                    <div class="scroll-content" id="scrollContent1">
                    <table id="broadsheetTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Admission No</th>
                                <?php foreach ($subjects as $sub): ?>
                                    <th><?php echo htmlspecialchars($sub['subject_name']); ?></th>
                                <?php endforeach; ?>
                                <th>Total</th>
                                <th>Avg %</th>
                                <th>Pos</th>
                            </tr>
                            <?php if ($view_mode === 'full' && !empty($score_types)): ?>
                                <tr style="background:#eef2ff;">
                                    <th colspan="3"></th>
                                    <?php foreach ($subjects as $sub): ?>
                                        <th style="font-size:0.6rem; padding:4px;">
                                            <?php foreach ($score_types as $st): ?>
                                                <?php echo htmlspecialchars(substr($st['label'], 0, 6)); ?><br>
                                            <?php endforeach; ?>
                                            Ttl | Grd
                                        </th>
                                    <?php endforeach; ?>
                                    <th colspan="3"></th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            // Sort students by position
                            usort($students, function($a, $b) use ($class_positions) {
                                $pos_a = $class_positions[$a['id']]['class_position'] ?? 999;
                                $pos_b = $class_positions[$b['id']]['class_position'] ?? 999;
                                return $pos_a - $pos_b;
                            });
                            
                            foreach ($students as $student):
                                $sid = $student['id'];
                                $student_total = 0;
                                $student_count = 0;
                                $pos = $class_positions[$sid]['class_position'] ?? 0;
                            ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <?php foreach ($subjects as $sub):
                                        $subj_scores = $scores[$sid][$sub['id']] ?? null;
                                        $total = $subj_scores['total_score'] ?? '—';
                                        $grade = $subj_scores['grade'] ?? '—';
                                        $grade_class = '';
                                        if ($grade == 'A') $grade_class = 'grade-A';
                                        elseif ($grade == 'B') $grade_class = 'grade-B';
                                        elseif ($grade == 'C') $grade_class = 'grade-C';
                                        elseif ($grade == 'D') $grade_class = 'grade-D';
                                        elseif ($grade == 'F') $grade_class = 'grade-F';
                                        
                                        if (is_numeric($total)) {
                                            $student_total += $total;
                                            $student_count++;
                                        }
                                    ?>
                                        <td class="<?php echo $grade_class; ?>">
                                            <?php if ($view_mode === 'full' && !empty($score_types)): ?>
                                                <div style="font-size:0.65rem;">
                                                    <?php foreach ($score_types as $st):
                                                        $val = $subj_scores['score_data'][$st['label']] ?? '—';
                                                    ?>
                                                        <span style="display:inline-block; width:35px;"><?php echo is_numeric($val) ? $val : '—'; ?></span>
                                                    <?php endforeach; ?>
                                                    <strong><?php echo is_numeric($total) ? $total : '—'; ?></strong> | 
                                                    <span class="grade-badge"><?php echo $grade; ?></span>
                                                </div>
                                            <?php else: ?>
                                                <?php echo is_numeric($total) ? $total : '—'; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td><strong><?php echo $student_total; ?></strong></td>
                                    <td><strong><?php echo $student_count > 0 ? number_format($student_total / $student_count, 1) : 0; ?>%</strong></td>
                                    <td><strong><?php echo ordinal($pos); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><!-- scroll-content -->
                </div><!-- scroll-outer -->
                
            <?php else: ?>
                <!-- SUBJECT BROADSHEET -->
                <?php 
                $selected_subject = null;
                foreach ($subjects as $sub) {
                    if ($sub['id'] == $subject_id) {
                        $selected_subject = $sub;
                        break;
                    }
                }
                if (!$selected_subject && !empty($subjects)) {
                    $selected_subject = $subjects[0];
                    $subject_id = $selected_subject['id'];
                }
                
                // Calculate subject stats
                $subject_scores = [];
                foreach ($students as $student) {
                    $score = $scores[$student['id']][$subject_id]['total_score'] ?? 0;
                    if (is_numeric($score)) $subject_scores[] = $score;
                }
                $subj_avg = !empty($subject_scores) ? round(array_sum($subject_scores) / count($subject_scores), 1) : 0;
                $subj_highest = !empty($subject_scores) ? max($subject_scores) : 0;
                $subj_lowest = !empty($subject_scores) ? min($subject_scores) : 0;
                ?>
                
                <div class="broadsheet-title">
                    <h2><?php echo strtoupper(htmlspecialchars($selected_subject['subject_name'])); ?> - SUBJECT PERFORMANCE BROADSHEET</h2>
                    <p><?php echo htmlspecialchars($class); ?> · <?php echo htmlspecialchars($term); ?> Term, <?php echo htmlspecialchars($session); ?></p>
                </div>
                
                <div class="broadsheet-stats">
                    <div class="stat-card"><div class="value"><?php echo count($students); ?></div><div class="label">Total Students</div></div>
                    <div class="stat-card"><div class="value"><?php echo number_format($subj_avg, 1); ?>%</div><div class="label">Class Average</div></div>
                    <div class="stat-card"><div class="value"><?php echo number_format($subj_highest, 1); ?></div><div class="label">Highest Score</div></div>
                    <div class="stat-card"><div class="value"><?php echo number_format($subj_lowest, 1); ?></div><div class="label">Lowest Score</div></div>
                </div>
                
                <div class="scroll-outer">
                    <div class="scroll-top-bar" id="scrollTop2"><div style="height:1px" id="phantom2"></div></div>
                    <div class="scroll-content" id="scrollContent2">
                    <table id="broadsheetTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Admission No</th>
                                <?php foreach ($score_types as $st): ?>
                                    <th><?php echo htmlspecialchars($st['label']); ?></th>
                                <?php endforeach; ?>
                                <th>Total Score</th>
                                <th>Grade</th>
                                <th>Position</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            // Sort students by total score for this subject
                            usort($students, function($a, $b) use ($scores, $subject_id) {
                                $score_a = $scores[$a['id']][$subject_id]['total_score'] ?? 0;
                                $score_b = $scores[$b['id']][$subject_id]['total_score'] ?? 0;
                                return $score_b <=> $score_a;
                            });
                            
                            foreach ($students as $student):
                                $sid = $student['id'];
                                $score_data = $scores[$sid][$subject_id] ?? null;
                                $total = $score_data['total_score'] ?? '—';
                                $grade = $score_data['grade'] ?? '—';
                                $pos = $score_data['subject_position'] ?? '—';
                                $grade_class = '';
                                if ($grade == 'A') $grade_class = 'grade-A';
                                elseif ($grade == 'B') $grade_class = 'grade-B';
                                elseif ($grade == 'C') $grade_class = 'grade-C';
                                elseif ($grade == 'D') $grade_class = 'grade-D';
                                elseif ($grade == 'F') $grade_class = 'grade-F';
                            ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <?php foreach ($score_types as $st):
                                        $val = $score_data['score_data'][$st['label']] ?? '—';
                                    ?>
                                        <td><?php echo is_numeric($val) ? $val : '—'; ?></td>
                                    <?php endforeach; ?>
                                    <td class="<?php echo $grade_class; ?>"><strong><?php echo is_numeric($total) ? $total : '—'; ?></strong></td>
                                    <td class="<?php echo $grade_class; ?>"><?php echo $grade; ?></td>
                                    <td><?php echo ordinal($pos); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><!-- scroll-content -->
                </div><!-- scroll-outer -->
            <?php endif; ?>
        </div>
        
        <div class="footer">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> — Generated on <?php echo date('d M Y, h:i A'); ?>
        </div>
    </main>

    <script>
        const sb = document.getElementById('sidebar');
        const ov = document.getElementById('overlay');
        const btn = document.getElementById('menuBtn');
        if (btn) {
            btn.addEventListener('click', () => { sb.classList.toggle('open'); ov.classList.toggle('show'); });
        }
        if (ov) {
            ov.addEventListener('click', () => { sb.classList.remove('open'); ov.classList.remove('show'); });
        }
        
        const recordId = <?php echo $record_id; ?>;
        
        function changeType() {
            const type = document.getElementById('broadsheetType').value;
            const modeSelect = document.getElementById('modeSelect');
            const subjectSelect = document.getElementById('subjectSelect');
            
            if (type === 'class') {
                modeSelect.style.display = 'flex';
                subjectSelect.style.display = 'none';
                window.location.href = `exam_broadsheet.php?record_id=${recordId}&type=class&mode=${document.getElementById('viewMode').value}`;
            } else {
                modeSelect.style.display = 'none';
                subjectSelect.style.display = 'flex';
                window.location.href = `exam_broadsheet.php?record_id=${recordId}&type=subject&subject_id=${document.getElementById('subjectId').value}`;
            }
        }
        
        function changeMode() {
            const mode = document.getElementById('viewMode').value;
            window.location.href = `exam_broadsheet.php?record_id=${recordId}&type=class&mode=${mode}`;
        }
        
        function changeSubject() {
            const subjectId = document.getElementById('subjectId').value;
            window.location.href = `exam_broadsheet.php?record_id=${recordId}&type=subject&subject_id=${subjectId}`;
        }
        
        document.getElementById('exportCsvBtn')?.addEventListener('click', function() {
            const type = document.getElementById('broadsheetType').value;
            let url = `exam_broadsheet.php?record_id=${recordId}&type=${type}&format=csv`;
            if (type === 'class') {
                url += `&mode=${document.getElementById('viewMode').value}`;
            } else {
                url += `&subject_id=${document.getElementById('subjectId').value}`;
            }
            window.location.href = url;
        });
        
        async function downloadPDF() {
            const table = document.getElementById('broadsheetTable');
            if (!table) { alert('No table to export'); return; }

            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...'; }

            try {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

                const titleEl = document.querySelector('.broadsheet-title h2');
                const subtitleEl = document.querySelector('.broadsheet-title p');
                const titleText = titleEl ? titleEl.innerText : 'Broadsheet';
                const subtitleText = subtitleEl ? subtitleEl.innerText : '';

                doc.setFontSize(13);
                doc.setFont('helvetica', 'bold');
                doc.text(titleText, doc.internal.pageSize.getWidth() / 2, 14, { align: 'center' });
                doc.setFontSize(9);
                doc.setFont('helvetica', 'normal');
                doc.text(subtitleText, doc.internal.pageSize.getWidth() / 2, 20, { align: 'center' });

                // Extract header rows
                const headData = [];
                table.querySelectorAll('thead tr').forEach(row => {
                    const rowData = [];
                    row.querySelectorAll('th').forEach(th => rowData.push(th.innerText.trim().replace(/\n/g, ' ')));
                    headData.push(rowData);
                });

                // Extract body rows
                const bodyData = [];
                table.querySelectorAll('tbody tr').forEach(row => {
                    const rowData = [];
                    row.querySelectorAll('td').forEach(td => rowData.push(td.innerText.trim().replace(/\n/g, ' ')));
                    bodyData.push(rowData);
                });

                doc.autoTable({
                    head: headData.length ? headData : [[]],
                    body: bodyData,
                    startY: 25,
                    styles: { fontSize: 6.5, cellPadding: 1.8, overflow: 'linebreak', valign: 'middle', halign: 'center' },
                    headStyles: { fillColor: [41, 128, 185], textColor: 255, fontStyle: 'bold', fontSize: 7 },
                    columnStyles: { 0: { cellWidth: 8 }, 1: { halign: 'left', cellWidth: 32 }, 2: { cellWidth: 18 } },
                    alternateRowStyles: { fillColor: [245, 247, 250] },
                    margin: { top: 25, left: 5, right: 5 },
                    theme: 'grid',
                    didDrawPage: function(data) {
                        const totalPages = doc.internal.getNumberOfPages();
                        doc.setFontSize(7);
                        doc.setFont('helvetica', 'normal');
                        doc.text('Page ' + data.pageNumber + ' of ' + totalPages,
                            doc.internal.pageSize.getWidth() - 15,
                            doc.internal.pageSize.getHeight() - 4);
                        doc.text('Generated: ' + new Date().toLocaleDateString(), 10, doc.internal.pageSize.getHeight() - 4);
                    }
                });

                doc.save('broadsheet_<?php echo preg_replace('/[^A-Za-z0-9_]/', '_', $class); ?>_<?php echo preg_replace('/[^A-Za-z0-9_]/', '_', $term); ?>_<?php echo preg_replace('/[^A-Za-z0-9_]/', '_', $session); ?>.pdf');

            } catch (err) {
                console.error('PDF error:', err);
                alert('PDF generation failed: ' + err.message);
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-pdf"></i> Export PDF'; }
            }
        }
        
        // Recalculate function
        async function recalculateScores() {
            const recalcBtn = document.getElementById('recalculateBtn');
            
            if (!confirm('⚠️ WARNING: This will recalculate:\n\n• All total scores and percentages\n• All subject grades (A, B, C, etc.)\n• Subject positions (rankings within each subject)\n• Class positions (overall rankings)\n\nThis action cannot be undone. Continue?')) {
                return;
            }
            
            // Show loading state
            const originalText = recalcBtn.innerHTML;
            recalcBtn.disabled = true;
            recalcBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recalculating...';
            
            try {
                const response = await fetch('exam_recalculate.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `record_id=${recordId}&recalc_action=all`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show success message with stats
                    let message = data.message + '\n\n';
                    message += `📊 Class: ${data.class}\n`;
                    message += `📚 Students: ${data.students_count}\n`;
                    message += `📖 Subjects: ${data.subjects_count}\n\n`;
                    message += `✅ Statistics:\n`;
                    message += `• Scores updated: ${data.stats.scores_updated}\n`;
                    message += `• Grades updated: ${data.stats.grades_updated}\n`;
                    message += `• Subject positions updated: ${data.stats.subject_positions_updated}\n`;
                    message += `• Class positions updated: ${data.stats.class_positions_updated}\n\n`;
                    message += `The page will now reload to show updated data.`;
                    
                    alert(message);
                    
                    // Reload the page to show updated data
                    window.location.reload();
                } else {
                    alert('❌ Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Failed to recalculate. Please try again.\nError: ' + error.message);
            } finally {
                recalcBtn.disabled = false;
                recalcBtn.innerHTML = originalText;
            }
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = 'toast-notification' + (type === 'error' ? ' error' : type === 'warning' ? ' warning' : '');
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
    </script>
</body>
</html>
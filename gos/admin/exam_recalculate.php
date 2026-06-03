<?php
// gsa/admin/exam_recalculate.php - Recalculate Scores, Positions, and Grades
// AJAX endpoint - No HTML output, only JSON

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

if (isset($_SESSION['admin_id'])) {
    $admin_id   = $_SESSION['admin_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id   = $_SESSION['user_id'];
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

// Only admin roles may access this page
if (!in_array($admin_role, ['super_admin', 'admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$school_id = SCHOOL_ID;

// ── Get parameters ────────────────────────────────────────────────────────────
$record_id = isset($_POST['record_id']) ? (int)$_POST['record_id'] : 0;
$action = isset($_POST['recalc_action']) ? $_POST['recalc_action'] : 'all';

if (!$record_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Record ID is required']);
    exit();
}

// ── Load exam record ──────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
    $stmt->execute([$record_id, $school_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to load record: ' . $e->getMessage()]);
    exit();
}

if (!$record) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    exit();
}

$class = $record['class'];
$session = $record['session'];
$term = $record['term'];

// ── Get class_id ──────────────────────────────────────────────────────────────
$class_id = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class, $school_id]);
    $class_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $class_id = $class_row ? (int)$class_row['id'] : 0;
} catch (Exception $e) {
    error_log("Failed to get class_id: " . $e->getMessage());
}

// ── Decode grading scale ──────────────────────────────────────────────────────
$decoded = json_decode($record['score_types'] ?? '{}', true);
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

// ── Helper function to get grade info ─────────────────────────────────────────
function getGradeInfoRecalc($total, $scale) {
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max']) {
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
        }
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// ── Load all students in the class ────────────────────────────────────────────
$students = [];
try {
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? AND status = 'active' ORDER BY full_name ASC");
        $stmt->execute([$school_id, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class = ? AND status = 'active' ORDER BY full_name ASC");
        $stmt->execute([$school_id, $class]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Failed to load students: " . $e->getMessage());
}

$total_students = count($students);
$student_ids = array_column($students, 'id');

// ── Load all subjects for the class ───────────────────────────────────────────
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
} catch (Exception $e) {
    error_log("Failed to load subjects: " . $e->getMessage());
}

if (empty($subjects)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No subjects found for this class']);
    exit();
}

$subject_ids = array_column($subjects, 'id');

// ── Load all scores ───────────────────────────────────────────────────────────
$scores = [];
try {
    $ph = implode(',', array_fill(0, count($subject_ids), '?'));
    $stmt = $pdo->prepare("SELECT student_id, subject_id, score_data, total_score FROM student_scores WHERE school_id = ? AND session = ? AND term = ? AND subject_id IN ($ph)");
    $stmt->execute(array_merge([$school_id, $session, $term], $subject_ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
        $scores[(int)$row['student_id']][(int)$row['subject_id']] = $row;
    }
} catch (Exception $e) {
    error_log("Failed to load scores: " . $e->getMessage());
}

$stats = [
    'subjects_processed' => 0,
    'students_processed' => 0,
    'scores_updated' => 0,
    'grades_updated' => 0,
    'subject_positions_updated' => 0,
    'class_positions_updated' => 0
];

// ──────────────────────────────────────────────────────────────────────────────
// STEP 1: RECALCULATE TOTAL SCORES, PERCENTAGES, AND GRADES
// ──────────────────────────────────────────────────────────────────────────────
if ($action === 'all' || $action === 'scores') {
    
    foreach ($students as $student) {
        $student_id = (int)$student['id'];
        
        if (!isset($scores[$student_id])) continue;
        
        foreach ($scores[$student_id] as $subject_id => $score_data) {
            // Calculate total from individual score components
            $total = 0;
            $score_components = $score_data['score_data'];
            
            foreach ($score_components as $component => $value) {
                if (is_numeric($value)) {
                    $total += (float)$value;
                }
            }
            
            // Calculate percentage based on max_score from record
            $max_score = (int)($record['max_score'] ?? 100);
            $percentage = $max_score > 0 ? round(($total / $max_score) * 100, 2) : 0;
            
            // Get grade based on percentage (using total_score percentage)
            $grade_info = getGradeInfoRecalc($percentage, $grading_scale);
            
            // Update the database
            $updateStmt = $pdo->prepare("
                UPDATE student_scores 
                SET total_score = ?, percentage = ?, grade = ? 
                WHERE school_id = ? AND student_id = ? AND subject_id = ? AND session = ? AND term = ?
            ");
            $updateStmt->execute([
                $total, $percentage, $grade_info['grade'],
                $school_id, $student_id, $subject_id, $session, $term
            ]);
            
            $stats['scores_updated']++;
            $stats['grades_updated']++;
        }
        $stats['students_processed']++;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 2: RECALCULATE SUBJECT POSITIONS (per subject, within class)
// ──────────────────────────────────────────────────────────────────────────────
if ($action === 'all' || $action === 'positions') {
    
    foreach ($subject_ids as $subject_id) {
        // Get all students' total scores for this subject, ordered by total_score DESC
        $subjectScores = [];
        
        // Also handle sequential positions option
        $sequential_positions = (int)($record['sequential_positions'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT ss.student_id, ss.total_score, ss.id as score_id
            FROM student_scores ss
            WHERE ss.school_id = ? AND ss.subject_id = ? AND ss.session = ? AND ss.term = ?
            ORDER BY ss.total_score DESC
        ");
        $stmt->execute([$school_id, $subject_id, $session, $term]);
        $ranked = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $position = 1;
        $prev_score = null;
        $prev_position = 1;
        
        foreach ($ranked as $idx => $row) {
            $current_score = (float)$row['total_score'];
            
            if ($sequential_positions) {
                // Sequential: 1st, 2nd, 3rd, 4th... regardless of ties
                $display_position = $idx + 1;
            } else {
                // Standard: handle ties (same score = same position)
                if ($prev_score !== null && $current_score < $prev_score) {
                    $position = $idx + 1;
                } elseif ($prev_score !== null && $current_score == $prev_score) {
                    // Same position for tie
                    $display_position = $prev_position;
                    $position = $prev_position;
                } else {
                    $display_position = $position;
                }
                $prev_position = $position;
            }
            
            $display_position = $position;
            
            // Update student_scores table
            $updateStmt = $pdo->prepare("UPDATE student_scores SET subject_position = ? WHERE id = ?");
            $updateStmt->execute([$display_position, $row['score_id']]);
            
            // Update student_subject_positions table
            $checkStmt = $pdo->prepare("
                SELECT id FROM student_subject_positions 
                WHERE school_id = ? AND student_id = ? AND subject_id = ? AND session = ? AND term = ?
            ");
            $checkStmt->execute([$school_id, $row['student_id'], $subject_id, $session, $term]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exists) {
                $updatePosStmt = $pdo->prepare("UPDATE student_subject_positions SET subject_position = ?, updated_at = NOW() WHERE id = ?");
                $updatePosStmt->execute([$display_position, $exists['id']]);
            } else {
                $insertPosStmt = $pdo->prepare("
                    INSERT INTO student_subject_positions (school_id, student_id, subject_id, session, term, subject_position, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $insertPosStmt->execute([$school_id, $row['student_id'], $subject_id, $session, $term, $display_position]);
            }
            
            $stats['subject_positions_updated']++;
            $prev_score = $current_score;
        }
        $stats['subjects_processed']++;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 3: RECALCULATE CLASS POSITIONS AND AVERAGES
// ──────────────────────────────────────────────────────────────────────────────
if ($action === 'all' || $action === 'class_positions') {
    
    // First, calculate each student's average across all subjects
    $student_averages = [];
    $student_totals = [];
    $student_subject_counts = [];
    
    foreach ($students as $student) {
        $student_id = (int)$student['id'];
        $total_score_sum = 0;
        $subject_count = 0;
        
        if (isset($scores[$student_id])) {
            foreach ($scores[$student_id] as $subject_id => $score_data) {
                $total_score_sum += (float)($score_data['total_score'] ?? 0);
                $subject_count++;
            }
        }
        
        $average = $subject_count > 0 ? round(($total_score_sum / $subject_count), 2) : 0;
        $student_averages[$student_id] = $average;
        $student_totals[$student_id] = $total_score_sum;
        $student_subject_counts[$student_id] = $subject_count;
    }
    
    // Sort by average to determine class positions
    $sequential_positions = (int)($record['sequential_positions'] ?? 0);
    $position = 1;
    $prev_avg = null;
    $prev_position = 1;
    
    // Sort students by average DESC
    arsort($student_averages);
    $ranked_students = [];
    $idx = 0;
    
    foreach ($student_averages as $student_id => $avg) {
        if ($sequential_positions) {
            $display_position = $idx + 1;
            $position = $display_position;
        } else {
            if ($prev_avg !== null && $avg < $prev_avg) {
                $position = $idx + 1;
            } elseif ($prev_avg !== null && $avg == $prev_avg) {
                $display_position = $prev_position;
                $position = $prev_position;
            } else {
                $display_position = $position;
            }
            $prev_position = $position;
        }
        
        $display_position = $position;
        $ranked_students[$student_id] = [
            'position' => $display_position,
            'average' => $avg,
            'total_marks' => $student_totals[$student_id],
            'subject_count' => $student_subject_counts[$student_id]
        ];
        
        $prev_avg = $avg;
        $idx++;
    }
    
    // Update or insert into student_positions table
    foreach ($ranked_students as $student_id => $data) {
        $checkStmt = $pdo->prepare("
            SELECT id FROM student_positions 
            WHERE school_id = ? AND student_id = ? AND session = ? AND term = ?
        ");
        $checkStmt->execute([$school_id, $student_id, $session, $term]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exists) {
            $updateStmt = $pdo->prepare("
                UPDATE student_positions 
                SET class_position = ?, average = ?, total_marks = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$data['position'], $data['average'], $data['total_marks'], $exists['id']]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO student_positions (school_id, student_id, session, term, class_position, average, total_marks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([$school_id, $student_id, $session, $term, $data['position'], $data['average'], $data['total_marks']]);
        }
        
        $stats['class_positions_updated']++;
    }
}

// ── Update the record status if needed ────────────────────────────────────────
if (($record['status'] ?? 'draft') === 'draft') {
    $pdo->prepare("UPDATE report_card_settings SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$record_id]);
}

// ── Log the activity ──────────────────────────────────────────────────────────
try {
    $logStmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, user_type, activity, school_id) 
        VALUES (?, 'admin', ?, ?)
    ");
    $logStmt->execute([$admin_id, "Recalculated scores and positions for {$class} - {$term} Term {$session}", $school_id]);
} catch (Exception $e) { /* non-fatal */ }

// ── Return success response ───────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Recalculation completed successfully!',
    'stats' => $stats,
    'record_id' => $record_id
]);
exit();
?>
<?php
// gos/staff/staff_score_entry.php - Staff Score Entry (Only assigned subjects)
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /gos/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id       = SCHOOL_ID;
$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$staff_id        = $_SESSION['user_id'];
$staff_name      = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role      = $_SESSION['staff_role'] ?? 'staff';

// Get staff_id string from staff table
$stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
$stmt->execute([$staff_id, $school_id]);
$staff_id_string = $stmt->fetchColumn();

if (!$staff_id_string) {
    die("Staff record not found. Please contact administrator.");
}

// ── Require a valid record_id ─────────────────────────────────────────────────
$record_id = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;

// ============================================
// HANDLE POST SAVE SCORES (MOVED TO TOP)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores'])) {
    $post_subject_id = (int)($_POST['subject_id'] ?? 0);
    $scores_post = $_POST['scores'] ?? [];

    // First, load the exam record to get grading scale and score types
    try {
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
        $stmt->execute([$record_id, $school_id]);
        $record_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $record_data = null;
    }

    if (!$record_data) {
        $_SESSION['flash_error'] = "Exam record not found.";
        header("Location: staff_score_entry.php?record_id={$record_id}");
        exit();
    }

    // Decode score types & grading
    $decoded = json_decode($record_data['score_types'] ?? '{}', true);
    $score_types = $decoded['score_types'] ?? (is_array($decoded) && isset($decoded[0]['label']) ? $decoded : []);
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

    $class_name = $record_data['class'];
    $class_id_record = $record_data['class_id'] ?? 0;  // Use class_id from record if available
    $session = $record_data['session'];
    $term = $record_data['term'];

    // Get class_id from class name if not already present
    $class_id = $class_id_record;
    if ($class_id == 0) {
        $stmt = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? AND school_id = ?");
        $stmt->execute([$class_name, $school_id]);
        $class_row = $stmt->fetch();
        $class_id = $class_row ? $class_row['id'] : 0;
    }

    // Get subject name
    $subj_name_val = '';
    try {
        $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
        $stmt->execute([$post_subject_id]);
        $subj = $stmt->fetch();
        $subj_name_val = $subj ? $subj['subject_name'] : '';
    } catch (Exception $e) {
        $subj_name_val = '';
    }

    // Helper function for grade
    function getGradeInfoStaff($total, $scale)
    {
        foreach ($scale as $row) {
            if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
                return ['grade' => $row['grade'], 'remark' => $row['remark']];
        }
        return ['grade' => 'F', 'remark' => 'Fail'];
    }

    // Get students in this class using class_id
    $students = [];
    if ($class_id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM students 
                WHERE school_id = ? AND class_id = ? AND status = 'active'
            ");
            $stmt->execute([$school_id, $class_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $students = [];
        }
    }

    if (empty($students)) {
        $_SESSION['flash_error'] = "No students found in this class.";
        header("Location: staff_score_entry.php?record_id={$record_id}&subject_id={$post_subject_id}");
        exit();
    }

    $pdo->beginTransaction();
    try {
        foreach ($students as $stu) {
            $sid = (int)$stu['id'];
            $raw = $scores_post[$sid] ?? [];
            $sdata = [];
            $total = 0.0;
            $hasAny = false;

            foreach ($score_types as $st) {
                $label = $st['label'];
                $maxVal = (float)($st['max'] ?? 0);
                if (isset($raw[$label]) && trim((string)$raw[$label]) !== '') {
                    $val = min((float)$raw[$label], $maxVal);
                    $val = max(0, $val);
                    $total += $val;
                    $hasAny = true;
                    $sdata[$label] = $val;
                } else {
                    $sdata[$label] = null;
                }
            }

            if (!$hasAny) continue;

            $graded = getGradeInfoStaff($total, $grading_scale);
            $max_score = (int)($record_data['max_score'] ?? 100);
            $pct = $max_score > 0 ? round(($total / $max_score) * 100, 2) : 0;

            // Check if score already exists
            $chk = $pdo->prepare("
                SELECT id FROM student_scores
                WHERE school_id=? AND student_id=? AND subject_id=? AND session=? AND term=?
                LIMIT 1
            ");
            $chk->execute([$school_id, $sid, $post_subject_id, $session, $term]);
            $eid = $chk->fetchColumn();

            if ($eid) {
                $stmt = $pdo->prepare("
                    UPDATE student_scores
                    SET score_data=?, total_score=?, percentage=?, grade=?, subject_name=?
                    WHERE id=?
                ");
                $stmt->execute([json_encode($sdata), $total, $pct, $graded['grade'], $subj_name_val, $eid]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO student_scores
                        (school_id, student_id, subject_id, subject_name, session, term, score_data, total_score, percentage, grade)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([$school_id, $sid, $post_subject_id, $subj_name_val, $session, $term, json_encode($sdata), $total, $pct, $graded['grade']]);
            }
        }

        // Recalculate subject positions
        $stmt = $pdo->prepare("
            SELECT id, student_id, total_score FROM student_scores
            WHERE school_id=? AND subject_id=? AND session=? AND term=?
            ORDER BY total_score DESC
        ");
        $stmt->execute([$school_id, $post_subject_id, $session, $term]);
        $ranked = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pos = 1;
        foreach ($ranked as $r) {
            $pdo->prepare("UPDATE student_scores SET subject_position=? WHERE id=?")->execute([$pos, $r['id']]);

            // Update student_subject_positions
            $chk2 = $pdo->prepare("
                SELECT id FROM student_subject_positions
                WHERE school_id=? AND student_id=? AND subject_id=? AND session=? AND term=? LIMIT 1
            ");
            $chk2->execute([$school_id, $r['student_id'], $post_subject_id, $session, $term]);
            $spid = $chk2->fetchColumn();
            if ($spid) {
                $pdo->prepare("UPDATE student_subject_positions SET subject_position=?, updated_at=NOW() WHERE id=?")
                    ->execute([$pos, $spid]);
            } else {
                $pdo->prepare("
                    INSERT INTO student_subject_positions
                        (school_id, student_id, subject_id, session, term, subject_position, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,NOW(),NOW())
                ")->execute([$school_id, $r['student_id'], $post_subject_id, $session, $term, $pos]);
            }
            $pos++;
        }

        $pdo->commit();
        $_SESSION['flash_success'] = "Scores saved for {$subj_name_val}. Subject positions recalculated.";
        header("Location: staff_score_entry.php?record_id={$record_id}&subject_id={$post_subject_id}");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("staff_score_entry save error: " . $e->getMessage());
        $_SESSION['flash_error'] = "Error saving scores: " . $e->getMessage();
        header("Location: staff_score_entry.php?record_id={$record_id}&subject_id={$post_subject_id}");
        exit();
    }
}

// If no record_id, show list of available exam records for staff
if ($record_id === 0) {
    // Get all active exam records for classes the staff is assigned to
    try {
        // Get staff's assigned classes (with class_id now)
        $stmt = $pdo->prepare("
            SELECT sc.class_id, c.class_name 
            FROM staff_classes sc
            JOIN classes c ON sc.class_id = c.id
            WHERE sc.staff_id = ? AND sc.school_id = ?
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $assigned_class_ids = array_column($assigned_classes, 'class_id');

        if (!empty($assigned_class_ids)) {
            $placeholders = str_repeat('?,', count($assigned_class_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT rcs.id, rcs.record_name, rcs.class, rcs.session, rcs.term, rcs.status, c.class_name as display_class_name
                FROM report_card_settings rcs
                JOIN classes c ON rcs.class_id = c.id
                WHERE rcs.school_id = ? 
                AND rcs.class_id IN ($placeholders)
                AND rcs.status != 'archived'
                ORDER BY rcs.created_at DESC
            ");
            $stmt->execute(array_merge([$school_id], $assigned_class_ids));
            $available_records = $stmt->fetchAll();
        } else {
            $available_records = [];
        }
    } catch (Exception $e) {
        error_log("staff_score_entry records fetch: " . $e->getMessage());
        $available_records = [];
    }

    // Display the list page
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($school_name); ?> - Select Exam Record</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --primary-color: <?php echo $primary_color; ?>;
                --sidebar-width: 280px;
                --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
                --radius-md: 12px;
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

            .main-content {
                margin-left: 0;
                padding: 20px;
                min-height: 100vh;
                transition: margin-left 0.28s ease;
            }

            .top-header {
                background: white;
                padding: 20px 25px;
                border-radius: var(--radius-md);
                margin-bottom: 25px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
                box-shadow: var(--shadow-sm);
            }

            .header-title h1 {
                color: var(--primary-color);
                font-size: 1.4rem;
                font-weight: 700;
            }

            .card {
                background: white;
                border-radius: var(--radius-md);
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: var(--shadow-sm);
            }

            .card-header {
                padding-bottom: 12px;
                margin-bottom: 15px;
                border-bottom: 2px solid #e0e0e0;
            }

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
                background: #f5f5f5;
                font-weight: 600;
            }

            .btn {
                padding: 8px 16px;
                border-radius: 8px;
                border: none;
                cursor: pointer;
                font-weight: 500;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-size: 0.8rem;
            }

            .btn-primary {
                background: var(--primary-color);
                color: white;
            }

            @media (min-width: 768px) {
                .main-content {
                    margin-left: var(--sidebar-width);
                }
            }

            @media (max-width: 767px) {
                .main-content {
                    padding-top: 70px;
                }

                .data-table {
                    font-size: 12px;
                }
            }
        </style>
    </head>

    <body>
        <button class="mobile-menu-btn" id="mobileMenuBtn" style="position:fixed;top:16px;left:16px;z-index:1001;width:44px;height:44px;background:var(--primary-color);color:white;border:none;border-radius:10px;font-size:20px;cursor:pointer;">
            <i class="fas fa-bars"></i>
        </button>
        <?php include_once 'includes/staff_sidebar.php'; ?>
        <div class="main-content">
            <div class="top-header">
                <div class="header-title">
                    <h1><i class="fas fa-pencil-alt"></i> Enter Scores</h1>
                    <p>Select an exam record to enter scores</p>
                </div>
                <div>
                    <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Available Exam Records</h3>
                </div>
                <?php if (empty($available_records)): ?>
                    <div style="text-align:center; padding:50px;">
                        <i class="fas fa-folder-open" style="font-size:48px; opacity:0.3;"></i>
                        <p>No exam records available for your classes.</p>
                        <p style="margin-top:10px;">Please contact the administrator to create exam records.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Exam Record</th>
                                <th>Class</th>
                                <th>Session/Term</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_records as $record): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($record['record_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($record['display_class_name'] ?? $record['class']); ?></td>
                                    <td><?php echo htmlspecialchars($record['session']); ?> - <?php echo htmlspecialchars($record['term']); ?> Term</td>
                                    <td>
                                        <span class="status-badge" style="padding:3px 10px;border-radius:20px;font-size:0.7rem;background:<?php echo $record['status'] === 'active' ? '#d4edda' : '#fef5e7'; ?>;color:<?php echo $record['status'] === 'active' ? '#155724' : '#856404'; ?>;">
                                            <?php echo ucfirst($record['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="staff_score_entry.php?record_id=<?php echo $record['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-pencil-alt"></i> Enter Scores
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <script>
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('staffSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (mobileBtn) {
                mobileBtn.onclick = () => {
                    sidebar.classList.toggle('active');
                    if (overlay) overlay.classList.toggle('active');
                };
            }
            if (overlay) {
                overlay.onclick = () => {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                };
            }
        </script>
    </body>

    </html>
<?php
    exit();
}

// ── Load the exam record (for the score entry page) ──────────────────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE id = ? AND school_id = ?");
    $stmt->execute([$record_id, $school_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("staff_score_entry load: " . $e->getMessage());
    $record = null;
}

if (!$record || ($record['status'] ?? 'draft') === 'archived') {
    header("Location: staff_score_entry.php");
    exit();
}

// Get class_id from record (use class_id if available, otherwise look up by class name)
$class_id_from_record = $record['class_id'] ?? 0;
$class_name = $record['class'];

if ($class_id_from_record > 0) {
    $class_id = $class_id_from_record;
    // Get class name for display
    $stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $class_row = $stmt->fetch();
    $display_class_name = $class_row ? $class_row['class_name'] : $class_name;
} else {
    // Fallback: look up class_id from class name
    $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE class_name = ? AND school_id = ?");
    $stmt->execute([$class_name, $school_id]);
    $class_row = $stmt->fetch();
    $class_id = $class_row ? $class_row['id'] : 0;
    $display_class_name = $class_row ? $class_row['class_name'] : $class_name;
}

// Verify staff has access to this class (using class_id)
$stmt = $pdo->prepare("
    SELECT sc.class_id FROM staff_classes sc
    WHERE sc.staff_id = ? AND sc.school_id = ? AND sc.class_id = ?
");
$stmt->execute([$staff_id_string, $school_id, $class_id]);
if (!$stmt->fetch()) {
    header("Location: staff_score_entry.php");
    exit();
}

// ── Decode score types & grading ──────────────────────────────────────────────
$decoded      = json_decode($record['score_types'] ?? '{}', true);
$score_types  = $decoded['score_types']   ?? (is_array($decoded) && isset($decoded[0]['label']) ? $decoded : []);
$grading_scale = $decoded['grading_scale'] ?? [];

if (empty($grading_scale)) {
    $grading_scale = [
        ['grade' => 'A', 'min' => 75, 'max' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B', 'min' => 65, 'max' => 74,  'remark' => 'Very Good'],
        ['grade' => 'C', 'min' => 50, 'max' => 64,  'remark' => 'Good'],
        ['grade' => 'D', 'min' => 40, 'max' => 49,  'remark' => 'Pass'],
        ['grade' => 'F', 'min' => 0,  'max' => 39,  'remark' => 'Fail'],
    ];
}

$session = $record['session'];
$term    = $record['term'];

// ── Get subjects assigned to this staff for this class (using class_id) ────────
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.subject_name
        FROM subjects s
        JOIN staff_subjects ss ON ss.subject_id = s.id AND ss.school_id = ?
        JOIN subject_classes sc ON sc.subject_id = s.id AND sc.school_id = ? AND sc.class_id = ?
        WHERE ss.staff_id = ? AND (s.school_id = ? OR s.is_central = 1)
        ORDER BY s.subject_name ASC
    ");
    $stmt->execute([$school_id, $school_id, $class_id, $staff_id_string, $school_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("staff_score_entry subjects: " . $e->getMessage());
}

// ── Active subject from GET ───────────────────────────────────────────────────
$active_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($active_subject_id === 0 && !empty($subjects)) {
    $active_subject_id = (int)$subjects[0]['id'];
}

// ── Load students USING CLASS_ID ─────────────────────────────────────────────
$students = [];
if ($class_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number, gender
            FROM students
            WHERE school_id = ? AND class_id = ? AND status = 'active'
            ORDER BY full_name ASC
        ");
        $stmt->execute([$school_id, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("staff_score_entry students: " . $e->getMessage());
        $students = [];
    }
}

// ── Helper function ──────────────────────────────────────────────────────────
function getGradeInfoStaffDisplay($total, $scale)
{
    foreach ($scale as $row) {
        if ($total >= (float)$row['min'] && $total <= (float)$row['max'])
            return ['grade' => $row['grade'], 'remark' => $row['remark']];
    }
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// ── Load existing scores for active subject ───────────────────────────────────
$existing_scores = [];
if ($active_subject_id > 0 && !empty($students)) {
    try {
        $stmt = $pdo->prepare("
            SELECT ss.student_id, ss.score_data, ss.total_score, ss.grade, ss.subject_position
            FROM student_scores ss
            JOIN students st ON ss.student_id = st.id
            WHERE ss.school_id=? AND ss.subject_id=? AND ss.session=? AND ss.term=?
              AND st.class_id=?
        ");
        $stmt->execute([$school_id, $active_subject_id, $session, $term, $class_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['score_data'] = json_decode($row['score_data'] ?? '[]', true) ?: [];
            $existing_scores[(int)$row['student_id']] = $row;
        }
    } catch (Exception $e) {
        error_log("staff_score_entry existing: " . $e->getMessage());
    }
}

// ── Subjects that already have scores ─────────────────────────────────────────
$subjects_with_scores = [];
if (!empty($subjects)) {
    try {
        $sub_ids = array_column($subjects, 'id');
        $ph = implode(',', array_fill(0, count($sub_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT ss.subject_id FROM student_scores ss
            JOIN students st ON ss.student_id = st.id
            WHERE ss.school_id=? AND ss.session=? AND ss.term=?
              AND st.class_id=? AND ss.subject_id IN ($ph)
        ");
        $stmt->execute(array_merge([$school_id, $session, $term, $class_id], $sub_ids));
        $subjects_with_scores = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { /* non-fatal */
    }
}

// Stats
$total_subjects     = count($subjects);
$total_students     = count($students);
$completed_subjects = count($subjects_with_scores);
$progress_pct       = $total_subjects > 0 ? round(($completed_subjects / $total_subjects) * 100) : 0;

// Active subject name
$active_subject_name = '';
foreach ($subjects as $sub) {
    if ((int)$sub['id'] === $active_subject_id) {
        $active_subject_name = $sub['subject_name'];
        break;
    }
}

// Flash messages
$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success']);
unset($_SESSION['flash_error']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> — Score Entry (Staff)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --sidebar-width: 280px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius-md: 12px;
            --radius-sm: 8px;
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

        .main-content {
            margin-left: 0;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.28s ease;
        }

        .top-header {
            background: white;
            padding: 20px 25px;
            border-radius: var(--radius-md);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.4rem;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .header-title h1 i {
            margin-right: 10px;
        }

        .header-title p {
            color: #666;
            font-size: 0.8rem;
        }

        .info-item {
            background: var(--light-color);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border-top: 3px solid var(--primary-color);
        }

        .stat-card.green {
            border-top-color: var(--success-color);
        }

        .stat-card.amber {
            border-top-color: var(--warning-color);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-card.green .stat-value {
            color: var(--success-color);
        }

        .stat-card.amber .stat-value {
            color: var(--warning-color);
        }

        .stat-label {
            font-size: 0.75rem;
            color: #777;
            margin-top: 4px;
        }

        .progress-wrap {
            background: white;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 6px;
            background: var(--light-color);
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .subject-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .subject-card {
            background: white;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: #333;
            border: 1px solid var(--light-color);
            transition: all 0.2s;
        }

        .subject-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }

        .subject-card.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .subject-name {
            font-weight: 500;
            font-size: 0.85rem;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .status-done {
            background: var(--success-color);
        }

        .status-pending {
            background: var(--light-color);
            border: 1px solid #ccc;
        }

        .score-card {
            background: white;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .score-header {
            background: var(--primary-color);
            color: white;
            padding: 14px 20px;
        }

        .score-header h2 {
            font-size: 1rem;
            font-weight: 600;
        }

        .score-header .meta {
            font-size: 0.7rem;
            opacity: 0.8;
            margin-top: 4px;
        }

        .score-list {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .student-score-row {
            background: #f9f9f9;
            border-radius: var(--radius-sm);
            padding: 12px;
            border: 1px solid var(--light-color);
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--light-color);
        }

        .student-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .student-details {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .student-adm {
            font-size: 0.7rem;
            color: #888;
        }

        .score-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }

        .score-field {
            flex: 1;
            min-width: 80px;
        }

        .score-field label {
            display: block;
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 4px;
        }

        .score-input {
            width: 100%;
            padding: 8px;
            text-align: center;
            border: 1.5px solid #ddd;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
        }

        .score-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .result-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid var(--light-color);
        }

        .total-score {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary-color);
        }

        .grade-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .grade-A {
            background: #d4edda;
            color: #155724;
        }

        .grade-B {
            background: #cce5ff;
            color: #004085;
        }

        .grade-C {
            background: #fff3cd;
            color: #856404;
        }

        .grade-D {
            background: #ffe5cc;
            color: #7d4000;
        }

        .grade-F {
            background: #f8d7da;
            color: #721c24;
        }

        .footer-buttons {
            padding: 16px 20px;
            border-top: 1px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-secondary {
            background: white;
            color: var(--primary-color);
            border: 1.5px solid var(--primary-color);
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        @media (min-width: 768px) {
            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding-top: 70px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .subject-grid {
                grid-template-columns: 1fr;
            }

            .score-fields {
                flex-direction: column;
            }

            .footer-buttons {
                flex-direction: column;
            }

            .footer-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <?php include_once 'includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-pencil-alt"></i> Enter Scores</h1>
                <p><i class="fas fa-chevron-right"></i> <?php echo htmlspecialchars($record['record_name'] ?? "{$session} {$term} Term"); ?> · <?php echo htmlspecialchars($display_class_name); ?></p>
            </div>
            <div>
                <span class="info-item"><i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_subjects; ?></div>
                <div class="stat-label">My Subjects</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value"><?php echo $completed_subjects; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-value"><?php echo $total_subjects - $completed_subjects; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_students; ?></div>
                <div class="stat-label">Students</div>
            </div>
        </div>

        <div class="progress-wrap">
            <div class="progress-label">
                <span>Score entry progress</span>
                <span><?php echo $completed_subjects; ?> / <?php echo $total_subjects; ?> subjects · <?php echo $progress_pct; ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress_pct; ?>%"></div>
            </div>
        </div>

        <?php if (empty($subjects)): ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3>No subjects assigned</h3>
                <p>You haven't been assigned to any subjects for this class.</p>
                <p style="margin-top: 8px;">Please contact the administrator.</p>
            </div>
        <?php elseif (empty($students)): ?>
            <div class="empty-state">
                <i class="fas fa-user-graduate"></i>
                <h3>No students found</h3>
                <p>No active students found in <?php echo htmlspecialchars($display_class_name); ?>.</p>
                <p style="margin-top: 8px;">Please ensure students have the correct class assigned.</p>
            </div>
        <?php else: ?>

            <!-- Subject Selection Grid -->
            <div class="subject-grid">
                <?php foreach ($subjects as $sub):
                    $is_done = isset($subjects_with_scores[(int)$sub['id']]);
                    $is_active = (int)$sub['id'] === $active_subject_id;
                ?>
                    <a href="staff_score_entry.php?record_id=<?php echo $record_id; ?>&subject_id=<?php echo $sub['id']; ?>"
                        class="subject-card <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="subject-name"><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                        <span class="status-dot <?php echo $is_done ? 'status-done' : 'status-pending'; ?>"></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Score Entry Form -->
            <?php if ($active_subject_id > 0 && $active_subject_name): ?>
                <form method="POST" id="scoreForm">
                    <input type="hidden" name="subject_id" value="<?php echo $active_subject_id; ?>">
                    <input type="hidden" name="save_scores" value="1">

                    <div class="score-card">
                        <div class="score-header">
                            <h2><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($active_subject_name); ?></h2>
                            <div class="meta">
                                <?php echo htmlspecialchars($display_class_name); ?> · <?php echo htmlspecialchars($term); ?> Term · <?php echo htmlspecialchars($session); ?>
                                · Total: <?php echo (int)$record['max_score']; ?> marks
                                (<?php echo implode(' + ', array_map(fn($s) => htmlspecialchars($s['label']) . '/' . (int)$s['max'], $score_types)); ?>)
                            </div>
                        </div>

                        <div class="score-list">
                            <?php foreach ($students as $stu):
                                $stu_id = (int)$stu['id'];
                                $saved = $existing_scores[$stu_id] ?? null;
                                $initials = strtoupper(substr($stu['full_name'], 0, 2));
                                $graded = $saved ? getGradeInfoStaffDisplay((float)$saved['total_score'], $grading_scale) : null;
                                $grade_class = $graded ? 'grade-' . $graded['grade'] : 'grade-F';
                            ?>
                                <div class="student-score-row">
                                    <div class="student-info">
                                        <div class="student-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                        <div class="student-details">
                                            <div class="student-name"><?php echo htmlspecialchars($stu['full_name']); ?></div>
                                            <div class="student-adm">Adm: <?php echo htmlspecialchars($stu['admission_number']); ?></div>
                                        </div>
                                    </div>

                                    <div class="score-fields">
                                        <?php foreach ($score_types as $st):
                                            $lbl = $st['label'];
                                            $maxVal = (int)$st['max'];
                                            $val = $saved ? ($saved['score_data'][$lbl] ?? '') : '';
                                        ?>
                                            <div class="score-field">
                                                <label><?php echo htmlspecialchars($lbl); ?> / <?php echo $maxVal; ?></label>
                                                <input type="number"
                                                    name="scores[<?php echo $stu_id; ?>][<?php echo htmlspecialchars($lbl); ?>]"
                                                    class="score-input"
                                                    value="<?php echo htmlspecialchars((string)$val); ?>"
                                                    min="0" max="<?php echo $maxVal; ?>" step="0.5"
                                                    data-student="<?php echo $stu_id; ?>"
                                                    data-max="<?php echo $maxVal; ?>"
                                                    oninput="recalcRow(this, <?php echo $stu_id; ?>)">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="result-row">
                                        <div>
                                            <span class="total-score" id="total_<?php echo $stu_id; ?>">
                                                <?php echo $saved ? number_format((float)$saved['total_score'], 1) : '—'; ?>
                                            </span>
                                            <span style="font-size: 0.7rem; color: #888;" id="remark_<?php echo $stu_id; ?>">
                                                <?php echo $graded ? $graded['remark'] : ''; ?>
                                            </span>
                                        </div>
                                        <div id="grade_<?php echo $stu_id; ?>">
                                            <?php if ($graded): ?>
                                                <span class="grade-badge <?php echo $grade_class; ?>"><?php echo $graded['grade']; ?></span>
                                            <?php else: ?>
                                                <span class="grade-badge grade-F">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="footer-buttons">
                            <button type="button" class="btn btn-secondary" onclick="fillZeros()">
                                <i class="fas fa-fill-drip"></i> Fill Zeros
                            </button>
                            <button type="submit" class="btn btn-primary" id="saveBtn">
                                <i class="fas fa-save"></i> Save Scores
                            </button>
                        </div>
                    </div>
                </form>

                <?php if ($completed_subjects >= $total_subjects && $total_subjects > 0): ?>
                    <div class="alert alert-success" style="margin-top: 16px;">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>All subjects complete!</strong>
                            <div style="margin-top: 8px;">
                                <a href="staff_traits_comments.php?record_id=<?php echo $record_id; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-arrow-right"></i> Continue to Traits & Comments
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        const GRADE_SCALE = <?php echo json_encode($grading_scale); ?>;

        function getGrade(total) {
            for (const r of GRADE_SCALE)
                if (total >= r.min && total <= r.max) return r;
            return {
                grade: 'F',
                remark: 'Fail'
            };
        }

        function recalcRow(input, studentId) {
            const inputs = document.querySelectorAll(`input[data-student="${studentId}"]`);
            let total = 0;
            let hasAny = false;

            inputs.forEach(i => {
                const val = parseFloat(i.value);
                if (!isNaN(val)) {
                    total += val;
                    hasAny = true;
                    const max = parseFloat(i.dataset.max);
                    if (val > max) {
                        i.style.borderColor = '#e74c3c';
                        i.style.backgroundColor = '#fff5f5';
                    } else {
                        i.style.borderColor = '#ddd';
                        i.style.backgroundColor = 'white';
                    }
                }
            });

            const totalEl = document.getElementById('total_' + studentId);
            const gradeEl = document.getElementById('grade_' + studentId);
            const remarkEl = document.getElementById('remark_' + studentId);

            if (!hasAny) {
                if (totalEl) totalEl.textContent = '—';
                if (gradeEl) gradeEl.innerHTML = '<span class="grade-badge grade-F">—</span>';
                if (remarkEl) remarkEl.textContent = '';
                return;
            }

            const g = getGrade(total);
            if (totalEl) totalEl.textContent = Number.isInteger(total) ? total : total.toFixed(1);
            if (gradeEl) gradeEl.innerHTML = `<span class="grade-badge grade-${g.grade}">${g.grade}</span>`;
            if (remarkEl) remarkEl.textContent = g.remark;
        }

        function fillZeros() {
            document.querySelectorAll('.score-input').forEach(i => {
                if (i.value.trim() === '') {
                    i.value = '0';
                }
            });
            <?php foreach ($students as $s): ?>
                recalcRow(null, <?php echo (int)$s['id']; ?>);
            <?php endforeach; ?>
        }

        document.getElementById('scoreForm')?.addEventListener('submit', function(e) {
            const overLimit = document.querySelectorAll('.score-input[style*="border-color: rgb(231, 76, 60)"]');
            if (overLimit.length) {
                e.preventDefault();
                alert('Some scores exceed the maximum allowed. Please fix them before saving.');
                return;
            }
            const btn = document.getElementById('saveBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    </script>
</body>

</html>
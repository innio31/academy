<?php
// staff/manage-subjects.php - Staff view-only subjects (assigned to them)
session_start();

// Check if staff is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: /ida/login.php");
    exit();
}

require_once '../includes/config.php';

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['user_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';

// Get the staff_id string from staff table (used in staff_subjects)
$staff_id_string = '';
try {
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
    $stmt->execute([$staff_id, $school_id]);
    $staff_id_string = $stmt->fetchColumn();
    if (!$staff_id_string) {
        $staff_id_string = $_SESSION['staff_id'] ?? $staff_id;
    }
} catch (Exception $e) {
    $staff_id_string = $_SESSION['staff_id'] ?? $staff_id;
}

// Get assigned subject IDs for this staff member
$assigned_subject_ids = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT subject_id 
        FROM staff_subjects 
        WHERE staff_id = ? AND school_id = ?
    ");
    $stmt->execute([$staff_id_string, $school_id]);
    $assigned_subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching staff subjects: " . $e->getMessage());
}

// If no subjects assigned, show empty state
$has_assigned_subjects = !empty($assigned_subject_ids);

// Fetch subjects assigned to this staff member
$subjects = [];
if ($has_assigned_subjects) {
    $placeholders = str_repeat('?,', count($assigned_subject_ids) - 1) . '?';
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name) as assigned_classes,
                   (SELECT COUNT(*) FROM objective_questions WHERE subject_id = s.id) as objective_count,
                   (SELECT COUNT(*) FROM subjective_questions WHERE subject_id = s.id) as subjective_count,
                   (SELECT COUNT(*) FROM theory_questions WHERE subject_id = s.id) as theory_count,
                   (SELECT COUNT(*) FROM exams WHERE subject_id = s.id) as exam_count,
                   (SELECT COUNT(*) FROM topics WHERE subject_id = s.id AND school_id = s.school_id) as topic_count
            FROM subjects s
            LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
            LEFT JOIN classes c ON sc.class_id = c.id
            WHERE s.id IN ($placeholders) AND s.school_id = ?
            GROUP BY s.id
            ORDER BY s.subject_name
        ");
        $params = array_merge($assigned_subject_ids, [$school_id]);
        $stmt->execute($params);
        $subjects = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error fetching subjects: " . $e->getMessage());
    }
}

// Get AJAX request for subject details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_subject' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $subject_id = intval($_GET['id']);
        
        // Verify staff has access to this subject
        if (!in_array($subject_id, $assigned_subject_ids)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   GROUP_CONCAT(DISTINCT c.id ORDER BY c.class_name) as assigned_class_ids,
                   GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name) as assigned_class_names
            FROM subjects s
            LEFT JOIN subject_classes sc ON s.id = sc.subject_id AND sc.school_id = s.school_id
            LEFT JOIN classes c ON sc.class_id = c.id
            WHERE s.id = ? AND s.school_id = ?
            GROUP BY s.id
        ");
        $stmt->execute([$subject_id, $school_id]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($subject) {
            // Get counts
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM objective_questions WHERE subject_id = ? AND school_id = ?");
            $stmt->execute([$subject_id, $school_id]);
            $objective_count = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjective_questions WHERE subject_id = ? AND school_id = ?");
            $stmt->execute([$subject_id, $school_id]);
            $subjective_count = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM theory_questions WHERE subject_id = ? AND school_id = ?");
            $stmt->execute([$subject_id, $school_id]);
            $theory_count = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE subject_id = ? AND school_id = ?");
            $stmt->execute([$subject_id, $school_id]);
            $exam_count = $stmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'subject' => [
                    'id' => $subject['id'],
                    'subject_name' => $subject['subject_name'],
                    'description' => $subject['description'],
                    'assigned_class_ids' => $subject['assigned_class_ids'],
                    'assigned_class_names' => $subject['assigned_class_names'],
                    'objective_count' => $objective_count,
                    'subjective_count' => $subjective_count,
                    'theory_count' => $theory_count,
                    'exam_count' => $exam_count
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Subject not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Include staff sidebar
require_once 'includes/staff_sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($school_name); ?> - My Subjects</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --primary-dark: #1a5a8a;
            --secondary-color: #3498db;
            --success: #27ae60;
            --success-light: #d5f4e6;
            --warning: #f39c12;
            --warning-light: #fef5e7;
            --danger: #e74c3c;
            --danger-light: #fbe9e7;
            --info: #3498db;
            --info-light: #eaf6ff;
            --gray-50: #f9fafb;
            --gray-100: #f0f2f5;
            --gray-200: #e4e7eb;
            --gray-300: #d1d5db;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .top-header {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .header-title p {
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .btn {
            padding: 10px 18px;
            border-radius: var(--radius-md);
            border: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.7rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-800);
        }

        .search-bar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 12px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: var(--shadow-sm);
        }

        .search-bar i {
            color: var(--gray-600);
        }

        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 10px 15px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 0.85rem;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .subjects-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .subject-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-200);
        }

        .subject-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .subject-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .subject-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            background: var(--info-light);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .subject-name i {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .subject-description {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .subject-classes {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }

        .class-tag {
            background: var(--gray-100);
            color: var(--gray-600);
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
        }

        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }

        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .stat-badge.objective {
            background: #e3f2fd;
            color: #1976d2;
        }

        .stat-badge.subjective {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .stat-badge.theory {
            background: #e8f5e9;
            color: #388e3c;
        }

        .stat-badge.exam {
            background: #fff3e0;
            color: #f57c00;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 550px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
        }

        .modal-header h3 {
            font-size: 1.1rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-600);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 110px;
            font-weight: 600;
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 0.85rem;
        }

        .modal-action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-200);
        }

        .modal-action-btn {
            flex: 1;
            min-width: 100px;
            justify-content: center;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius-lg);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 16px;
        }

        .empty-state h3 {
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--gray-600);
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .top-header {
                flex-direction: column;
                text-align: center;
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>

<body>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-book"></i> My Subjects</h1>
                <p>Subjects assigned to you for teaching</p>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search subjects by name, description, or class...">
        </div>

        <!-- Subjects Grid -->
        <?php if (!$has_assigned_subjects): ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3>No Subjects Assigned</h3>
                <p>You haven't been assigned any subjects yet. Please contact the school administrator.</p>
            </div>
        <?php elseif (empty($subjects)): ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3>No Subjects Found</h3>
                <p>No subject records found for your assigned subjects.</p>
            </div>
        <?php else: ?>
            <div class="subjects-grid" id="subjectsGrid">
                <?php foreach ($subjects as $subject): ?>
                    <div class="subject-card" data-subject-id="<?php echo $subject['id']; ?>" 
                         data-subject-name="<?php echo htmlspecialchars(strtolower($subject['subject_name'])); ?>" 
                         data-subject-desc="<?php echo htmlspecialchars(strtolower($subject['description'] ?? '')); ?>" 
                         data-subject-classes="<?php echo htmlspecialchars(strtolower($subject['assigned_classes'] ?? '')); ?>">
                        <div class="subject-card-header">
                            <span class="subject-name">
                                <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </span>
                        </div>
                        <?php if ($subject['description']): ?>
                            <div class="subject-description">
                                <?php echo htmlspecialchars(substr($subject['description'], 0, 100)) . (strlen($subject['description'] ?? '') > 100 ? '...' : ''); ?>
                            </div>
                        <?php endif; ?>
                        <div class="subject-classes">
                            <?php if ($subject['assigned_classes']): ?>
                                <?php foreach (explode(',', $subject['assigned_classes']) as $class): ?>
                                    <span class="class-tag"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($class); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="class-tag" style="background: var(--warning-light); color: var(--warning);">Not assigned to any class</span>
                            <?php endif; ?>
                        </div>
                        <div class="stats-row">
                            <span class="stat-badge objective" title="Objective Questions"><i class="fas fa-check-circle"></i> O: <?php echo $subject['objective_count']; ?></span>
                            <span class="stat-badge subjective" title="Subjective Questions"><i class="fas fa-pencil-alt"></i> S: <?php echo $subject['subjective_count']; ?></span>
                            <span class="stat-badge theory" title="Theory Questions"><i class="fas fa-file-alt"></i> T: <?php echo $subject['theory_count']; ?></span>
                            <span class="stat-badge exam" title="Topics"><i class="fas fa-tags"></i> Topics: <?php echo $subject['topic_count']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Subject Detail Modal -->
    <div class="modal" id="subjectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalSubjectTitle">Subject Details</h3>
                <button class="close-modal" onclick="closeModal('subjectModal')">&times;</button>
            </div>
            <div class="modal-body" id="subjectModalBody">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-pulse fa-2x"></i>
                    <p>Loading subject details...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const subjectCards = document.querySelectorAll('.subject-card');

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const term = this.value.toLowerCase();
                subjectCards.forEach(card => {
                    const name = card.getAttribute('data-subject-name') || '';
                    const desc = card.getAttribute('data-subject-desc') || '';
                    const classes = card.getAttribute('data-subject-classes') || '';
                    const matches = name.includes(term) || desc.includes(term) || classes.includes(term);
                    card.style.display = matches ? 'block' : 'none';
                });
            });
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Open subject detail modal when clicking a card
        const cards = document.querySelectorAll('.subject-card');
        cards.forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('a')) return;
                const subjectId = this.getAttribute('data-subject-id');
                openSubjectModal(subjectId);
            });
        });

        function openSubjectModal(subjectId) {
            const modal = document.getElementById('subjectModal');
            const modalBody = document.getElementById('subjectModalBody');
            const modalTitle = document.getElementById('modalSubjectTitle');

            modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-pulse fa-2x"></i><p>Loading subject details...</p></div>';
            openModal('subjectModal');

            fetch(`manage-subjects.php?ajax=get_subject&id=${subjectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const subject = data.subject;
                        modalTitle.innerHTML = `<i class="fas fa-book"></i> ${escapeHtml(subject.subject_name)}`;

                        let assignedClassesHtml = '';
                        if (subject.assigned_class_names) {
                            const classNames = subject.assigned_class_names.split(',');
                            classNames.forEach(className => {
                                assignedClassesHtml += `<span class="class-tag" style="background: var(--info-light);"><i class="fas fa-chalkboard"></i> ${escapeHtml(className)}</span>`;
                            });
                        } else {
                            assignedClassesHtml = '<span class="class-tag" style="background: var(--warning-light);">Not assigned to any class</span>';
                        }

                        modalBody.innerHTML = `
                            <div class="info-row">
                                <div class="info-label">Subject Name:</div>
                                <div class="info-value"><strong>${escapeHtml(subject.subject_name)}</strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Description:</div>
                                <div class="info-value">${escapeHtml(subject.description) || '<span style="color: #999;">No description</span>'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Assigned Classes:</div>
                                <div class="info-value"><div style="display: flex; flex-wrap: wrap; gap: 6px;">${assignedClassesHtml}</div></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Usage Stats:</div>
                                <div class="info-value">
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <span class="stat-badge objective"><i class="fas fa-check-circle"></i> Objective: ${subject.objective_count}</span>
                                        <span class="stat-badge subjective"><i class="fas fa-pencil-alt"></i> Subjective: ${subject.subjective_count}</span>
                                        <span class="stat-badge theory"><i class="fas fa-file-alt"></i> Theory: ${subject.theory_count}</span>
                                        <span class="stat-badge exam"><i class="fas fa-calendar-alt"></i> Exams: ${subject.exam_count}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-action-buttons">
                                <a href="manage-topics.php?subject_id=${subject.id}" class="btn btn-primary modal-action-btn">
                                    <i class="fas fa-tags"></i> Manage Topics
                                </a>
                                <a href="manage-questions.php?subject_id=${subject.id}" class="btn btn-success modal-action-btn">
                                    <i class="fas fa-question-circle"></i> Manage Questions
                                </a>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle fa-2x"></i><p>${escapeHtml(data.message)}</p><button class="btn btn-outline" onclick="closeModal('subjectModal')">Close</button></div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `<div style="text-align: center; padding: 40px; color: var(--danger);"><i class="fas fa-exclamation-triangle fa-2x"></i><p>Failed to load subject details. Please try again.</p><button class="btn btn-outline" onclick="closeModal('subjectModal')">Close</button></div>`;
                });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        };
    </script>
</body>

</html>
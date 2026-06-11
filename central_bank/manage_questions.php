<?php
// /central_bank/manage_questions.php - Manage all questions with advanced filtering
// Redesigned: filter-first flow, slide modal with image/PDF viewer

require_once 'includes/config.php';
require_once 'includes/auth.php';
require_super_admin();

$page_title = 'Manage Questions';
$page_subtitle = 'Browse, review, edit and delete questions from all question banks';

$message = '';
$message_type = '';

// Pagination
$items_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Filter values
$active_tab      = $_GET['tab'] ?? '';
$filter_subject  = isset($_GET['filter_subject'])  ? (int)$_GET['filter_subject']  : 0;
$filter_topic    = isset($_GET['filter_topic'])    ? (int)$_GET['filter_topic']    : 0;
$filter_year     = isset($_GET['filter_year'])     ? (int)$_GET['filter_year']     : 0;
$filter_difficulty = isset($_GET['filter_difficulty']) ? $_GET['filter_difficulty'] : '';
$search_term     = isset($_GET['search'])          ? trim($_GET['search'])          : '';

// Determine if filters have been applied enough to show questions
$filters_applied = !empty($active_tab);

// Tab counts
$obj_count  = $pdo->query("SELECT COUNT(*) FROM objective_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$sub_count  = $pdo->query("SELECT COUNT(*) FROM subjective_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$the_count  = $pdo->query("SELECT COUNT(*) FROM theory_questions WHERE is_central = 1 OR school_id IS NULL")->fetchColumn();
$waec_count = $pdo->query("SELECT COUNT(*) FROM waec_questions WHERE is_active = 1")->fetchColumn();
$jamb_count = $pdo->query("SELECT COUNT(*) FROM jamb_questions WHERE is_active = 1")->fetchColumn();

// ============================================
// HANDLE DELETE
// ============================================
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $type = $_GET['type'];
    $id   = (int)$_GET['id'];
    $table = match($type) {
        'objective'  => 'objective_questions',
        'subjective' => 'subjective_questions',
        'theory'     => 'theory_questions',
        'waec'       => 'waec_questions',
        'jamb'       => 'jamb_questions',
        default      => ''
    };
    if ($table) {
        try {
            $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            $message = "Question deleted successfully.";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// ============================================
// DROPDOWN DATA
// ============================================
$subjects      = $pdo->query("SELECT id, subject_name FROM subjects WHERE is_central = 1 OR school_id IS NULL ORDER BY subject_name")->fetchAll();
$waec_subjects = $pdo->query("SELECT id, subject_name FROM waec_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();
$jamb_subjects = $pdo->query("SELECT id, subject_name FROM jamb_subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();

$years = [];
for ($y = date('Y'); $y >= 1990; $y--) { $years[] = $y; }

$topics_for_filter = [];
if ($filter_subject) {
    if ($active_tab === 'waec') {
        $stmt = $pdo->prepare("SELECT id, topic_name FROM waec_topics WHERE waec_subject_id = ? AND is_active = 1 ORDER BY topic_name");
    } elseif ($active_tab === 'jamb') {
        $stmt = $pdo->prepare("SELECT id, topic_name FROM jamb_topics WHERE jamb_subject_id = ? AND is_active = 1 ORDER BY topic_name");
    } else {
        $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? AND (is_central = 1 OR school_id IS NULL) ORDER BY topic_name");
    }
    $stmt->execute([$filter_subject]);
    $topics_for_filter = $stmt->fetchAll();
}

// ============================================
// BUILD QUERIES (only when filters applied)
// ============================================
$questions       = [];
$total_count     = 0;
$total_pages     = 0;
$current_type    = $active_tab;

if ($filters_applied) {
    $params = [];

    switch ($active_tab) {
        case 'objective':
            $sql = "SELECT oq.id, oq.question_text, oq.option_a, oq.option_b, oq.option_c, oq.option_d,
                           oq.correct_answer, oq.difficulty_level, oq.marks, oq.question_image,
                           s.subject_name, t.topic_name
                    FROM objective_questions oq
                    LEFT JOIN subjects s ON oq.subject_id = s.id
                    LEFT JOIN topics t ON oq.topic_id = t.id
                    WHERE (oq.is_central = 1 OR oq.school_id IS NULL)";
            if ($filter_subject)    { $sql .= " AND oq.subject_id = ?";      $params[] = $filter_subject; }
            if ($filter_topic)      { $sql .= " AND oq.topic_id = ?";        $params[] = $filter_topic; }
            if ($filter_difficulty) { $sql .= " AND oq.difficulty_level = ?"; $params[] = $filter_difficulty; }
            if ($search_term)       { $sql .= " AND oq.question_text LIKE ?"; $params[] = "%$search_term%"; }
            break;

        case 'subjective':
            $sql = "SELECT sq.id, sq.question_text, sq.correct_answer, sq.difficulty_level, sq.marks,
                           s.subject_name, t.topic_name
                    FROM subjective_questions sq
                    LEFT JOIN subjects s ON sq.subject_id = s.id
                    LEFT JOIN topics t ON sq.topic_id = t.id
                    WHERE (sq.is_central = 1 OR sq.school_id IS NULL)";
            if ($filter_subject)    { $sql .= " AND sq.subject_id = ?";       $params[] = $filter_subject; }
            if ($filter_topic)      { $sql .= " AND sq.topic_id = ?";         $params[] = $filter_topic; }
            if ($filter_difficulty) { $sql .= " AND sq.difficulty_level = ?"; $params[] = $filter_difficulty; }
            if ($search_term)       { $sql .= " AND sq.question_text LIKE ?"; $params[] = "%$search_term%"; }
            break;

        case 'theory':
            $sql = "SELECT tq.id, tq.question_text, tq.question_file, tq.marks,
                           s.subject_name, t.topic_name
                    FROM theory_questions tq
                    LEFT JOIN subjects s ON tq.subject_id = s.id
                    LEFT JOIN topics t ON tq.topic_id = t.id
                    WHERE (tq.is_central = 1 OR tq.school_id IS NULL)";
            if ($filter_subject) { $sql .= " AND tq.subject_id = ?";       $params[] = $filter_subject; }
            if ($filter_topic)   { $sql .= " AND tq.topic_id = ?";         $params[] = $filter_topic; }
            if ($search_term)    { $sql .= " AND tq.question_text LIKE ?"; $params[] = "%$search_term%"; }
            break;

        case 'waec':
            $sql = "SELECT wq.id, wq.question_text, wq.option_a, wq.option_b, wq.option_c, wq.option_d, wq.option_e,
                           wq.correct_answer, wq.difficulty_level, wq.explanation, wq.exam_year, wq.question_image,
                           ws.subject_name, wt.topic_name
                    FROM waec_questions wq
                    LEFT JOIN waec_subjects ws ON wq.waec_subject_id = ws.id
                    LEFT JOIN waec_topics wt ON wq.waec_topic_id = wt.id
                    WHERE wq.is_active = 1";
            if ($filter_subject)    { $sql .= " AND wq.waec_subject_id = ?"; $params[] = $filter_subject; }
            if ($filter_topic)      { $sql .= " AND wq.waec_topic_id = ?";   $params[] = $filter_topic; }
            if ($filter_year)       { $sql .= " AND wq.exam_year = ?";       $params[] = $filter_year; }
            if ($filter_difficulty) { $sql .= " AND wq.difficulty_level = ?"; $params[] = $filter_difficulty; }
            if ($search_term)       { $sql .= " AND wq.question_text LIKE ?"; $params[] = "%$search_term%"; }
            break;

        case 'jamb':
            $sql = "SELECT jq.id, jq.question_text, jq.option_a, jq.option_b, jq.option_c, jq.option_d,
                           jq.correct_answer, jq.difficulty_level, jq.explanation, jq.exam_year, jq.question_image,
                           js.subject_name, jt.topic_name
                    FROM jamb_questions jq
                    LEFT JOIN jamb_subjects js ON jq.jamb_subject_id = js.id
                    LEFT JOIN jamb_topics jt ON jq.jamb_topic_id = jt.id
                    WHERE jq.is_active = 1";
            if ($filter_subject)    { $sql .= " AND jq.jamb_subject_id = ?"; $params[] = $filter_subject; }
            if ($filter_topic)      { $sql .= " AND jq.jamb_topic_id = ?";   $params[] = $filter_topic; }
            if ($filter_year)       { $sql .= " AND jq.exam_year = ?";       $params[] = $filter_year; }
            if ($filter_difficulty) { $sql .= " AND jq.difficulty_level = ?"; $params[] = $filter_difficulty; }
            if ($search_term)       { $sql .= " AND jq.question_text LIKE ?"; $params[] = "%$search_term%"; }
            break;

        default:
            $filters_applied = false;
    }

    if ($filters_applied && !empty($sql)) {
        $count_sql = preg_replace('/SELECT .+? FROM/si', 'SELECT COUNT(*) FROM', $sql);
        $count_sql = preg_replace('/ORDER BY .+/si', '', $count_sql);
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_count = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_count / $items_per_page);

        $sql .= " ORDER BY id DESC LIMIT $items_per_page OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

include 'includes/header.php';
?>

<?php if ($message): ?>
<div class="mq-alert mq-alert--<?php echo $message_type; ?>" id="alertBanner">
    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($message); ?>
    <button onclick="this.parentElement.remove()" class="mq-alert__close">&times;</button>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- PAGE HEADER -->
<!-- ============================================ -->
<div class="mq-page-header">
    <div class="mq-page-header__text">
        <h2 class="mq-page-header__title">Question Bank</h2>
        <p class="mq-page-header__sub">Filter by type and subject to browse, review, or manage questions</p>
    </div>
    <a href="add_question.php" class="mq-btn mq-btn--primary">
        <i class="fas fa-plus"></i> Add Question
    </a>
</div>

<!-- ============================================ -->
<!-- STEP 1 — QUESTION TYPE TABS -->
<!-- ============================================ -->
<div class="mq-section-label"><span>Step 1 — Select question type</span></div>
<div class="mq-type-grid">
    <?php
    $types = [
        ['key' => 'objective',  'icon' => 'fas fa-check-circle',  'label' => 'Objective',  'sub' => 'MCQ / Multiple choice', 'count' => $obj_count,  'color' => 'blue'],
        ['key' => 'subjective', 'icon' => 'fas fa-pen-nib',       'label' => 'Subjective', 'sub' => 'Short answer',          'count' => $sub_count,  'color' => 'purple'],
        ['key' => 'theory',     'icon' => 'fas fa-file-alt',       'label' => 'Theory',     'sub' => 'Essay / long form',     'count' => $the_count,  'color' => 'teal'],
        ['key' => 'waec',       'icon' => 'fas fa-graduation-cap', 'label' => 'WAEC',       'sub' => 'Past questions',        'count' => $waec_count, 'color' => 'green'],
        ['key' => 'jamb',       'icon' => 'fas fa-university',     'label' => 'JAMB',       'sub' => 'Past questions',        'count' => $jamb_count, 'color' => 'orange'],
    ];
    foreach ($types as $t):
        $active_class = ($active_tab === $t['key']) ? 'mq-type-card--active' : '';
        $href = '?' . http_build_query(array_filter([
            'tab'              => $t['key'],
            'filter_subject'   => ($active_tab === $t['key']) ? $filter_subject : 0,
            'filter_topic'     => ($active_tab === $t['key']) ? $filter_topic   : 0,
            'filter_year'      => ($active_tab === $t['key']) ? $filter_year    : 0,
            'filter_difficulty'=> ($active_tab === $t['key']) ? $filter_difficulty : '',
        ]));
    ?>
    <a href="<?php echo $href; ?>" class="mq-type-card mq-type-card--<?php echo $t['color']; ?> <?php echo $active_class; ?>">
        <div class="mq-type-card__icon"><i class="<?php echo $t['icon']; ?>"></i></div>
        <div class="mq-type-card__body">
            <strong><?php echo $t['label']; ?></strong>
            <span><?php echo $t['sub']; ?></span>
        </div>
        <div class="mq-type-card__count"><?php echo number_format($t['count']); ?></div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($active_tab): ?>
<!-- ============================================ -->
<!-- STEP 2 — FILTERS -->
<!-- ============================================ -->
<div class="mq-section-label"><span>Step 2 — Narrow down</span></div>
<div class="mq-filter-bar">
    <form method="GET" id="filterForm">
        <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
        <div class="mq-filter-bar__grid">

            <?php
            $subject_list = match($active_tab) {
                'waec'  => $waec_subjects,
                'jamb'  => $jamb_subjects,
                default => $subjects
            };
            ?>
            <div class="mq-filter-group">
                <label class="mq-filter-label"><i class="fas fa-book"></i> Subject</label>
                <select name="filter_subject" class="mq-select" onchange="this.form.submit()">
                    <option value="0">All Subjects</option>
                    <?php foreach ($subject_list as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo ($filter_subject == $s['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mq-filter-group">
                <label class="mq-filter-label"><i class="fas fa-tags"></i> Topic</label>
                <select name="filter_topic" class="mq-select" onchange="this.form.submit()" <?php echo empty($topics_for_filter) ? 'disabled' : ''; ?>>
                    <option value="0">All Topics</option>
                    <?php foreach ($topics_for_filter as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo ($filter_topic == $t['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['topic_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($active_tab === 'waec' || $active_tab === 'jamb'): ?>
            <div class="mq-filter-group">
                <label class="mq-filter-label"><i class="fas fa-calendar"></i> Year</label>
                <select name="filter_year" class="mq-select" onchange="this.form.submit()">
                    <option value="0">All Years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($active_tab !== 'theory'): ?>
            <div class="mq-filter-group">
                <label class="mq-filter-label"><i class="fas fa-layer-group"></i> Difficulty</label>
                <select name="filter_difficulty" class="mq-select" onchange="this.form.submit()">
                    <option value="">All Levels</option>
                    <option value="easy"   <?php echo $filter_difficulty === 'easy'   ? 'selected' : ''; ?>>Easy</option>
                    <option value="medium" <?php echo $filter_difficulty === 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="hard"   <?php echo $filter_difficulty === 'hard'   ? 'selected' : ''; ?>>Hard</option>
                </select>
            </div>
            <?php endif; ?>

            <div class="mq-filter-group mq-filter-group--search">
                <label class="mq-filter-label"><i class="fas fa-search"></i> Search</label>
                <div class="mq-search-wrap">
                    <input type="text" name="search" class="mq-select mq-search-input" placeholder="Search question text..." value="<?php echo htmlspecialchars($search_term); ?>">
                    <button type="submit" class="mq-btn mq-btn--search"><i class="fas fa-search"></i></button>
                </div>
            </div>

            <?php if ($filter_subject || $filter_topic || $filter_year || $filter_difficulty || $search_term): ?>
            <div class="mq-filter-group mq-filter-group--clear">
                <label class="mq-filter-label">&nbsp;</label>
                <a href="?tab=<?php echo $active_tab; ?>" class="mq-btn mq-btn--ghost"><i class="fas fa-times"></i> Clear filters</a>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============================================ -->
<!-- RESULTS -->
<!-- ============================================ -->
<?php if (!empty($questions)): ?>
<div class="mq-results-header">
    <div class="mq-results-header__info">
        <span class="mq-results-count"><?php echo number_format($total_count); ?> question<?php echo $total_count !== 1 ? 's' : ''; ?> found</span>
        <?php if ($filter_subject): ?>
            <?php $subj_name = array_column($subject_list, 'subject_name', 'id')[$filter_subject] ?? ''; ?>
            <span class="mq-tag"><?php echo htmlspecialchars($subj_name); ?></span>
        <?php endif; ?>
        <?php if ($filter_difficulty): ?>
            <span class="mq-tag mq-tag--<?php echo $filter_difficulty; ?>"><?php echo ucfirst($filter_difficulty); ?></span>
        <?php endif; ?>
        <?php if ($filter_year): ?>
            <span class="mq-tag"><?php echo $filter_year; ?></span>
        <?php endif; ?>
    </div>
    <button class="mq-btn mq-btn--primary mq-btn--sm" onclick="openSlideModal(0)">
        <i class="fas fa-play"></i> Browse All
    </button>
</div>

<!-- Question Cards Grid -->
<div class="mq-cards-grid" id="questionGrid">
    <?php foreach ($questions as $i => $q): ?>
    <div class="mq-q-card" onclick="openSlideModal(<?php echo $i; ?>)" data-index="<?php echo $i; ?>">
        <div class="mq-q-card__top">
            <span class="mq-q-card__num">#<?php echo $q['id']; ?></span>
            <div class="mq-q-card__badges">
                <?php if (!empty($q['difficulty_level'])): ?>
                <span class="mq-diff mq-diff--<?php echo $q['difficulty_level']; ?>"><?php echo ucfirst($q['difficulty_level']); ?></span>
                <?php endif; ?>
                <?php if (!empty($q['exam_year'])): ?>
                <span class="mq-tag mq-tag--year"><?php echo $q['exam_year']; ?></span>
                <?php endif; ?>
                <?php if (!empty($q['question_image'])): ?>
                <span class="mq-tag mq-tag--media"><i class="fas fa-image"></i></span>
                <?php endif; ?>
                <?php if (!empty($q['question_file'])): ?>
                <span class="mq-tag mq-tag--media"><i class="fas fa-file-pdf"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="mq-q-card__text">
            <?php echo htmlspecialchars(mb_substr($q['question_text'] ?? 'See attached file', 0, 120)); ?><?php echo mb_strlen($q['question_text'] ?? '') > 120 ? '…' : ''; ?>
        </div>
        <div class="mq-q-card__footer">
            <span class="mq-q-card__subject"><i class="fas fa-book"></i> <?php echo htmlspecialchars($q['subject_name'] ?? 'N/A'); ?></span>
            <?php if (!empty($q['topic_name'])): ?>
            <span class="mq-q-card__topic"><?php echo htmlspecialchars($q['topic_name']); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="mq-pagination">
    <?php
    $base = '?tab=' . $active_tab
        . ($filter_subject   ? "&filter_subject=$filter_subject"     : '')
        . ($filter_topic     ? "&filter_topic=$filter_topic"         : '')
        . ($filter_year      ? "&filter_year=$filter_year"           : '')
        . ($filter_difficulty? "&filter_difficulty=$filter_difficulty": '')
        . ($search_term      ? '&search=' . urlencode($search_term)  : '');

    if ($current_page > 1):
    ?><a href="<?php echo $base; ?>&page=<?php echo $current_page - 1; ?>" class="mq-page-btn"><i class="fas fa-chevron-left"></i></a><?php
    endif;

    $start = max(1, $current_page - 2);
    $end   = min($total_pages, $current_page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?><a href="<?php echo $base; ?>&page=<?php echo $i; ?>" class="mq-page-btn <?php echo $i == $current_page ? 'mq-page-btn--active' : ''; ?>"><?php echo $i; ?></a><?php
    endfor;

    if ($current_page < $total_pages):
    ?><a href="<?php echo $base; ?>&page=<?php echo $current_page + 1; ?>" class="mq-page-btn"><i class="fas fa-chevron-right"></i></a><?php
    endif;
    ?>
</div>
<?php endif; ?>

<?php elseif ($filters_applied): ?>
<div class="mq-empty">
    <i class="fas fa-inbox"></i>
    <p>No questions match your filters.</p>
    <a href="add_question.php?type=<?php echo $active_tab; ?>" class="mq-btn mq-btn--primary">Add First Question</a>
</div>
<?php endif; ?>

<?php endif; /* end if active_tab */ ?>

<!-- ============================================ -->
<!-- SLIDE MODAL -->
<!-- ============================================ -->
<div class="mq-modal" id="slideModal" style="display:none;" role="dialog" aria-modal="true">
    <div class="mq-modal__backdrop" onclick="closeSlideModal()"></div>
    <div class="mq-modal__panel">
        <!-- Modal Header -->
        <div class="mq-modal__header">
            <div class="mq-modal__header-left">
                <span class="mq-modal__type-badge" id="modalTypeBadge"></span>
                <span class="mq-modal__counter" id="slideCounter"></span>
            </div>
            <div class="mq-modal__header-right">
                <button class="mq-modal__action-btn mq-modal__action-btn--edit" id="editBtn" title="Edit question">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="mq-modal__action-btn mq-modal__action-btn--delete" id="deleteBtn" title="Delete question">
                    <i class="fas fa-trash"></i>
                </button>
                <button class="mq-modal__close" onclick="closeSlideModal()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Slide area -->
        <div class="mq-modal__slide-wrap">
            <div class="mq-modal__slide" id="modalSlide">
                <!-- Content injected by JS -->
            </div>
        </div>

        <!-- Modal Footer Nav -->
        <div class="mq-modal__footer">
            <button class="mq-nav-btn" id="prevBtn" onclick="navigate(-1)">
                <i class="fas fa-chevron-left"></i> <span>Previous</span>
            </button>
            <div class="mq-modal__dots" id="modalDots"></div>
            <button class="mq-nav-btn mq-nav-btn--next" id="nextBtn" onclick="navigate(1)">
                <span>Next</span> <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>

<!-- Image lightbox -->
<div class="mq-lightbox" id="imageLightbox" style="display:none;" onclick="closeLightbox()">
    <button class="mq-lightbox__close" onclick="closeLightbox()">&times;</button>
    <img id="lightboxImg" src="" alt="Question image">
</div>

<!-- ============================================ -->
<!-- QUESTION DATA FOR JS -->
<!-- ============================================ -->
<script>
const QUESTIONS = <?php echo json_encode($questions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const ACTIVE_TAB = <?php echo json_encode($active_tab); ?>;
const BASE_URL = '../'; // adjust to portal root
const DELETE_BASE = '?tab=<?php echo urlencode($active_tab); ?><?php echo $filter_subject ? "&filter_subject=$filter_subject" : ''; ?><?php echo $filter_topic ? "&filter_topic=$filter_topic" : ''; ?><?php echo $filter_year ? "&filter_year=$filter_year" : ''; ?><?php echo $filter_difficulty ? "&filter_difficulty=$filter_difficulty" : ''; ?><?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>&page=<?php echo $current_page; ?>';

let currentIndex = 0;

function openSlideModal(index) {
    currentIndex = index;
    document.getElementById('slideModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    renderSlide(currentIndex, 'enter');
}

function closeSlideModal() {
    document.getElementById('slideModal').style.display = 'none';
    document.body.style.overflow = '';
}

function navigate(dir) {
    const newIndex = currentIndex + dir;
    if (newIndex < 0 || newIndex >= QUESTIONS.length) return;
    const direction = dir > 0 ? 'next' : 'prev';
    slideOut(direction, () => {
        currentIndex = newIndex;
        renderSlide(currentIndex, direction === 'next' ? 'enter-right' : 'enter-left');
    });
}

function slideOut(direction, callback) {
    const slide = document.getElementById('modalSlide');
    slide.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
    slide.style.transform = direction === 'next' ? 'translateX(-60px)' : 'translateX(60px)';
    slide.style.opacity = '0';
    setTimeout(callback, 220);
}

function renderSlide(index, animDir) {
    const q = QUESTIONS[index];
    if (!q) return;

    // Counter & badges
    document.getElementById('slideCounter').textContent = `${index + 1} / ${QUESTIONS.length}`;
    document.getElementById('modalTypeBadge').textContent = ACTIVE_TAB.toUpperCase();
    document.getElementById('modalTypeBadge').className = `mq-modal__type-badge mq-modal__type-badge--${ACTIVE_TAB}`;

    // Edit/Delete actions
    document.getElementById('editBtn').onclick  = () => { closeSlideModal(); openEditModal(ACTIVE_TAB, q.id); };
    document.getElementById('deleteBtn').onclick = () => {
        if (confirm(`Delete question #${q.id}? This cannot be undone.`)) {
            window.location.href = DELETE_BASE + `&delete=1&type=${ACTIVE_TAB}&id=${q.id}`;
        }
    };

    // Dots
    const dotsEl = document.getElementById('modalDots');
    const total = QUESTIONS.length;
    if (total <= 15) {
        dotsEl.innerHTML = QUESTIONS.map((_, i) =>
            `<span class="mq-dot ${i === index ? 'mq-dot--active' : ''}" onclick="goTo(${i})"></span>`
        ).join('');
    } else {
        dotsEl.innerHTML = '';
    }

    // Nav buttons
    document.getElementById('prevBtn').disabled = index === 0;
    document.getElementById('nextBtn').disabled = index === QUESTIONS.length - 1;

    // Build content HTML
    let html = buildQuestionHtml(q, ACTIVE_TAB);

    // Animate in
    const slide = document.getElementById('modalSlide');
    slide.style.transition = 'none';
    slide.style.transform = animDir === 'enter-right' ? 'translateX(60px)' : animDir === 'enter-left' ? 'translateX(-60px)' : 'translateX(0)';
    slide.style.opacity = '0';
    slide.innerHTML = html;

    requestAnimationFrame(() => {
        slide.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
        slide.style.transform = 'translateX(0)';
        slide.style.opacity = '1';
    });

    // Init PDF viewer if needed
    if (q.question_file) {
        initPdfViewer(q.question_file);
    }
}

function goTo(index) {
    if (index === currentIndex) return;
    const dir = index > currentIndex ? 'next' : 'prev';
    slideOut(dir, () => {
        currentIndex = index;
        renderSlide(currentIndex, dir === 'next' ? 'enter-right' : 'enter-left');
    });
}

function buildQuestionHtml(q, type) {
    let html = '<div class="mq-slide-content">';

    // Subject / Topic / Meta row
    html += `<div class="mq-slide__meta">`;
    if (q.subject_name) html += `<span class="mq-slide__subject"><i class="fas fa-book-open"></i> ${esc(q.subject_name)}</span>`;
    if (q.topic_name)   html += `<span class="mq-slide__topic"><i class="fas fa-tag"></i> ${esc(q.topic_name)}</span>`;
    if (q.exam_year)    html += `<span class="mq-slide__year"><i class="fas fa-calendar-alt"></i> ${q.exam_year}</span>`;
    if (q.difficulty_level) html += `<span class="mq-diff mq-diff--${q.difficulty_level}">${ucFirst(q.difficulty_level)}</span>`;
    html += `</div>`;

    // Question text
    if (q.question_text) {
        html += `<div class="mq-slide__question-text">${esc(q.question_text)}</div>`;
    }

    // Image viewer (for all types that have images)
    if (q.question_image) {
        html += `
        <div class="mq-media-block">
            <div class="mq-media-block__label"><i class="fas fa-image"></i> Question Image</div>
            <div class="mq-img-container" onclick="openLightbox('${BASE_URL}${esc(q.question_image)}')">
                <img src="${BASE_URL}${esc(q.question_image)}" alt="Question image" class="mq-slide__img">
                <div class="mq-img-zoom-hint"><i class="fas fa-search-plus"></i> Tap to expand</div>
            </div>
        </div>`;
    }

    // PDF viewer (for theory or any file attachment)
    if (q.question_file) {
        const isPdf = q.question_file.toLowerCase().endsWith('.pdf');
        if (isPdf) {
            html += `
            <div class="mq-media-block">
                <div class="mq-media-block__label"><i class="fas fa-file-pdf"></i> Attached Document
                    <a href="${BASE_URL}${esc(q.question_file)}" target="_blank" class="mq-btn mq-btn--ghost mq-btn--xs" style="margin-left:auto;">
                        <i class="fas fa-external-link-alt"></i> Open in new tab
                    </a>
                </div>
                <div class="mq-pdf-viewer" id="pdfViewerContainer">
                    <div class="mq-pdf-toolbar">
                        <button onclick="pdfPrevPage()" id="pdfPrevBtn" class="mq-btn mq-btn--ghost mq-btn--xs"><i class="fas fa-chevron-left"></i></button>
                        <span id="pdfPageInfo">Loading…</span>
                        <button onclick="pdfNextPage()" id="pdfNextBtn" class="mq-btn mq-btn--ghost mq-btn--xs"><i class="fas fa-chevron-right"></i></button>
                        <button onclick="pdfZoomOut()" class="mq-btn mq-btn--ghost mq-btn--xs"><i class="fas fa-search-minus"></i></button>
                        <span id="pdfZoomLabel">100%</span>
                        <button onclick="pdfZoomIn()" class="mq-btn mq-btn--ghost mq-btn--xs"><i class="fas fa-search-plus"></i></button>
                    </div>
                    <canvas id="pdfCanvas" class="mq-pdf-canvas"></canvas>
                </div>
            </div>`;
        } else {
            html += `
            <div class="mq-media-block">
                <div class="mq-media-block__label"><i class="fas fa-paperclip"></i> Attachment</div>
                <a href="${BASE_URL}${esc(q.question_file)}" target="_blank" class="mq-btn mq-btn--primary mq-btn--sm">
                    <i class="fas fa-download"></i> Download File
                </a>
            </div>`;
        }
    }

    // Options (MCQ types)
    if (type === 'objective' || type === 'waec' || type === 'jamb') {
        const options = [
            { key: 'A', val: q.option_a },
            { key: 'B', val: q.option_b },
            { key: 'C', val: q.option_c },
            { key: 'D', val: q.option_d },
            { key: 'E', val: q.option_e },
        ].filter(o => o.val);

        html += `<div class="mq-slide__options">`;
        options.forEach(o => {
            const correct = o.key === q.correct_answer;
            html += `
            <div class="mq-option ${correct ? 'mq-option--correct' : ''}">
                <span class="mq-option__key">${o.key}</span>
                <span class="mq-option__text">${esc(o.val)}</span>
                ${correct ? '<span class="mq-option__tick"><i class="fas fa-check"></i></span>' : ''}
            </div>`;
        });
        html += `</div>`;
    }

    // Model answer for subjective
    if (type === 'subjective' && q.correct_answer) {
        html += `
        <div class="mq-slide__section">
            <div class="mq-slide__section-label"><i class="fas fa-check-double"></i> Model Answer / Marking Guide</div>
            <div class="mq-slide__answer-box">${esc(q.correct_answer)}</div>
        </div>`;
    }

    // Explanation (WAEC/JAMB)
    if ((type === 'waec' || type === 'jamb') && q.explanation) {
        html += `
        <div class="mq-slide__section">
            <div class="mq-slide__section-label"><i class="fas fa-lightbulb"></i> Explanation</div>
            <div class="mq-slide__explanation">${esc(q.explanation)}</div>
        </div>`;
    }

    // Marks
    if (q.marks) {
        html += `<div class="mq-slide__marks"><i class="fas fa-star"></i> ${q.marks} mark${q.marks != 1 ? 's' : ''}</div>`;
    }

    html += '</div>';
    return html;
}

// ============================================
// PDF VIEWER (PDF.js CDN)
// ============================================
let pdfDoc = null, pdfPageNum = 1, pdfScale = 1.2;

function initPdfViewer(filePath) {
    if (typeof pdfjsLib === 'undefined') return;
    const url = BASE_URL + filePath;
    pdfjsLib.getDocument(url).promise.then(pdf => {
        pdfDoc = pdf;
        pdfPageNum = 1;
        renderPdfPage();
    }).catch(err => {
        const c = document.getElementById('pdfPageInfo');
        if (c) c.textContent = 'Could not load PDF';
    });
}

function renderPdfPage() {
    if (!pdfDoc) return;
    pdfDoc.getPage(pdfPageNum).then(page => {
        const canvas  = document.getElementById('pdfCanvas');
        if (!canvas) return;
        const ctx     = canvas.getContext('2d');
        const vp      = page.getViewport({ scale: pdfScale });
        canvas.width  = vp.width;
        canvas.height = vp.height;
        page.render({ canvasContext: ctx, viewport: vp });
        const info = document.getElementById('pdfPageInfo');
        if (info) info.textContent = `Page ${pdfPageNum} of ${pdfDoc.numPages}`;
        const prev = document.getElementById('pdfPrevBtn');
        const next = document.getElementById('pdfNextBtn');
        if (prev) prev.disabled = pdfPageNum <= 1;
        if (next) next.disabled = pdfPageNum >= pdfDoc.numPages;
    });
}
function pdfPrevPage() { if (pdfPageNum > 1) { pdfPageNum--; renderPdfPage(); } }
function pdfNextPage() { if (pdfDoc && pdfPageNum < pdfDoc.numPages) { pdfPageNum++; renderPdfPage(); } }
function pdfZoomIn()   { pdfScale = Math.min(3, pdfScale + 0.2); renderPdfPage(); updateZoom(); }
function pdfZoomOut()  { pdfScale = Math.max(0.5, pdfScale - 0.2); renderPdfPage(); updateZoom(); }
function updateZoom()  { const l = document.getElementById('pdfZoomLabel'); if(l) l.textContent = Math.round(pdfScale * 100) + '%'; }

// ============================================
// LIGHTBOX
// ============================================
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('imageLightbox').style.display = 'flex';
}
function closeLightbox() { document.getElementById('imageLightbox').style.display = 'none'; }

// ============================================
// EDIT MODAL (unchanged existing logic)
// ============================================
function openEditModal(type, id) {
    // Get current filter parameters from URL
    const urlParams = new URLSearchParams(window.location.search);
    const filters = {
        tab: type,
        id: id,
        page: urlParams.get('page') || 1,
        filter_subject: urlParams.get('filter_subject') || '',
        filter_topic: urlParams.get('filter_topic') || '',
        filter_year: urlParams.get('filter_year') || '',
        filter_difficulty: urlParams.get('filter_difficulty') || '',
        search: urlParams.get('search') || ''
    };
    
    const queryString = new URLSearchParams(filters).toString();
    window.location.href = `edit_question.php?${queryString}`;
}

// ============================================
// KEYBOARD NAVIGATION
// ============================================
document.addEventListener('keydown', e => {
    if (document.getElementById('slideModal').style.display !== 'flex') return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown')  navigate(1);
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')    navigate(-1);
    if (e.key === 'Escape') closeSlideModal();
});

// ============================================
// UTILS
// ============================================
function esc(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}
function ucFirst(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}
</script>

<!-- PDF.js CDN (loaded lazily) -->
<script>
(function() {
    const hasPdf = <?php echo json_encode(!empty(array_filter($questions, fn($q) => !empty($q['question_file']) && str_ends_with(strtolower($q['question_file']), '.pdf')))); ?>;
    if (hasPdf) {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        s.onload = () => { pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'; };
        document.head.appendChild(s);
    }
})();
</script>

<style>
/* ====================================
   MSV Manage Questions — Full Redesign
   Palette: Navy #1c3877 · Red #e23639 · White · Greys
   ==================================== */

:root {
    --mq-navy:    #1c3877;
    --mq-navy-lt: #2a4a9a;
    --mq-red:     #e23639;
    --mq-green:   #16a34a;
    --mq-amber:   #d97706;
    --mq-teal:    #0891b2;
    --mq-purple:  #7c3aed;
    --mq-orange:  #ea580c;
    --mq-bg:      #f0f4fb;
    --mq-surface: #ffffff;
    --mq-border:  #e2e8f0;
    --mq-text:    #1e293b;
    --mq-muted:   #64748b;
    --mq-radius:  12px;
    --mq-shadow:  0 1px 4px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
}

/* Alert */
.mq-alert { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:var(--mq-radius); margin-bottom:20px; font-size:.875rem; }
.mq-alert--success { background:#dcfce7; color:#166534; border-left:4px solid var(--mq-green); }
.mq-alert--error   { background:#fee2e2; color:#991b1b; border-left:4px solid var(--mq-red); }
.mq-alert__close   { margin-left:auto; background:none; border:none; font-size:18px; cursor:pointer; opacity:.6; }
.mq-alert__close:hover { opacity:1; }

/* Page header */
.mq-page-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:28px; }
.mq-page-header__title { margin:0; font-size:1.5rem; font-weight:700; color:var(--mq-navy); }
.mq-page-header__sub   { margin:4px 0 0; color:var(--mq-muted); font-size:.875rem; }

/* Section labels */
.mq-section-label { display:flex; align-items:center; gap:10px; margin-bottom:14px; margin-top:28px; }
.mq-section-label span { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--mq-muted); background:var(--mq-bg); padding:0 10px; white-space:nowrap; }
.mq-section-label::before, .mq-section-label::after { content:''; flex:1; height:1px; background:var(--mq-border); }

/* Type cards */
.mq-type-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px,1fr)); gap:12px; margin-bottom:4px; }
.mq-type-card { display:flex; align-items:center; gap:12px; padding:14px 16px; background:var(--mq-surface); border:2px solid var(--mq-border); border-radius:var(--mq-radius); text-decoration:none; color:var(--mq-text); transition:all .18s ease; cursor:pointer; }
.mq-type-card:hover { border-color:var(--mq-navy); transform:translateY(-2px); box-shadow:var(--mq-shadow); }
.mq-type-card--active { border-color:var(--mq-navy); background:var(--mq-navy); color:#fff !important; box-shadow:0 4px 20px rgba(28,56,119,.25); }
.mq-type-card__icon { width:36px; height:36px; display:flex; align-items:center; justify-content:center; border-radius:8px; font-size:1rem; flex-shrink:0; }
.mq-type-card--blue   .mq-type-card__icon { background:#dbeafe; color:#1d4ed8; }
.mq-type-card--purple .mq-type-card__icon { background:#ede9fe; color:var(--mq-purple); }
.mq-type-card--teal   .mq-type-card__icon { background:#cffafe; color:var(--mq-teal); }
.mq-type-card--green  .mq-type-card__icon { background:#dcfce7; color:var(--mq-green); }
.mq-type-card--orange .mq-type-card__icon { background:#ffedd5; color:var(--mq-orange); }
.mq-type-card--active .mq-type-card__icon { background:rgba(255,255,255,.2); color:#fff; }
.mq-type-card__body { flex:1; min-width:0; }
.mq-type-card__body strong { display:block; font-size:.9rem; font-weight:700; }
.mq-type-card__body span   { display:block; font-size:.7rem; opacity:.65; margin-top:1px; }
.mq-type-card--active .mq-type-card__body span { opacity:.8; }
.mq-type-card__count { font-size:.75rem; font-weight:700; background:rgba(0,0,0,.08); padding:3px 8px; border-radius:20px; white-space:nowrap; }
.mq-type-card--active .mq-type-card__count { background:rgba(255,255,255,.25); color:#fff; }

/* Filter bar */
.mq-filter-bar { background:var(--mq-surface); border-radius:var(--mq-radius); padding:20px; box-shadow:var(--mq-shadow); margin-bottom:24px; }
.mq-filter-bar__grid { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
.mq-filter-group { flex:1; min-width:140px; }
.mq-filter-group--search { flex:2; min-width:220px; }
.mq-filter-group--clear { flex:0; min-width:auto; }
.mq-filter-label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--mq-muted); margin-bottom:6px; }
.mq-filter-label i { margin-right:4px; }
.mq-select { width:100%; padding:9px 12px; border:1.5px solid var(--mq-border); border-radius:8px; font-family:inherit; font-size:.85rem; color:var(--mq-text); background:var(--mq-surface); transition:border-color .15s; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:28px; }
.mq-select:focus { outline:none; border-color:var(--mq-navy); }
.mq-select:disabled { opacity:.45; cursor:not-allowed; }
.mq-search-wrap { display:flex; gap:6px; }
.mq-search-input { flex:1; background-image:none; padding-right:12px; }

/* Buttons */
.mq-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:8px; font-size:.85rem; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .15s ease; white-space:nowrap; }
.mq-btn--primary { background:var(--mq-navy); color:#fff; }
.mq-btn--primary:hover { background:var(--mq-navy-lt); }
.mq-btn--ghost { background:transparent; border:1.5px solid var(--mq-border); color:var(--mq-text); }
.mq-btn--ghost:hover { border-color:var(--mq-navy); color:var(--mq-navy); }
.mq-btn--danger { background:var(--mq-red); color:#fff; }
.mq-btn--sm  { padding:7px 14px; font-size:.8rem; }
.mq-btn--xs  { padding:4px 10px; font-size:.72rem; }
.mq-btn--search { background:var(--mq-navy); color:#fff; border-radius:8px; padding:9px 14px; border:none; cursor:pointer; }

/* Tags / badges */
.mq-tag { display:inline-flex; align-items:center; gap:4px; background:#e2e8f0; color:#475569; padding:3px 9px; border-radius:20px; font-size:.72rem; font-weight:600; }
.mq-tag--media  { background:#e0f2fe; color:#0369a1; }
.mq-tag--year   { background:#fef3c7; color:#92400e; }
.mq-diff { display:inline-flex; align-items:center; padding:3px 9px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:capitalize; }
.mq-diff--easy   { background:#dcfce7; color:#15803d; }
.mq-diff--medium { background:#fef3c7; color:#b45309; }
.mq-diff--hard   { background:#fee2e2; color:#b91c1c; }

/* Results header */
.mq-results-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
.mq-results-header__info { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
.mq-results-count { font-size:.9rem; font-weight:700; color:var(--mq-text); }

/* Question cards grid */
.mq-cards-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:14px; margin-bottom:24px; }
.mq-q-card { background:var(--mq-surface); border:1.5px solid var(--mq-border); border-radius:var(--mq-radius); padding:16px; cursor:pointer; transition:all .18s ease; }
.mq-q-card:hover { border-color:var(--mq-navy); box-shadow:var(--mq-shadow); transform:translateY(-2px); }
.mq-q-card__top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:10px; }
.mq-q-card__num { font-size:.72rem; font-weight:700; color:var(--mq-muted); background:#f1f5f9; padding:2px 8px; border-radius:20px; }
.mq-q-card__badges { display:flex; flex-wrap:wrap; gap:4px; }
.mq-q-card__text { font-size:.85rem; color:var(--mq-text); line-height:1.5; margin-bottom:12px; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
.mq-q-card__footer { display:flex; align-items:center; flex-wrap:wrap; gap:6px; padding-top:10px; border-top:1px solid var(--mq-border); }
.mq-q-card__subject { font-size:.72rem; color:var(--mq-muted); display:flex; align-items:center; gap:4px; }
.mq-q-card__topic   { font-size:.72rem; color:var(--mq-muted); background:#f8fafc; padding:2px 8px; border-radius:20px; }

/* Empty state */
.mq-empty { text-align:center; padding:60px 20px; color:var(--mq-muted); }
.mq-empty i { font-size:3rem; margin-bottom:16px; display:block; opacity:.3; }
.mq-empty p { margin-bottom:20px; }

/* Pagination */
.mq-pagination { display:flex; justify-content:center; gap:6px; flex-wrap:wrap; }
.mq-page-btn { padding:7px 13px; border:1.5px solid var(--mq-border); border-radius:8px; text-decoration:none; color:var(--mq-text); font-size:.82rem; font-weight:600; cursor:pointer; background:var(--mq-surface); transition:all .15s; }
.mq-page-btn:hover:not(:disabled) { border-color:var(--mq-navy); color:var(--mq-navy); }
.mq-page-btn--active { background:var(--mq-navy); color:#fff; border-color:var(--mq-navy); }
.mq-page-btn:disabled { opacity:.35; cursor:not-allowed; }

/* ================================
   SLIDE MODAL
   ================================ */
.mq-modal { position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; }
.mq-modal__backdrop { position:absolute; inset:0; background:rgba(15,23,42,.65); backdrop-filter:blur(4px); }
.mq-modal__panel { position:relative; width:min(680px, 96vw); max-height:90vh; background:var(--mq-surface); border-radius:16px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 80px rgba(0,0,0,.35); }

/* Modal header */
.mq-modal__header { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid var(--mq-border); background:var(--mq-surface); flex-shrink:0; }
.mq-modal__header-left { display:flex; align-items:center; gap:10px; }
.mq-modal__header-right { display:flex; align-items:center; gap:6px; }
.mq-modal__type-badge { font-size:.68rem; font-weight:800; letter-spacing:.08em; padding:3px 10px; border-radius:20px; }
.mq-modal__type-badge--objective  { background:#dbeafe; color:#1d4ed8; }
.mq-modal__type-badge--subjective { background:#ede9fe; color:var(--mq-purple); }
.mq-modal__type-badge--theory     { background:#cffafe; color:var(--mq-teal); }
.mq-modal__type-badge--waec       { background:#dcfce7; color:var(--mq-green); }
.mq-modal__type-badge--jamb       { background:#ffedd5; color:var(--mq-orange); }
.mq-modal__counter { font-size:.8rem; color:var(--mq-muted); font-weight:600; }
.mq-modal__close { background:none; border:none; font-size:1.25rem; cursor:pointer; color:var(--mq-muted); padding:4px 8px; border-radius:6px; transition:all .15s; }
.mq-modal__close:hover { background:#fee2e2; color:var(--mq-red); }
.mq-modal__action-btn { display:flex; align-items:center; justify-content:center; width:32px; height:32px; border:1.5px solid var(--mq-border); border-radius:8px; background:none; cursor:pointer; font-size:.82rem; transition:all .15s; }
.mq-modal__action-btn--edit:hover   { border-color:#f59e0b; color:#b45309; background:#fef3c7; }
.mq-modal__action-btn--delete:hover { border-color:var(--mq-red); color:var(--mq-red); background:#fee2e2; }

/* Slide area */
.mq-modal__slide-wrap { flex:1; overflow-y:auto; padding:0; }
.mq-modal__slide { padding:22px; }

/* Modal footer */
.mq-modal__footer { display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-top:1px solid var(--mq-border); background:#f8fafc; flex-shrink:0; }
.mq-nav-btn { display:flex; align-items:center; gap:7px; padding:8px 16px; border:1.5px solid var(--mq-border); border-radius:8px; background:var(--mq-surface); cursor:pointer; font-size:.82rem; font-weight:600; color:var(--mq-text); transition:all .15s; }
.mq-nav-btn:hover:not(:disabled) { border-color:var(--mq-navy); color:var(--mq-navy); background:#eef2ff; }
.mq-nav-btn:disabled { opacity:.3; cursor:not-allowed; }
.mq-nav-btn--next { flex-direction:row; }
.mq-modal__dots { display:flex; gap:5px; flex-wrap:wrap; justify-content:center; max-width:220px; }
.mq-dot { width:8px; height:8px; border-radius:50%; background:var(--mq-border); cursor:pointer; transition:all .15s; }
.mq-dot--active { background:var(--mq-navy); transform:scale(1.25); }
.mq-dot:hover:not(.mq-dot--active) { background:#94a3b8; }

/* Slide content */
.mq-slide-content { min-height: 200px; }
.mq-slide__meta { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:16px; }
.mq-slide__subject { font-size:.78rem; font-weight:700; color:var(--mq-navy); display:flex; align-items:center; gap:4px; background:#eef2ff; padding:4px 10px; border-radius:20px; }
.mq-slide__topic   { font-size:.78rem; color:var(--mq-muted); display:flex; align-items:center; gap:4px; background:#f1f5f9; padding:4px 10px; border-radius:20px; }
.mq-slide__year    { font-size:.78rem; color:#92400e; display:flex; align-items:center; gap:4px; background:#fef3c7; padding:4px 10px; border-radius:20px; }
.mq-slide__question-text { font-size:.95rem; line-height:1.7; color:var(--mq-text); margin-bottom:20px; white-space:pre-wrap; }

/* Options */
.mq-slide__options { display:flex; flex-direction:column; gap:8px; margin-bottom:20px; }
.mq-option { display:flex; align-items:flex-start; gap:12px; padding:11px 14px; border:1.5px solid var(--mq-border); border-radius:10px; background:#fafafa; transition:all .15s; }
.mq-option--correct { background:#f0fdf4; border-color:#86efac; }
.mq-option__key  { width:26px; height:26px; border-radius:50%; background:var(--mq-border); display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:800; flex-shrink:0; margin-top:1px; }
.mq-option--correct .mq-option__key { background:var(--mq-green); color:#fff; }
.mq-option__text { flex:1; font-size:.88rem; line-height:1.5; color:var(--mq-text); }
.mq-option__tick { color:var(--mq-green); font-size:.85rem; flex-shrink:0; margin-top:3px; }

/* Answer/explanation sections */
.mq-slide__section { margin-bottom:20px; }
.mq-slide__section-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--mq-muted); margin-bottom:8px; display:flex; align-items:center; gap:5px; }
.mq-slide__answer-box  { background:#f0fdf4; border:1.5px solid #86efac; border-radius:10px; padding:14px; font-size:.88rem; line-height:1.6; white-space:pre-wrap; color:var(--mq-text); }
.mq-slide__explanation { background:#eff6ff; border:1.5px solid #93c5fd; border-radius:10px; padding:14px; font-size:.88rem; line-height:1.6; white-space:pre-wrap; color:var(--mq-text); }
.mq-slide__marks { display:inline-flex; align-items:center; gap:6px; font-size:.78rem; font-weight:700; color:#92400e; background:#fef3c7; padding:5px 12px; border-radius:20px; }

/* Media blocks */
.mq-media-block { margin-bottom:20px; border:1.5px solid var(--mq-border); border-radius:10px; overflow:hidden; }
.mq-media-block__label { display:flex; align-items:center; gap:7px; padding:10px 14px; background:#f8fafc; font-size:.78rem; font-weight:700; color:var(--mq-muted); border-bottom:1px solid var(--mq-border); }

/* Image viewer */
.mq-img-container { position:relative; cursor:zoom-in; overflow:hidden; max-height:340px; display:flex; align-items:center; justify-content:center; background:#000; }
.mq-slide__img { max-width:100%; max-height:340px; object-fit:contain; display:block; }
.mq-img-zoom-hint { position:absolute; bottom:10px; right:10px; background:rgba(0,0,0,.55); color:#fff; padding:4px 10px; border-radius:20px; font-size:.72rem; display:flex; align-items:center; gap:5px; pointer-events:none; }

/* Image lightbox */
.mq-lightbox { position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,.92); display:flex; align-items:center; justify-content:center; cursor:zoom-out; }
.mq-lightbox img { max-width:95vw; max-height:95vh; object-fit:contain; border-radius:8px; }
.mq-lightbox__close { position:fixed; top:16px; right:20px; background:rgba(255,255,255,.15); border:none; color:#fff; font-size:1.5rem; width:40px; height:40px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:2001; transition:background .15s; }
.mq-lightbox__close:hover { background:rgba(255,255,255,.3); }

/* PDF viewer */
.mq-pdf-viewer { display:flex; flex-direction:column; align-items:center; background:#525659; padding:10px; min-height:200px; }
.mq-pdf-toolbar { display:flex; align-items:center; gap:8px; background:#3c3f41; padding:6px 12px; border-radius:8px; margin-bottom:10px; flex-wrap:wrap; justify-content:center; }
.mq-pdf-toolbar span { font-size:.78rem; color:#ccc; min-width:60px; text-align:center; }
.mq-pdf-canvas { max-width:100%; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,.4); }

/* ================================
   RESPONSIVE
   ================================ */
@media (max-width: 640px) {
    .mq-type-grid { grid-template-columns:repeat(2, 1fr); }
    .mq-type-card { padding:12px; }
    .mq-type-card__body span { display:none; }
    .mq-cards-grid { grid-template-columns:1fr; }
    .mq-modal__panel { max-height:96vh; border-radius:16px 16px 0 0; margin-top:auto; }
    .mq-modal { align-items:flex-end; }
    .mq-nav-btn span { display:none; }
    .mq-filter-bar__grid { gap:10px; }
    .mq-filter-group { min-width:100%; }
    .mq-filter-group--clear { min-width:100%; }
}
@media (max-width: 400px) {
    .mq-type-grid { grid-template-columns:1fr; }
}
</style>

<?php include 'includes/footer.php'; ?>
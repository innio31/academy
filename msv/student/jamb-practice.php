<?php
// msv/student/jamb-practice.php — Coming Soon page
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: /msv/login.php");
    exit();
}

$school_name     = SCHOOL_NAME;
$primary_color   = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_name    = $_SESSION['user_name'] ?? 'Student';
$current_page    = basename($_SERVER['PHP_SELF']);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JAMB Practice — Coming Soon | <?= htmlspecialchars($school_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  :root {
    --primary:   <?= htmlspecialchars($primary_color ?? '#1a6b3c') ?>;
    --bg:        #05080f;
    --surface:   #0c1120;
    --card:      #101828;
    --border:    rgba(255,255,255,.07);
    --text:      #e2e8f0;
    --muted:     #64748b;
    --blue:      #3b82f6;
    --blue-glow: rgba(59,130,246,.18);
    --amber:     #f59e0b;
    --emerald:   #10b981;
    --purple:    #8b5cf6;
    --rose:      #f43f5e;
    --cyan:      #06b6d4;
    --radius:    14px;
    --mono:      'JetBrains Mono', monospace;
    --sans:      'Plus Jakarta Sans', sans-serif;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }

  /* Grid background */
  body::after {
    content: '';
    position: fixed; inset: 0;
    background-image:
      linear-gradient(rgba(59,130,246,.04) 1px, transparent 1px),
      linear-gradient(90deg, rgba(59,130,246,.04) 1px, transparent 1px);
    background-size: 48px 48px;
    pointer-events: none; z-index: 0;
  }

  /* Glow blobs */
  .blob {
    position: fixed; border-radius: 50%;
    filter: blur(130px); opacity: .14;
    pointer-events: none; z-index: 0;
  }
  .blob-1 { width: 700px; height: 700px; background: var(--blue);    top: -250px; left: -200px; }
  .blob-2 { width: 450px; height: 450px; background: var(--purple);  bottom: -150px; right: -100px; }
  .blob-3 { width: 300px; height: 300px; background: var(--emerald); top: 50%; right: 10%; opacity: .08; }

  .layout { display: flex; min-height: 100vh; position: relative; z-index: 1; }

  .main {
    flex: 1;
    padding: 52px 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }

  /* ── Top label strip ── */
  .label-strip {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 32px;
    animation: fadeDown .5s ease both;
  }
  .label-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    border-radius: 100px;
    font-family: var(--mono);
    font-size: .68rem;
    letter-spacing: .1em;
    text-transform: uppercase;
  }
  .chip-blue   { background: rgba(59,130,246,.12);  border: 1px solid rgba(59,130,246,.25);  color: var(--blue); }
  .chip-amber  { background: rgba(245,158,11,.12);  border: 1px solid rgba(245,158,11,.25);  color: var(--amber); }
  .chip-blink .dot {
    width: 5px; height: 5px; background: currentColor;
    border-radius: 50%; animation: blink 1.6s infinite;
  }

  /* ── Hero card ── */
  .hero-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 56px 52px;
    max-width: 800px;
    width: 100%;
    text-align: center;
    box-shadow: 0 48px 96px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.05);
    animation: fadeUp .65s ease .1s both;
    position: relative;
    overflow: hidden;
  }
  /* Top shimmer line */
  .hero-card::before {
    content: '';
    position: absolute; top: 0; left: 10%; right: 10%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--blue), var(--purple), transparent);
  }

  /* ── Icon cluster ── */
  .icon-cluster {
    position: relative;
    width: 96px; height: 96px;
    margin: 0 auto 32px;
  }
  .icon-main {
    width: 96px; height: 96px;
    background: linear-gradient(135deg, #1d4ed8, #6d28d9);
    border-radius: 26px;
    display: flex; align-items: center; justify-content: center;
    font-size: 2.4rem;
    box-shadow: 0 24px 48px rgba(59,130,246,.35);
    animation: iconIn .8s cubic-bezier(.34,1.56,.64,1) .25s both;
  }
  .icon-badge {
    position: absolute;
    bottom: -6px; right: -8px;
    background: var(--amber);
    color: #000;
    font-family: var(--mono);
    font-size: .6rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 100px;
    border: 2px solid var(--card);
    white-space: nowrap;
  }

  h1 {
    font-size: clamp(1.9rem, 4.5vw, 2.9rem);
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 14px;
    background: linear-gradient(135deg, #fff 20%, var(--blue) 60%, var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .subtitle {
    font-size: .95rem;
    color: var(--muted);
    line-height: 1.7;
    max-width: 520px;
    margin: 0 auto 36px;
  }

  /* ── JAMB score banner ── */
  .score-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.2);
    border-radius: 12px;
    padding: 12px 20px;
    margin-bottom: 36px;
    font-size: .82rem;
    color: var(--amber);
    font-family: var(--mono);
  }
  .score-banner i { font-size: 1rem; }

  /* ── Feature grid ── */
  .features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 36px;
    text-align: left;
  }

  .feature {
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    display: flex; flex-direction: column; gap: 8px;
    transition: border-color .2s, background .2s, transform .2s;
    animation: fadeUp .55s ease both;
  }
  .feature:nth-child(1) { animation-delay: .15s; }
  .feature:nth-child(2) { animation-delay: .22s; }
  .feature:nth-child(3) { animation-delay: .29s; }
  .feature:nth-child(4) { animation-delay: .36s; }
  .feature:nth-child(5) { animation-delay: .43s; }
  .feature:nth-child(6) { animation-delay: .50s; }
  .feature:nth-child(7) { animation-delay: .57s; }
  .feature:hover {
    border-color: rgba(59,130,246,.3);
    background: rgba(59,130,246,.05);
    transform: translateY(-2px);
  }
  .feature-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; margin-bottom: 2px;
  }
  .feature h3 { font-size: .84rem; font-weight: 600; color: var(--text); }
  .feature p  { font-size: .74rem; color: var(--muted); line-height: 1.55; }

  .fi-blue    { background: rgba(59,130,246,.15);  color: var(--blue); }
  .fi-amber   { background: rgba(245,158,11,.15);  color: var(--amber); }
  .fi-emerald { background: rgba(16,185,129,.15);  color: var(--emerald); }
  .fi-purple  { background: rgba(139,92,246,.15);  color: var(--purple); }
  .fi-rose    { background: rgba(244,63,94,.15);   color: var(--rose); }
  .fi-cyan    { background: rgba(6,182,212,.15);   color: var(--cyan); }
  .fi-muted   { background: rgba(255,255,255,.06); color: #94a3b8; }

  /* ── Progress ── */
  .progress-wrap { margin-bottom: 32px; text-align: left; }
  .progress-meta {
    display: flex; justify-content: space-between;
    font-size: .72rem; color: var(--muted);
    font-family: var(--mono); margin-bottom: 8px;
  }
  .progress-bar {
    height: 5px; background: rgba(255,255,255,.07);
    border-radius: 100px; overflow: hidden;
  }
  .progress-fill {
    height: 100%; width: 40%;
    background: linear-gradient(90deg, var(--blue), var(--purple));
    border-radius: 100px;
    animation: fillBar 2.2s ease 1.2s both;
  }

  /* ── Subject pills ── */
  .subjects-preview {
    display: flex; flex-wrap: wrap; gap: 8px;
    justify-content: center;
    margin-bottom: 36px;
  }
  .pill {
    font-family: var(--mono);
    font-size: .68rem;
    padding: 4px 12px;
    border-radius: 100px;
    border: 1px solid var(--border);
    color: var(--muted);
    background: rgba(255,255,255,.03);
    transition: all .2s;
    animation: fadeUp .4s ease both;
  }
  .pill.compulsory {
    border-color: rgba(245,158,11,.3);
    color: var(--amber);
    background: rgba(245,158,11,.07);
  }
  .pill:hover { border-color: rgba(59,130,246,.4); color: var(--blue); }

  /* ── CTA ── */
  .cta-group {
    display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
  }
  .btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 26px;
    border-radius: 12px;
    font-family: var(--sans);
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .22s;
    border: none;
  }
  .btn-primary {
    background: linear-gradient(135deg, #1d4ed8, #7c3aed);
    color: #fff;
    box-shadow: 0 8px 24px rgba(59,130,246,.3);
  }
  .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 14px 36px rgba(59,130,246,.45); }
  .btn-ghost {
    background: rgba(255,255,255,.05);
    color: var(--muted);
    border: 1px solid var(--border);
  }
  .btn-ghost:hover { background: rgba(255,255,255,.09); color: var(--text); }

  /* ── Stats strip ── */
  .stats {
    display: flex; gap: 48px; justify-content: center;
    margin-top: 48px;
    padding-top: 32px;
    border-top: 1px solid var(--border);
    animation: fadeUp .6s ease .9s both;
    flex-wrap: wrap;
  }
  .stat { text-align: center; }
  .stat-num {
    font-family: var(--mono);
    font-size: 1.65rem;
    font-weight: 700;
    display: block;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .stat-label { font-size: .72rem; color: var(--muted); margin-top: 3px; letter-spacing: .02em; }

  /* ── Keyframes ── */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  @keyframes fadeDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  @keyframes iconIn {
    from { opacity: 0; transform: scale(.3) rotate(20deg); }
    to   { opacity: 1; transform: scale(1) rotate(0deg); }
  }
  @keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: .2; } }
  @keyframes fillBar {
    from { width: 0; }
    to   { width: 40%; }
  }

  @media (max-width: 640px) {
    .main { padding: 32px 16px; }
    .hero-card { padding: 36px 22px; }
    .features { grid-template-columns: 1fr 1fr; }
    .stats { gap: 24px; }
    .score-banner { flex-direction: column; gap: 4px; font-size: .76rem; }
  }
</style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>

<div class="layout">
  <!-- Sidebar -->
  <?php include 'includes/student_sidebar.php'; ?>

  <!-- Main Content -->
  <main class="main">

    <div class="label-strip">
      <span class="label-chip chip-blue chip-blink">
        <span class="dot"></span>
        Under Construction
      </span>
      <span class="label-chip chip-amber">JAMB CBT</span>
    </div>

    <div class="hero-card">

      <div class="icon-cluster">
        <div class="icon-main"><i class="fa-solid fa-pen-to-square"></i></div>
        <span class="icon-badge">CBT MODE</span>
      </div>

      <h1>JAMB Practice Centre</h1>

      <p class="subtitle">
        Full JAMB CBT simulation — year papers, topical drilling, and the
        real 4-subject aggregate experience — coming to your portal soon.
      </p>

      <!-- JAMB scoring note -->
      <div class="score-banner">
        <i class="fa-solid fa-circle-info"></i>
        4 subjects · 180 questions · 120 minutes · scored out of 400
      </div>

      <!-- Feature grid -->
      <div class="features">
        <div class="feature">
          <div class="feature-icon fi-amber"><i class="fa-solid fa-calendar-days"></i></div>
          <h3>Year-Based Papers</h3>
          <p>Attempt any past JAMB paper by year — single subject or a full 4-subject sitting.</p>
        </div>
        <div class="feature">
          <div class="feature-icon fi-blue"><i class="fa-solid fa-layer-group"></i></div>
          <h3>Topical Practice</h3>
          <p>Drill a specific topic across multiple years for deep mastery of each concept.</p>
        </div>
        <div class="feature">
          <div class="feature-icon fi-purple"><i class="fa-solid fa-display"></i></div>
          <h3>Full CBT Simulation</h3>
          <p>Replicate the real JAMB CBT — all 4 subjects, navigation, timer, and aggregate score.</p>
        </div>
        <div class="feature">
          <div class="feature-icon fi-emerald"><i class="fa-solid fa-sliders"></i></div>
          <h3>Standard & Custom Modes</h3>
          <p>Use JAMB defaults or set your own question count and time per session.</p>
        </div>
        <div class="feature">
          <div class="feature-icon fi-rose"><i class="fa-solid fa-chart-line"></i></div>
          <h3>Performance Dashboard</h3>
          <p>Track scores per subject and topic, see aggregate trends, and spot weak areas.</p>
        </div>
        <div class="feature">
          <div class="feature-icon fi-cyan"><i class="fa-solid fa-clock-rotate-left"></i></div>
          <h3>Practice History</h3>
          <p>All sessions are saved with full review — revisit every answer and explanation.</p>
        </div>
        <div class="feature">
          <div class="feature-icon fi-muted"><i class="fa-solid fa-bookmark"></i></div>
          <h3>Bookmarks & Notes</h3>
          <p>Flag tricky questions mid-session and attach personal notes for later review.</p>
        </div>
      </div>

      <!-- Subject pills preview -->
      <div class="subjects-preview">
        <span class="pill compulsory"><i class="fa-solid fa-lock" style="font-size:.6rem;"></i> Use of English</span>
        <span class="pill">Mathematics</span>
        <span class="pill">Physics</span>
        <span class="pill">Chemistry</span>
        <span class="pill">Biology</span>
        <span class="pill">Economics</span>
        <span class="pill">Government</span>
        <span class="pill">Literature</span>
        <span class="pill">Geography</span>
        <span class="pill">+ more</span>
      </div>

      <!-- Build progress -->
      <div class="progress-wrap">
        <div class="progress-meta">
          <span>Build progress</span>
          <span>40%</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill"></div>
        </div>
      </div>

      <!-- CTAs -->
      <div class="cta-group">
        <a href="exams.php" class="btn btn-primary">
          <i class="fa-solid fa-arrow-left"></i>
          Back to Exams
        </a>
        <a href="waec-practice.php" class="btn btn-ghost">
          <i class="fa-solid fa-graduation-cap"></i>
          WAEC Practice
        </a>
        <a href="index.php" class="btn btn-ghost">
          <i class="fa-solid fa-house"></i>
          Dashboard
        </a>
      </div>

    </div><!-- /.hero-card -->

    <!-- Stats strip -->
    <div class="stats">
      <div class="stat">
        <span class="stat-num">20+</span>
        <span class="stat-label">JAMB Subjects</span>
      </div>
      <div class="stat">
        <span class="stat-num">25+</span>
        <span class="stat-label">Years of Papers</span>
      </div>
      <div class="stat">
        <span class="stat-num">8,000+</span>
        <span class="stat-label">Questions Being Loaded</span>
      </div>
      <div class="stat">
        <span class="stat-num">3</span>
        <span class="stat-label">Practice Modes</span>
      </div>
    </div>

  </main>
</div>

</body>
</html>
<?php
echo ob_get_clean();
?>
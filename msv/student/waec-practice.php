<?php
// msv/student/waec-practice.php — Coming Soon page
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
  header("Location: /msv/login.php");
  exit();
}

$school_name    = SCHOOL_NAME;
$primary_color  = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;
$student_name   = $_SESSION['user_name'] ?? 'Student';
$current_page   = basename($_SERVER['PHP_SELF']);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WAEC Practice — Coming Soon | <?= htmlspecialchars($school_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --primary: <?= htmlspecialchars($primary_color ?? '#1a6b3c') ?>;
      --secondary: <?= htmlspecialchars($secondary_color ?? '#e8f5ef') ?>;
      --bg: #0a0f0d;
      --surface: #111710;
      --card: #172118;
      --border: rgba(255, 255, 255, .07);
      --text: #e8f0ea;
      --muted: #7a9982;
      --accent: #4ade80;
      --gold: #fbbf24;
      --radius: 16px;
      --mono: 'Space Mono', monospace;
      --sans: 'Sora', sans-serif;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--sans);
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ── Noise texture overlay ── */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
      pointer-events: none;
      z-index: 0;
    }

    /* ── Glow blobs ── */
    .blob {
      position: fixed;
      border-radius: 50%;
      filter: blur(120px);
      opacity: .18;
      pointer-events: none;
      z-index: 0;
    }

    .blob-1 {
      width: 600px;
      height: 600px;
      background: var(--primary);
      top: -200px;
      right: -150px;
    }

    .blob-2 {
      width: 400px;
      height: 400px;
      background: var(--accent);
      bottom: -100px;
      left: -100px;
    }

    /* ── Sidebar wrapper ── */
    .layout {
      display: flex;
      min-height: 100vh;
      position: relative;
      z-index: 1;
    }

    /* ── Main area ── */
    .main {
      flex: 1;
      padding: 48px 40px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0;
    }

    /* ── Hero badge ── */
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(74, 222, 128, .12);
      border: 1px solid rgba(74, 222, 128, .25);
      color: var(--accent);
      font-family: var(--mono);
      font-size: .7rem;
      letter-spacing: .12em;
      text-transform: uppercase;
      padding: 6px 14px;
      border-radius: 100px;
      margin-bottom: 28px;
      animation: fadeDown .6s ease both;
    }

    .badge .dot {
      width: 6px;
      height: 6px;
      background: var(--accent);
      border-radius: 50%;
      animation: pulse 2s infinite;
    }

    /* ── Central card ── */
    .card-hero {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 52px 48px;
      max-width: 760px;
      width: 100%;
      text-align: center;
      box-shadow: 0 40px 80px rgba(0, 0, 0, .4);
      animation: fadeUp .7s ease .1s both;
      position: relative;
      overflow: hidden;
    }

    .card-hero::before {
      content: '';
      position: absolute;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 60%;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--accent), transparent);
    }

    .hero-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, var(--primary), #2d9e5f);
      border-radius: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      margin: 0 auto 28px;
      box-shadow: 0 20px 40px rgba(26, 107, 60, .4);
      animation: iconBounce .8s cubic-bezier(.68, -.55, .27, 1.55) .3s both;
    }

    h1 {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 800;
      line-height: 1.1;
      margin-bottom: 16px;
      background: linear-gradient(135deg, #fff 30%, var(--accent));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    h1 span {
      display: block;
      font-size: .5em;
      font-weight: 400;
      color: var(--muted);
      -webkit-text-fill-color: var(--muted);
    }

    .tagline {
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.7;
      max-width: 500px;
      margin: 0 auto 40px;
    }

    /* ── Feature preview grid ── */
    .features {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 14px;
      margin-bottom: 40px;
      text-align: left;
    }

    .feature {
      background: rgba(255, 255, 255, .04);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      transition: border-color .25s, background .25s;
      animation: fadeUp .6s ease both;
    }

    .feature:nth-child(1) {
      animation-delay: .2s;
    }

    .feature:nth-child(2) {
      animation-delay: .3s;
    }

    .feature:nth-child(3) {
      animation-delay: .4s;
    }

    .feature:nth-child(4) {
      animation-delay: .5s;
    }

    .feature:nth-child(5) {
      animation-delay: .6s;
    }

    .feature:nth-child(6) {
      animation-delay: .7s;
    }

    .feature:hover {
      border-color: rgba(74, 222, 128, .3);
      background: rgba(74, 222, 128, .05);
    }

    .feature-icon {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .95rem;
      margin-bottom: 4px;
    }

    .feature h3 {
      font-size: .85rem;
      font-weight: 600;
      color: var(--text);
    }

    .feature p {
      font-size: .75rem;
      color: var(--muted);
      line-height: 1.5;
    }

    /* Feature icon colours */
    .fi-1 {
      background: rgba(251, 191, 36, .15);
      color: var(--gold);
    }

    .fi-2 {
      background: rgba(74, 222, 128, .15);
      color: var(--accent);
    }

    .fi-3 {
      background: rgba(129, 140, 248, .15);
      color: #818cf8;
    }

    .fi-4 {
      background: rgba(251, 113, 133, .15);
      color: #fb7185;
    }

    .fi-5 {
      background: rgba(34, 211, 238, .15);
      color: #22d3ee;
    }

    .fi-6 {
      background: rgba(167, 243, 208, .15);
      color: #a7f3d0;
    }

    /* ── Progress bar ── */
    .progress-wrap {
      margin-bottom: 32px;
      text-align: left;
    }

    .progress-label {
      display: flex;
      justify-content: space-between;
      font-size: .75rem;
      color: var(--muted);
      font-family: var(--mono);
      margin-bottom: 8px;
    }

    .progress-bar {
      height: 6px;
      background: rgba(255, 255, 255, .08);
      border-radius: 100px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      width: 35%;
      background: linear-gradient(90deg, var(--primary), var(--accent));
      border-radius: 100px;
      animation: fillBar 2s ease 1s both;
    }

    /* ── CTA ── */
    .cta-group {
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: 12px;
      font-family: var(--sans);
      font-size: .875rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all .25s;
      border: none;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary), #2d9e5f);
      color: #fff;
      box-shadow: 0 8px 24px rgba(26, 107, 60, .35);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(26, 107, 60, .5);
    }

    .btn-ghost {
      background: rgba(255, 255, 255, .06);
      color: var(--muted);
      border: 1px solid var(--border);
    }

    .btn-ghost:hover {
      background: rgba(255, 255, 255, .1);
      color: var(--text);
    }

    /* ── Stat strip ── */
    .stats {
      display: flex;
      gap: 40px;
      justify-content: center;
      margin-top: 48px;
      padding-top: 32px;
      border-top: 1px solid var(--border);
      animation: fadeUp .6s ease .8s both;
      flex-wrap: wrap;
    }

    .stat {
      text-align: center;
    }

    .stat-num {
      font-family: var(--mono);
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--accent);
      display: block;
    }

    .stat-label {
      font-size: .75rem;
      color: var(--muted);
      margin-top: 2px;
    }

    /* ── Keyframes ── */
    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeDown {
      from {
        opacity: 0;
        transform: translateY(-12px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes iconBounce {
      from {
        opacity: 0;
        transform: scale(.4) rotate(-15deg);
      }

      to {
        opacity: 1;
        transform: scale(1) rotate(0deg);
      }
    }

    @keyframes pulse {

      0%,
      100% {
        opacity: 1;
      }

      50% {
        opacity: .3;
      }
    }

    @keyframes fillBar {
      from {
        width: 0;
      }

      to {
        width: 35%;
      }
    }

    /* ── Responsive ── */
    @media (max-width: 640px) {
      .main {
        padding: 32px 20px;
      }

      .card-hero {
        padding: 36px 24px;
      }

      .features {
        grid-template-columns: 1fr 1fr;
      }

      .stats {
        gap: 24px;
      }
    }
  </style>
</head>

<body>

  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>

  <div class="layout">
    <!-- Sidebar -->
    <?php include 'includes/student_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main">

      <div class="badge">
        <span class="dot"></span>
        Under Construction
      </div>

      <div class="card-hero">

        <div class="hero-icon">
          <i class="fa-solid fa-graduation-cap"></i>
        </div>

        <h1>
          WAEC Practice
          <span>Coming very soon — we're building something great</span>
        </h1>

        <p class="tagline">
          A full WAEC preparation experience — year-by-year papers, topical drills,
          performance tracking, and personalised insights — all built into your portal.
        </p>

        <!-- Build progress -->
        <div class="progress-wrap">
          <div class="progress-label">
            <span>Build progress</span>
            <span>35%</span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill"></div>
          </div>
        </div>

        <!-- Feature preview -->
        <div class="features">
          <div class="feature">
            <div class="feature-icon fi-1"><i class="fa-solid fa-calendar-days"></i></div>
            <h3>Year-Based Practice</h3>
            <p>Pick a subject and year, then attempt the full paper — or a timed slice of it.</p>
          </div>
          <div class="feature">
            <div class="feature-icon fi-2"><i class="fa-solid fa-layer-group"></i></div>
            <h3>Topical Drills</h3>
            <p>Choose a specific topic and practise questions from multiple years at once.</p>
          </div>
          <div class="feature">
            <div class="feature-icon fi-3"><i class="fa-solid fa-sliders"></i></div>
            <h3>Standard & Custom Modes</h3>
            <p>Match real WAEC settings or set your own question count and time limit.</p>
          </div>
          <div class="feature">
            <div class="feature-icon fi-4"><i class="fa-solid fa-chart-line"></i></div>
            <h3>Performance Insights</h3>
            <p>See exactly which topics need work, and watch your mastery grow over time.</p>
          </div>
          <div class="feature">
            <div class="feature-icon fi-5"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <h3>Practice History</h3>
            <p>Every session is saved. Review past attempts and track consistency.</p>
          </div>
          <div class="feature">
            <div class="feature-icon fi-6"><i class="fa-solid fa-bookmark"></i></div>
            <h3>Bookmarks & Review</h3>
            <p>Flag tricky questions during practice and come back to them anytime.</p>
          </div>
        </div>

        <!-- CTA -->
        <div class="cta-group">
          <a href="exams.php" class="btn btn-primary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Exams
          </a>
          <a href="index.php" class="btn btn-ghost">
            <i class="fa-solid fa-house"></i>
            Dashboard
          </a>
        </div>

      </div><!-- /.card-hero -->

      <!-- Stats strip -->
      <div class="stats">
        <div class="stat">
          <span class="stat-num">15+</span>
          <span class="stat-label">WAEC Subjects</span>
        </div>
        <div class="stat">
          <span class="stat-num">20+</span>
          <span class="stat-label">Years of Papers</span>
        </div>
        <div class="stat">
          <span class="stat-num">5,000+</span>
          <span class="stat-label">Questions Being Loaded</span>
        </div>
        <div class="stat">
          <span class="stat-num">2</span>
          <span class="stat-label">Practice Modes</span>
        </div>
      </div>

    </main>
  </div>

</body>

</html>
<?php
echo ob_get_clean();

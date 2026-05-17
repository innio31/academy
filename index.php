<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>acad.com.ng | Smart School Solutions — Landing, Portal, CBT for Schools</title>
    <!-- Font Awesome 6 (free) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts for clean typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #fefefe;
            color: #1A2C3E;
            line-height: 1.5;
            scroll-behavior: smooth;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: #0F3B3C;
            color: white;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.25s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            box-shadow: 0 8px 16px -8px rgba(0,0,0,0.1);
        }
        .btn i {
            font-size: 1.1rem;
        }
        .btn-primary {
            background: #1E6F5C;
            box-shadow: 0 10px 20px -8px rgba(30,111,92,0.3);
        }
        .btn-primary:hover {
            background: #0F4C3F;
            transform: translateY(-3px);
        }
        .btn-outline-light {
            background: transparent;
            border: 2px solid white;
            color: white;
            box-shadow: none;
        }
        .btn-outline-light:hover {
            background: white;
            color: #1E6F5C;
            border-color: white;
        }
        .btn-accent {
            background: #F4A261;
            color: #1A2C3E;
        }
        .btn-accent:hover {
            background: #E76F51;
            color: white;
            transform: translateY(-3px);
        }

        .navbar {
            position: sticky;
            top: 0;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(8px);
            z-index: 100;
            padding: 16px 0;
            border-bottom: 1px solid #eef2f0;
            box-shadow: 0 2px 12px rgba(0,0,0,0.02);
        }
        .nav-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .logo-area {
            display: flex;
            align-items: baseline;
            gap: 6px;
            text-decoration: none;
        }
        .logo-main {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, #0F3B3C, #1E6F5C);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            letter-spacing: -0.02em;
        }
        .logo-badge {
            background: #F4A261;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 60px;
            color: #1A2C3E;
        }
        .nav-links {
            display: flex;
            gap: 32px;
            align-items: center;
        }
        .nav-links a {
            text-decoration: none;
            font-weight: 500;
            color: #2C4B4C;
            transition: color 0.2s;
        }
        .nav-links a:hover {
            color: #E76F51;
        }
        .btn-nav {
            background: #1E6F5C;
            padding: 8px 18px;
            border-radius: 60px;
            color: white !important;
        }
        .btn-nav:hover {
            background: #0F4C3F;
        }

        .hero {
            padding: 80px 0 60px;
            background: linear-gradient(130deg, #F9F7F3 0%, #EFF6F0 100%);
        }
        .hero-grid {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 48px;
        }
        .hero-content {
            flex: 1;
        }
        .hero-badge {
            background: #F4E2C6;
            color: #B3541C;
            display: inline-block;
            padding: 6px 14px;
            border-radius: 60px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .hero-content h1 {
            font-size: 3.2rem;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 20px;
            color: #1A2C3E;
        }
        .hero-highlight {
            color: #1E6F5C;
            border-bottom: 4px solid #F4A261;
            display: inline-block;
        }
        .hero-desc {
            font-size: 1.2rem;
            color: #2F5D5E;
            max-width: 540px;
            margin-bottom: 32px;
        }
        .hero-stats {
            display: flex;
            gap: 28px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .stat-item span {
            font-size: 2rem;
            font-weight: 800;
            color: #1E6F5C;
        }
        .hero-buttons {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }
        .hero-image {
            flex: 1;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 400"><rect width="500" height="400" fill="%23E0F2F1" rx="28"/><circle cx="250" cy="180" r="70" fill="%23B2DFDB"/><rect x="160" y="260" width="180" height="80" rx="16" fill="%2380CBC4"/><path d="M220 300 L280 300 L250 340 Z" fill="%23F4A261"/><circle cx="200" cy="200" r="12" fill="%23FFE0B2"/><circle cx="290" cy="200" r="12" fill="%23FFE0B2"/><rect x="230" y="230" width="30" height="18" fill="%235D4037" rx="6"/><path d="M100 320 L150 350 L100 380" stroke="%234DB6AC" fill="none" stroke-width="6"/><path d="M400 300 L430 320 L400 340" stroke="%234DB6AC" fill="none" stroke-width="6"/></svg>') no-repeat center;
            background-size: contain;
            min-height: 320px;
        }
        @media (max-width: 800px) {
            .hero-content h1 { font-size: 2.4rem; }
            .nav-links { gap: 18px; }
            .hero-grid { flex-direction: column; }
        }
        .section-title {
            font-size: 2.2rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 16px;
        }
        .section-sub {
            text-align: center;
            color: #54787A;
            max-width: 680px;
            margin: 0 auto 48px;
            font-size: 1.1rem;
        }
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 32px;
            margin: 48px 0;
        }
        .service-card {
            background: white;
            border-radius: 32px;
            padding: 32px 24px;
            box-shadow: 0 20px 35px -12px rgba(0,0,0,0.05);
            transition: all 0.25s;
            border: 1px solid #eef2f0;
        }
        .service-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 28px 32px -16px rgba(30,111,92,0.15);
            border-color: #c4dfd9;
        }
        .card-icon {
            width: 64px;
            height: 64px;
            background: #E0F2F1;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #1E6F5C;
            margin-bottom: 24px;
        }
        .service-card h3 {
            font-size: 1.6rem;
            margin-bottom: 12px;
        }
        .price-tag {
            display: inline-block;
            background: #F4A26120;
            color: #B3541C;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            margin: 16px 0 12px;
        }
        .feature-list {
            list-style: none;
            margin: 20px 0;
        }
        .feature-list li {
            margin: 12px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .feature-list li i {
            color: #1E6F5C;
            width: 20px;
        }
        .double-feature {
            background: #F9F7F3;
            padding: 64px 0;
            margin: 40px 0;
        }
        .split-row {
            display: flex;
            flex-wrap: wrap;
            gap: 48px;
            align-items: center;
        }
        .split-col {
            flex: 1;
        }
        .split-col h2 {
            font-size: 1.9rem;
            margin-bottom: 20px;
        }
        .mockup-icon {
            background: white;
            border-radius: 32px;
            padding: 20px;
            box-shadow: 0 20px 30px -12px rgba(0,0,0,0.05);
            text-align: center;
        }
        .mockup-icon i {
            font-size: 4rem;
            color: #1E6F5C;
        }
        .compare-pricing {
            background: linear-gradient(120deg, #1A2C3E, #1E3F40);
            color: white;
            padding: 64px 0;
            border-radius: 48px;
            margin: 64px auto;
        }
        .price-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 32px;
            margin-top: 48px;
        }
        .price-card {
            background: white;
            color: #1A2C3E;
            border-radius: 36px;
            padding: 32px;
            flex: 1;
            min-width: 280px;
            transition: transform 0.2s;
        }
        .price-card.popular {
            border: 2px solid #F4A261;
            position: relative;
        }
        .badge-pop {
            background: #F4A261;
            position: absolute;
            top: -12px;
            right: 24px;
            padding: 4px 16px;
            border-radius: 30px;
            font-weight: bold;
            font-size: 0.75rem;
            color: #1A2C3E;
        }
        .price-card .price {
            font-size: 2.8rem;
            font-weight: 800;
            color: #1E6F5C;
            margin: 16px 0;
        }
        .price-card hr {
            margin: 20px 0;
            border-color: #e9ecef;
        }
        .final-cta {
            text-align: center;
            padding: 72px 24px;
            background: #F4EADB;
            border-radius: 48px;
            margin-bottom: 48px;
        }
        footer {
            background: #0F2C2D;
            color: #CDE3E0;
            padding: 48px 0 32px;
            margin-top: 48px;
        }
        .footer-flex {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 32px;
        }
        .footer-col a {
            color: #B9D9D4;
            text-decoration: none;
        }
        .social-icons {
            display: flex;
            gap: 16px;
            margin-top: 16px;
        }
        .social-icons a {
            background: #255c55;
            width: 36px;
            height: 36px;
            border-radius: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
        }
        .social-icons a:hover {
            background: #F4A261;
            color: #1A2C3E;
        }
        @media (max-width: 680px) {
            .nav-flex { flex-direction: column; gap: 18px; }
            .nav-links { flex-wrap: wrap; justify-content: center; }
            .container { padding: 0 20px; }
            .section-title { font-size: 1.8rem; }
        }
        .accent-text {
            color: #E76F51;
        }
        .wa-float {
            position: fixed;
            bottom: 26px;
            right: 26px;
            background: #25D366;
            color: white;
            width: 58px;
            height: 58px;
            border-radius: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            transition: 0.2s;
            z-index: 99;
            text-decoration: none;
        }
        .wa-float:hover {
            transform: scale(1.07);
            background: #20b859;
        }
    </style>
</head>
<body>

<a href="https://wa.me/2349051586024?text=Hello%20acad.com.ng%20team!%20I'm%20interested%20in%20school%20solutions%20(portal%2C%20landing%2C%20CBT)." class="wa-float" target="_blank" aria-label="WhatsApp">
    <i class="fab fa-whatsapp"></i>
</a>

<nav class="navbar">
    <div class="container nav-flex">
        <a href="#" class="logo-area">
            <span class="logo-main">acad.com.ng</span>
            <span class="logo-badge">school suite</span>
        </a>
        <div class="nav-links">
            <a href="#solutions">Solutions</a>
            <a href="#platform">Portal App</a>
            <a href="#cbt-offline">Offline CBT</a>
            <a href="#pricing">Pricing</a>
            <a href="#contact" class="btn-nav">Get Quote</a>
        </div>
    </div>
</nav>

<section class="hero">
    <div class="container hero-grid">
        <div class="hero-content">
            <span class="hero-badge"><i class="fas fa-graduation-cap"></i>  Powered by edutech experts</span>
            <h1>Everything your school needs: <span class="hero-highlight">Landing, Admission,</span> All-in-one Portal & CBT</h1>
            <p class="hero-desc">Build a modern digital presence with acad.com.ng — custom subdomains, admission systems, parent/staff portal as a mobile-like app, offline CBT, starting at affordable prices.</p>
            <div class="hero-stats">
                <div class="stat-item"><span>120+</span> <br>schools onboarded</div>
                <div class="stat-item"><span>₦₦</span> <br>budget friendly</div>
                <div class="stat-item"><span>24/7</span> <br>support included</div>
            </div>
            <div class="hero-buttons">
                <a href="#contact" class="btn btn-primary"><i class="fas fa-rocket"></i> Get custom demo</a>
                <a href="#solutions" class="btn btn-outline-light" style="background:transparent; border:2px solid #1E6F5C; color:#1E6F5C;"><i class="fas fa-arrow-right"></i> Explore packages</a>
            </div>
        </div>
        <div class="hero-image"></div>
    </div>
</section>

<div class="container" id="solutions">
    <div style="margin: 80px 0 16px;">
        <h2 class="section-title">Complete Academic Ecosystem</h2>
        <p class="section-sub">We design & build everything — from brochure websites to full ERP portals with app-like experience</p>
    </div>

    <!-- UPDATED 3 PACKAGES: Basic Landing (₦50k), Social Media + Portal Bundle (₦45k/term), Offline CBT -->
    <div class="services-grid">
        <!-- Package 1: Basic School Landing + Subdomain (₦50k) -->
        <div class="service-card">
            <div class="card-icon"><i class="fas fa-globe"></i></div>
            <h3>Basic: School Landing + Subdomain</h3>
            <p>Custom school landing page on <strong>yourschool.acad.com.ng</strong> subdomain. Includes admission information, about, contact, modern design.</p>
            <div class="price-tag">₦50k / one-time</div>
            <ul class="feature-list">
                <li><i class="fas fa-check-circle"></i> Responsive design, admission form</li>
                <li><i class="fas fa-check-circle"></i> Fast hosting & SSL</li>
                <li><i class="fas fa-check-circle"></i> SEO ready + contact form</li>
                <li><i class="fas fa-check-circle"></i> 3 days delivery</li>
            </ul>
            <a href="#contact" class="btn" style="background:#F4A261; color:#1A2C3E; width:100%; text-align:center; justify-content:center;"><i class="fas fa-school"></i> Get landing</a>
        </div>

        <!-- Package 2: School Landing + Subdomain + Social Media Integration + Portal (₦45k/term) with advanced portal features -->
        <div class="service-card">
            <div class="card-icon"><i class="fas fa-chalkboard-user"></i></div>
            <h3>Elite: Landing + Social + Portal</h3>
            <p>Full digital ecosystem: custom landing, social media integration, and powerful multi-role portal. Billed per term.</p>
            <div class="price-tag">₦45k / term (recurring)</div>
            <ul class="feature-list">
                <li><i class="fas fa-check-circle"></i> School landing + yourschool.acad.com.ng</li>
                <li><i class="fas fa-check-circle"></i> <strong>Social Media Integration:</strong> Auto-post announcements, embedded feeds (FB/IG), social share buttons & WhatsApp widget</li>
                <li><i class="fas fa-check-circle"></i> Parent dashboard: results, fees, announcements</li>
                <li><i class="fas fa-check-circle"></i> Staff: lesson notes, grade entry, attendance</li>
                <li><i class="fas fa-check-circle"></i> Super admin + full analytics</li>
                <li><i class="fas fa-check-circle"></i> Works as installable "app" (PWA)</li>
                <li><i class="fas fa-check-circle"></i> Bi-monthly updates + maintenance</li>
                <li><i class="fas fa-check-circle"></i> <strong>2 official email addresses</strong> (e.g., admin@yourschool.com.ng, info@yourschool.com.ng via forwarding)</li>
            </ul>
            <a href="#contact" class="btn btn-primary" style="width:100%; justify-content:center;"><i class="fab fa-instagram"></i> Start term plan</a>
        </div>

        <!-- Package 3: Offline CBT System (exactly as requested) -->
        <div class="service-card">
            <div class="card-icon"><i class="fas fa-laptop-code"></i></div>
            <h3>Offline CBT System</h3>
            <p>Computer-based test software that works without internet! Perfect for schools with labs. Secure, automated marking, result analytics.</p>
            <div class="price-tag">One-time setup + licensing</div>
            <ul class="feature-list">
                <li><i class="fas fa-check-circle"></i> No internet required (LAN based)</li>
                <li><i class="fas fa-check-circle"></i> Randomised questions per student</li>
                <li><i class="fas fa-check-circle"></i> Auto score & instant result</li>
                <li><i class="fas fa-check-circle"></i> Also support online mode</li>
                <li><i class="fas fa-check-circle"></i> Supports 500+ concurrent devices</li>
                <li><i class="fas fa-check-circle"></i> Question bank & detailed analytics</li>
            </ul>
            <a href="#contact" class="btn" style="background:#E76F51; width:100%; justify-content:center;">Request CBT pricing</a>
        </div>
    </div>
</div>

<!-- feature spotlight: platform as an app -->
<section class="double-feature" id="platform">
    <div class="container split-row">
        <div class="split-col">
            <div class="mockup-icon">
                <i class="fas fa-tablet-alt"></i> <i class="fas fa-chalkboard-user"></i>
                <p style="margin-top: 16px; font-weight:500;">📱 Installs like an app on phones & tablets</p>
            </div>
        </div>
        <div class="split-col">
            <h2>The <span class="accent-text">Mobile-first School Portal</span> that parents, staff & admin love</h2>
            <p>We build a white-labeled portal under your subdomain (portal.yourschool.acad.com.ng). It works as a progressive web app — parents can add it to phone home screen. Real-time notifications, grade book, fee balance, digital ID cards, and more.</p>
            <ul class="feature-list" style="margin-top: 18px;">
                <li><i class="fas fa-check-circle"></i> Multi-role access: parent, student, teacher, admin</li>
                <li><i class="fas fa-check-circle"></i> Built-in messaging & announcement system</li>
                <li><i class="fas fa-check-circle"></i> Fee payment tracking, receipts generation</li>
                <li><i class="fas fa-check-circle"></i> Attendance via QR code (optional)</li>
                <li><i class="fas fa-check-circle"></i> Customizable to school brand colors</li>
            </ul>
            <div style="margin-top: 28px;"><a href="#contact" class="btn btn-primary"><i class="fas fa-eye"></i> Live preview portal</a></div>
        </div>
    </div>
</section>

<!-- offline CBT deeper -->
<section id="cbt-offline" style="padding: 32px 0 64px;">
    <div class="container split-row" style="flex-direction: row-reverse;">
        <div class="split-col">
            <div class="mockup-icon" style="background: #EEF7F5;">
                <i class="fas fa-network-wired" style="font-size: 4rem;"></i>
                <h3 style="margin-top: 8px;">Offline First Technology</h3>
            </div>
        </div>
        <div class="split-col">
            <h2>Offline CBT + <br> <span class="accent-text">Lightning-fast Exam Suite</span></h2>
            <p>Our CBT system works in computer labs with zero internet dependency. Exam questions are loaded via local server, results stored offline then sync when needed. Ideal for large-scale school exams, mock tests, and entrance exams.</p>
            <ul class="feature-list">
                <li><i class="fas fa-check-circle"></i> Supports 500+ concurrent devices</li>
                <li><i class="fas fa-check-circle"></i> Question bank management</li>
                <li><i class="fas fa-check-circle"></i> Auto-proctoring and time control</li>
                <li><i class="fas fa-check-circle"></i> Detailed analytics per subject/class</li>
                <li><i class="fas fa-check-circle"></i> Also cloud-sync hybrid mode</li>
            </ul>
            <div><span class="price-tag" style="background:#1E6F5C; color:white;">Budget friendly — ask for quote</span></div>
        </div>
    </div>
</section>

<!-- Pricing Section UPDATED with new package details for clarity and matching the three offers -->
<div class="container" id="pricing">
    <div class="compare-pricing">
        <h2 class="section-title" style="color: white;">💸 Affordable plans for every school</h2>
        <p class="section-sub" style="color: #C5E0DD;">Transparent pricing: one-time setup or flexible term payments. No hidden fees.</p>
        <div class="price-grid">
            <div class="price-card">
                <h3>Basic Landing Pack</h3>
                <div class="price">₦50k <span style="font-size:1rem;">one-time</span></div>
                <p>School Landing + Subdomain + Admission form</p>
                <hr>
                <ul class="feature-list" style="margin:0; padding-left:0;">
                    <li><i class="fas fa-check"></i> Responsive site (5 pages)</li>
                    <li><i class="fas fa-check"></i> Online admission / enquiry form</li>
                    <li><i class="fas fa-check"></i> Free .acad.com.ng domain</li>
                    <li><i class="fas fa-check"></i> 1 year hosting + SSL</li>
                    <li><i class="fas fa-check"></i> 3 days delivery</li>
                </ul>
            </div>
            <div class="price-card popular">
                <div class="badge-pop">Best Value</div>
                <h3>Elite Term Plan</h3>
                <div class="price">₦45k <span style="font-size:1rem;">/ term</span></div>
                <p>Landing + Social Media Boost + Full Portal (app-like)</p>
                <hr>
                <ul class="feature-list">
                    <li><i class="fas fa-check"></i> Everything from Basic Landing</li>
                    <li><i class="fas fa-check"></i> Social media integration (auto feed, share tools)</li>
                    <li><i class="fas fa-check"></i> Full portal: Parent, Staff, Admin roles</li>
                    <li><i class="fas fa-check"></i> Results, fees, attendance, lesson notes</li>
                    <li><i class="fas fa-check"></i> Works as installable app (PWA)</li>
                    <li><i class="fas fa-check"></i> Bi-monthly updates + 2 official email addresses</li>
                    <li><i class="fas fa-check"></i> Priority support & maintenance</li>
                </ul>
            </div>
            <div class="price-card">
                <h3>Ultimate + CBT</h3>
                <div class="price">₦200k</div>
                <p>Elite Portal Suite + Offline CBT (unlimited license)</p>
                <hr>
                <ul class="feature-list">
                    <li><i class="fas fa-check"></i> Landing + Social + Full portal</li>
                    <li><i class="fas fa-check"></i> Offline CBT – full setup & training</li>
                    <li><i class="fas fa-check"></i> LAN based / auto marking</li>
                    <li><i class="fas fa-check"></i> White-label exam reports</li>
                    <li><i class="fas fa-check"></i> 1 year support + updates</li>
                </ul>
            </div>
        </div>
        <p style="text-align:center; margin-top: 48px;">✨ <strong>Elite term plan includes bi-monthly updates, social media integration, and 2 official email accounts</strong> — perfect for modern schools seeking digital presence & parent engagement.</p>
    </div>
</div>

<!-- consultation / contact form -->
<div class="container" id="contact">
    <div class="final-cta">
        <i class="fas fa-chalkboard" style="font-size: 3rem; color:#1E6F5C;"></i>
        <h2 style="font-size: 2rem;">Let's build your school's digital future</h2>
        <p style="max-width: 560px; margin: 16px auto;">Tell us your requirements: Basic Landing (₦50k), Elite Term Plan (₦45k/term) with social media & portal, or Offline CBT. We respond within 12 hours.</p>
        <div style="display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; margin: 32px 0;">
            <a href="https://wa.me/2349051586024?text=I%20want%20to%20build%20school%20portal%20with%20acad.com.ng%20(Elite%20plan%20or%20CBT)" class="btn btn-primary" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp Our Team</a>
            <a href="mailto:schools@acad.com.ng?subject=Inquiry%20about%20school%20solutions" class="btn" style="background:#1A2C3E;"><i class="fas fa-envelope"></i> schools@acad.com.ng</a>
        </div>
        <p class="price-tag" style="background: white; color:#0F3B3C;"><i class="fas fa-phone-alt"></i> Call/Text: +234 903 544 8295 (Business hours)</p>
        <small>Free consultation & demo for all solutions</small>
    </div>
</div>

<footer>
    <div class="container footer-flex">
        <div class="footer-col">
            <h3 style="color:white; margin-bottom: 16px;">acad.com.ng</h3>
            <p>Smart school technology suite: Landing pages, social + portal bundle, school management portal (installable app) & offline CBT.</p>
            <div class="social-icons">
                <a href="#"><i class="fab fa-facebook-f"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-linkedin-in"></i></a>
            </div>
        </div>
        <div class="footer-col">
            <h4 style="color:white;">Solutions</h4>
            <p><a href="#">Basic Landing (₦50k)</a></p>
            <p><a href="#">Elite Term Plan (₦45k/term)</a></p>
            <p><a href="#">Parent/Staff Portal (PWA)</a></p>
            <p><a href="#">Offline CBT System</a></p>
        </div>
        <div class="footer-col">
            <h4 style="color:white;">Contact</h4>
            <p><i class="fas fa-envelope"></i> schools@acad.com.ng</p>
            <p><i class="fas fa-phone-alt"></i> +234 903 544 8295</p>
            <p><i class="fab fa-whatsapp"></i> +234 905 158 6024</p>
            <p>Ota, Nigeria / Remote support worldwide</p>
        </div>
        <div class="footer-col">
            <h4 style="color:white;">Legal</h4>
            <p><a href="#">Privacy Policy</a></p>
            <p><a href="#">Service terms</a></p>
            <p><a href="#">CAC registered company</a></p>
        </div>
    </div>
    <div class="container" style="text-align: center; margin-top: 48px; border-top: 1px solid #2F5956; padding-top: 32px;">
        <p>© 2025 acad.com.ng — Built for modern schools. All rights reserved.</p>
    </div>
</footer>

<script>
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === "#" || href === "") return;
            const target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
</script>
</body>
</html>
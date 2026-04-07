<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MISPro — ASK Global Advisory Pvt. Ltd.</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600;700&display=swap"
    rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
  <style>
    :root {
      --navy: #0a0f1e;
      --navy2: #111827;
      --gold: #c9a84c;
      --gold2: #e8c96a;
      --text: #e5e7eb;
      --muted: #6b7280;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      font-family: 'Outfit', sans-serif;
      background: var(--navy);
      color: var(--text);
      min-height: 100vh;
      overflow-x: hidden;
    }

    /* ── BACKGROUND ── */
    .bg-grid {
      position: fixed;
      inset: 0;
      z-index: 0;
      background-image:
        linear-gradient(rgba(201, 168, 76, .04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(201, 168, 76, .04) 1px, transparent 1px);
      background-size: 60px 60px;
      pointer-events: none;
    }

    .bg-glow {
      position: fixed;
      z-index: 0;
      border-radius: 50%;
      filter: blur(120px);
      pointer-events: none;
    }

    .glow-1 {
      width: 500px;
      height: 500px;
      background: rgba(201, 168, 76, .07);
      top: -100px;
      left: -100px;
    }

    .glow-2 {
      width: 400px;
      height: 400px;
      background: rgba(59, 130, 246, .05);
      bottom: -100px;
      right: -100px;
    }

    /* ── NAVBAR ── */
    nav {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 100;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .9rem 2.5rem;
      background: rgba(10, 15, 30, .9);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(201, 168, 76, .12);
    }

    .nav-brand {
      display: flex;
      align-items: center;
      gap: .75rem;
    }

    .nav-logo {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .nav-logo span {
      font-family: 'Cormorant Garamond', serif;
      font-weight: 900;
      color: var(--navy);
      font-size: .95rem;
    }

    .nav-brand-text .t1 {
      font-weight: 700;
      font-size: .95rem;
      color: white;
    }

    .nav-brand-text .t2 {
      font-size: .7rem;
      color: var(--muted);
    }

    .nav-links {
      display: flex;
      gap: 2rem;
    }

    .nav-links a {
      color: var(--muted);
      font-size: .88rem;
      text-decoration: none;
      transition: .2s;
    }

    .nav-links a:hover {
      color: var(--gold);
    }

    .nav-cta {
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      color: var(--navy);
      font-weight: 700;
      border: none;
      padding: .5rem 1.4rem;
      border-radius: 8px;
      font-family: 'Outfit', sans-serif;
      font-size: .88rem;
      cursor: pointer;
      text-decoration: none;
      transition: .2s;
    }

    .nav-cta:hover {
      opacity: .9;
      transform: translateY(-1px);
    }

    .nav-links a.active {
      color: var(--gold);
      font-weight: 600;
      position: relative;
    }

    .nav-links a.active::after {
      content: "";
      position: absolute;
      bottom: -6px;
      left: 0;
      width: 100%;
      height: 2px;
      background: var(--gold);
      border-radius: 2px;
    }

    /* ── HERO ── */
    .hero {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 6rem 1.5rem 4rem;
    }

    .hero-inner {
      max-width: 820px;
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      background: rgba(201, 168, 76, .1);
      border: 1px solid rgba(201, 168, 76, .25);
      color: var(--gold);
      border-radius: 50px;
      padding: .3rem 1rem;
      font-size: .78rem;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      margin-bottom: 1.5rem;
      animation: fadeInDown .6s ease both;
    }

    .hero h1 {
      font-family: 'Cormorant Garamond', serif;
      font-size: clamp(2.8rem, 6vw, 4.5rem);
      line-height: 1.1;
      font-weight: 700;
      color: white;
      margin-bottom: 1.2rem;
      animation: fadeInDown .7s .1s ease both;
    }

    .hero h1 .gold {
      color: var(--gold);
    }

    .hero p {
      font-size: 1.08rem;
      color: #9ca3af;
      line-height: 1.7;
      max-width: 600px;
      margin: 0 auto 2.5rem;
      animation: fadeInDown .7s .2s ease both;
    }

    .hero-btns {
      display: flex;
      gap: 1rem;
      justify-content: center;
      flex-wrap: wrap;
      animation: fadeInDown .7s .3s ease both;
    }

    .btn-hero-primary {
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      color: var(--navy);
      font-weight: 700;
      padding: .75rem 2rem;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      font-size: .95rem;
      font-family: 'Outfit', sans-serif;
      text-decoration: none;
      transition: .2s;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .btn-hero-primary:hover {
      opacity: .9;
      transform: translateY(-2px);
      box-shadow: 0 12px 30px rgba(201, 168, 76, .25);
    }

    .btn-hero-outline {
      background: transparent;
      color: white;
      font-weight: 600;
      padding: .75rem 2rem;
      border-radius: 10px;
      border: 1.5px solid rgba(255, 255, 255, .2);
      cursor: pointer;
      font-size: .95rem;
      font-family: 'Outfit', sans-serif;
      text-decoration: none;
      transition: .2s;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .btn-hero-outline:hover {
      border-color: var(--gold);
      color: var(--gold);
    }

    /* ── STATS BAR ── */
    .stats-bar {
      position: relative;
      z-index: 1;
      display: flex;
      gap: 0;
      justify-content: center;
      background: rgba(255, 255, 255, .03);
      border-top: 1px solid rgba(255, 255, 255, .06);
      border-bottom: 1px solid rgba(255, 255, 255, .06);
      flex-wrap: wrap;
    }

    .stat-item {
      padding: 1.5rem 3rem;
      text-align: center;
      border-right: 1px solid rgba(255, 255, 255, .06);
      flex: 1;
      min-width: 150px;
    }

    .stat-item:last-child {
      border-right: none;
    }

    .stat-val {
      font-family: 'Cormorant Garamond', serif;
      font-size: 2rem;
      font-weight: 700;
      color: var(--gold);
    }

    .stat-lbl {
      font-size: .78rem;
      color: var(--muted);
      margin-top: .2rem;
    }

    /* ── ROLE SECTION ── */
    .section {
      position: relative;
      z-index: 1;
      padding: 5rem 1.5rem;
    }

    .section-inner {
      max-width: 1100px;
      margin: 0 auto;
    }

    .section-label {
      font-size: .72rem;
      font-weight: 700;
      color: var(--gold);
      text-transform: uppercase;
      letter-spacing: .12em;
      margin-bottom: .75rem;
    }

    .section-title {
      font-family: 'Cormorant Garamond', serif;
      font-size: clamp(1.8rem, 4vw, 2.8rem);
      font-weight: 700;
      color: white;
      margin-bottom: 1rem;
    }

    .section-sub {
      color: var(--muted);
      font-size: .95rem;
      max-width: 550px;
      line-height: 1.7;
    }

    /* ── ROLE CARDS ── */
    .role-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
      margin-top: 3rem;
    }

    .role-card {
      background: rgba(255, 255, 255, .03);
      border: 1px solid rgba(255, 255, 255, .07);
      border-radius: 16px;
      padding: 2rem;
      transition: .3s;
      cursor: pointer;
      text-decoration: none;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .role-card:hover {
      background: rgba(201, 168, 76, .05);
      border-color: rgba(201, 168, 76, .25);
      transform: translateY(-4px);
    }

    .role-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
    }

    .role-card h3 {
      color: white;
      font-size: 1.2rem;
      font-weight: 600;
    }

    .role-card p {
      color: var(--muted);
      font-size: .88rem;
      line-height: 1.6;
    }

    .role-features {
      list-style: none;
      margin-top: .5rem;
    }

    .role-features li {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-size: .82rem;
      color: #9ca3af;
      padding: .2rem 0;
    }

    .role-features li i {
      color: var(--gold);
      font-size: .7rem;
    }

    .role-badge {
      display: inline-block;
      font-size: .7rem;
      font-weight: 700;
      padding: .15rem .65rem;
      border-radius: 50px;
      text-transform: uppercase;
      letter-spacing: .06em;
      align-self: flex-start;
      margin-top: auto;
    }

    /* ── DEPT SECTION ── */
    .dept-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
      margin-top: 2.5rem;
    }

    .dept-card {
      background: rgba(255, 255, 255, .03);
      border: 1px solid rgba(255, 255, 255, .07);
      border-radius: 12px;
      padding: 1.5rem 1.2rem;
      text-align: center;
      transition: .2s;
    }

    .dept-card:hover {
      transform: translateY(-3px);
      border-color: rgba(201, 168, 76, .2);
    }

    .dept-card-icon {
      font-size: 1.8rem;
      margin-bottom: .75rem;
    }

    .dept-card h4 {
      color: white;
      font-size: .95rem;
      font-weight: 600;
      margin-bottom: .4rem;
    }

    .dept-card p {
      color: var(--muted);
      font-size: .78rem;
    }

    /* ── WORKFLOW ── */
    .workflow-steps {
      display: flex;
      gap: 0;
      flex-wrap: wrap;
      margin-top: 2.5rem;
      counter-reset: step;
    }

    .step {
      flex: 1;
      min-width: 180px;
      padding: 1.5rem;
      position: relative;
      border-right: 1px solid rgba(255, 255, 255, .06);
    }

    .step:last-child {
      border-right: none;
    }

    .step::before {
      counter-increment: step;
      content: counter(step);
      display: block;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(201, 168, 76, .15);
      color: var(--gold);
      font-weight: 700;
      font-size: .85rem;
      text-align: center;
      line-height: 32px;
      margin-bottom: .75rem;
    }

    .step h4 {
      color: white;
      font-size: .9rem;
      font-weight: 600;
      margin-bottom: .4rem;
    }

    .step p {
      color: var(--muted);
      font-size: .78rem;
      line-height: 1.5;
    }

    /* ── LOGIN MODAL ── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .7);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(4px);
    }

    .modal-overlay.show {
      display: flex;
    }

    .modal-box {
      background: white;
      border-radius: 20px;
      overflow: hidden;
      width: min(440px, 95vw);
      box-shadow: 0 32px 80px rgba(0, 0, 0, .5);
      animation: slideUp .3s ease;
    }

    .modal-header {
      background: #0a0f1e;
      padding: 1.5rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h4 {
      color: white;
      font-size: 1.1rem;
      font-weight: 600;
      margin: 0;
    }

    .modal-close {
      background: none;
      border: none;
      color: #9ca3af;
      cursor: pointer;
      font-size: 1.1rem;
    }

    .modal-body {
      padding: 2rem;
    }

    .modal-role-tabs {
      display: flex;
      gap: .4rem;
      background: #f9fafb;
      border-radius: 10px;
      padding: .3rem;
      margin-bottom: 1.5rem;
    }

    .modal-tab {
      flex: 1;
      padding: .5rem;
      border: none;
      background: transparent;
      font-size: .82rem;
      font-weight: 500;
      color: #9ca3af;
      cursor: pointer;
      border-radius: 8px;
      transition: .2s;
      font-family: 'Outfit', sans-serif;
    }

    .modal-tab.active {
      background: white;
      color: #0a0f1e;
      font-weight: 700;
      box-shadow: 0 1px 4px rgba(0, 0, 0, .1);
    }

    .modal-input-wrap {
      position: relative;
      margin-bottom: 1rem;
    }

    .modal-input-wrap i.ico {
      position: absolute;
      left: .85rem;
      top: 50%;
      transform: translateY(-50%);
      color: #9ca3af;
      font-size: .85rem;
    }

    .modal-input {
      width: 100%;
      padding: .65rem .9rem .65rem 2.4rem;
      border: 1.5px solid #e5e7eb;
      border-radius: 9px;
      font-size: .9rem;
      font-family: 'Outfit', sans-serif;
      background: #fafafa;
      transition: .2s;
    }

    .modal-input:focus {
      outline: none;
      border-color: #c9a84c;
      box-shadow: 0 0 0 3px rgba(201, 168, 76, .1);
      background: white;
    }

    .modal-btn {
      width: 100%;
      padding: .72rem;
      border: none;
      border-radius: 10px;
      background: linear-gradient(135deg, #c9a84c, #e8c96a);
      color: #0a0f1e;
      font-weight: 700;
      font-size: .95rem;
      font-family: 'Outfit', sans-serif;
      cursor: pointer;
      transition: .2s;
      margin-top: .25rem;
    }

    .modal-btn:hover {
      opacity: .9;
    }

    #branchWrap {
      display: none;
    }

    #branchWrap.show {
      display: block;
    }

    /* ── FOOTER ── */
    footer {
      position: relative;
      z-index: 1;
      border-top: 1px solid rgba(255, 255, 255, .06);
      padding: 2rem 2.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }

    footer p {
      color: var(--muted);
      font-size: .8rem;
    }

    .footer-links {
      display: flex;
      gap: 1.5rem;
    }

    .footer-links a {
      color: var(--muted);
      font-size: .8rem;
      text-decoration: none;
    }

    .footer-links a:hover {
      color: var(--gold);
    }

    @keyframes fadeInDown {
      from {
        opacity: 0;
        transform: translateY(-16px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    @keyframes slideUp {
      from {
        opacity: 0;
        transform: translateY(24px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    @media (max-width:768px) {
      nav {
        padding: .75rem 1rem;
      }

      .nav-links {
        display: none;
      }

      .stat-item {
        padding: 1rem 1.5rem;
      }

      .step {
        min-width: 140px;
      }
    }
  </style>
</head>

<body>

  <div class="bg-grid"></div>
  <div class="bg-glow glow-1"></div>
  <div class="bg-glow glow-2"></div>

  <!-- NAVBAR -->
  <nav>
    <div class="nav-brand">
      <div class="nav-logo"><span>ASK</span></div>
      <div class="nav-brand-text">
        <div class="t1">MISPro</div>
        <div class="t2">ASK Global Advisory</div>
      </div>
    </div>
    <div class="nav-links">
      <a href="#roles">Roles</a>
      <a href="#departments">Departments</a>
      <a href="#workflow">Workflow</a>
      <a href="#features">Features</a>
    </div>
    <a href="#" class="nav-cta" onclick="openLogin(event)">
      <i class="fas fa-sign-in-alt me-1"></i> Login
    </a>
  </nav>

  <!-- HERO -->
  <section class="hero">
    <div class="hero-inner">
      <div class="hero-badge">
        <i class="fas fa-star"></i> ASK Global Advisory Pvt. Ltd.
      </div>
      <h1>Management<br><span class="gold">Information System</span><br>for Modern Consulting</h1>
      <p>A unified platform to manage tasks, track workflows across departments and branches, and generate real-time
        reports — built for ASK Global Advisory's daily operations.</p>
      <div class="hero-btns">
        <a href="#" class="btn-hero-primary" onclick="openLogin(event)">
          <i class="fas fa-sign-in-alt"></i> Access MISPro
        </a>
        <a href="#workflow" class="btn-hero-outline">
          <i class="fas fa-play-circle"></i> See How It Works
        </a>
      </div>
    </div>
  </section>

  <!-- ROLES -->
  <section class="section" id="roles">
    <div class="section-inner">
      <div class="section-label">Access Levels</div>
      <div class="section-title">Three Roles, One System</div>
      <p class="section-sub">Every user sees exactly what they need — no more, no less. Role-based access keeps data
        secure and workflows clean.</p>

      <div class="role-grid">
        <!-- Executive -->
        <a href="auth/login.php?role=executive" class="role-card">
          <div class="role-icon" style="background:rgba(201,168,76,.15);color:#c9a84c;">
            <i class="fas fa-crown"></i>
          </div>
          <div>
            <h3>Executive</h3>
            <p>Full visibility across all branches, departments, staff, and companies. Export PDF reports with Google
              Charts.</p>
          </div>
          <ul class="role-features">
            <li><i class="fas fa-check"></i> All-branch task overview</li>
            <li><i class="fas fa-check"></i> Google Charts — dept / branch / staff</li>
            <li><i class="fas fa-check"></i> Full workflow timeline per company</li>
            <li><i class="fas fa-check"></i> Export to PDF</li>
          </ul>
          <span class="role-badge" style="background:rgba(201,168,76,.15);color:#c9a84c;">Login as Executive →</span>
        </a>

        <!-- Admin -->
        <a href="auth/login.php?role=admin" class="role-card">
          <div class="role-icon" style="background:rgba(59,130,246,.12);color:#3b82f6;">
            <i class="fas fa-user-shield"></i>
          </div>
          <div>
            <h3>Branch Admin</h3>
            <p>Manage staff, assign tasks, track department workflow, view company details, and export reports for their
              branch.</p>
          </div>
          <ul class="role-features">
            <li><i class="fas fa-check"></i> Add staff, reset passwords</li>
            <li><i class="fas fa-check"></i> Assign tasks by department</li>
            <li><i class="fas fa-check"></i> Transfer tasks across depts</li>
            <li><i class="fas fa-check"></i> Company search + workflow view</li>
          </ul>
          <span class="role-badge" style="background:rgba(59,130,246,.12);color:#3b82f6;">Login as Admin →</span>
        </a>

        <!-- Staff -->
        <a href="auth/login.php?role=staff" class="role-card">
          <div class="role-icon" style="background:rgba(16,185,129,.12);color:#10b981;">
            <i class="fas fa-user"></i>
          </div>
          <div>
            <h3>Staff</h3>
            <p>View assigned tasks, update status, add remarks, and transfer to next staff in the same department and
              branch.</p>
          </div>
          <ul class="role-features">
            <li><i class="fas fa-check"></i> Today / tomorrow / past tasks</li>
            <li><i class="fas fa-check"></i> Mark status + add remarks</li>
            <li><i class="fas fa-check"></i> Transfer to next staff member</li>
            <li><i class="fas fa-check"></i> App + email notifications</li>
          </ul>
          <span class="role-badge" style="background:rgba(16,185,129,.12);color:#10b981;">Login as Staff →</span>
        </a>
      </div>
    </div>
  </section>

  <!-- DEPARTMENTS -->
  <section class="section" id="departments" style="padding-top:0;">
    <div class="section-inner">
      <div class="section-label">Department Modules</div>
      <div class="section-title">Separate Tables for Each Department</div>
      <p class="section-sub">Every department has its own dedicated task table with fields specific to their workflow —
        Tax, Retail, Corporate, and Banking.</p>
      <div class="dept-grid">
        <div class="dept-card">
          <div class="dept-card-icon" style="color:#f59e0b;">🧾</div>
          <h4>Tax</h4>
          <p>VAT, Income Tax, TDS, PAN, filing status, assessment year</p>
        </div>
        <div class="dept-card">
          <div class="dept-card-icon" style="color:#3b82f6;">🏪</div>
          <h4>Retail</h4>
          <p>Invoices, inventory, payments, delivery tracking</p>
        </div>
        <div class="dept-card">
          <div class="dept-card-icon" style="color:#8b5cf6;">🏢</div>
          <h4>Corporate</h4>
          <p>Company registration, audits, AGM, ROC filings, compliance</p>
        </div>
        <div class="dept-card">
          <div class="dept-card-icon" style="color:#10b981;">🏦</div>
          <h4>Banking</h4>
          <p>Loan applications, KYC, disbursement, EMI, collateral</p>
        </div>
        <div class="dept-card">
          <div class="dept-card-icon" style="color:#ec4899;">⚙️</div>
          <h4>Core Admin</h4>
          <p>Staff management, company directory, system settings</p>
        </div>
      </div>
    </div>
  </section>

  <!-- WORKFLOW -->
  <section class="section" id="workflow"
    style="background:rgba(255,255,255,.02);border-top:1px solid rgba(255,255,255,.05);border-bottom:1px solid rgba(255,255,255,.05);">
    <div class="section-inner">
      <div class="section-label">Task Workflow</div>
      <div class="section-title">From Assignment to Completion</div>
      <p class="section-sub">Tasks flow through staff and departments with full tracking — every action, time spent, and
        remark is recorded for the complete audit trail.</p>
      <div class="workflow-steps">
        <div class="step">
          <h4>Admin Creates Task</h4>
          <p>Admin assigns task to a staff member in their branch + department with due date, priority, and
            dept-specific fields.</p>
        </div>
        <div class="step">
          <h4>Staff Notified</h4>
          <p>Staff receives an app notification and email instantly. Task appears in their Today/Tomorrow queue.</p>
        </div>
        <div class="step">
          <h4>Staff Works & Transfers</h4>
          <p>Staff updates status, adds remarks, and transfers to the next staff member in the same dept/branch when
            their part is done.</p>
        </div>
        <div class="step">
          <h4>Staff Marks Complete</h4>
          <p>Final staff marks task as Completed. Admin is notified. Admin can then transfer to another department
            admin.</p>
        </div>
        <div class="step">
          <h4>Cross-Dept Transfer</h4>
          <p>Admin transfers completed task to another department admin if further work is needed, with remarks.</p>
        </div>
        <div class="step">
          <h4>Executive Views All</h4>
          <p>Executive sees the complete workflow: who did what, how long, at what date, with export to PDF.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- FEATURES -->
  <section class="section" id="features">
    <div class="section-inner">
      <div class="section-label">Key Features</div>
      <div class="section-title">Everything You Need</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.5rem;margin-top:2.5rem;">
        <?php
        $features = [
          ['fa-shield-alt', 'Google Authenticator 2FA', 'Secure login with TOTP verification on every sign-in.'],
          ['fa-bell', 'App + Email Notifications', 'Real-time alerts on task assignment, transfer, and completion.'],
          ['fa-chart-pie', 'Google Charts Reports', 'Interactive pie, bar, and line charts for executive dashboard.'],
          ['fa-file-pdf', 'PDF Export', 'Export any report, task list, or company workflow to PDF.'],
          ['fa-exchange-alt', 'Task Transfer Chain', 'Full tracked transfer chain from staff to staff to admin to dept.'],
          ['fa-building', 'Company Directory', 'Search, filter companies with full task history and who did what.'],
          ['fa-user-plus', 'Staff Management', 'Add, edit, reset password, reassign branch/dept from core admin.'],
          ['fa-clock', 'Time Tracking', 'Record time spent per step in the workflow for performance reports.'],
        ];
        ?>
        <?php foreach ($features as [$icon, $title, $desc]): ?>
          <div
            style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:1.5rem;">
            <div
              style="width:40px;height:40px;border-radius:10px;background:rgba(201,168,76,.12);color:#c9a84c;display:flex;align-items:center;justify-content:center;margin-bottom:.9rem;">
              <i class="fas <?= $icon ?>"></i>
            </div>
            <h4 style="color:white;font-size:.95rem;font-weight:600;margin-bottom:.4rem;"><?= $title ?></h4>
            <p style="color:#6b7280;font-size:.82rem;line-height:1.6;"><?= $desc ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <footer>
    <div>
      <div class="nav-brand" style="margin-bottom:.4rem;">
        <div class="nav-logo" style="width:28px;height:28px;"><span style="font-size:.72rem;">ASK</span></div>
        <div class="nav-brand-text">
          <div class="t1" style="font-size:.85rem;">MISPro</div>
        </div>
      </div>
      <p>© <?= date('Y') ?> ASK Global Advisory Pvt. Ltd. · "At ASK business problems end, solutions begin"</p>
    </div>
    <div class="footer-links">
      <a href="auth/login.php?role=executive">Executive Login</a>
      <a href="auth/login.php?role=admin">Admin Login</a>
      <a href="auth/login.php?role=staff">Staff Login</a>
    </div>
  </footer>

  <!-- LOGIN MODAL -->
  <div class="modal-overlay" id="loginModal">
    <div class="modal-box">
      <div class="modal-header">
        <h4><i class="fas fa-sign-in-alt me-2" style="color:#c9a84c;"></i> Login to MISPro</h4>
        <button class="modal-close" onclick="closeLogin()"><i class="fas fa-times"></i></button>
      </div>
      <div class="modal-body">
        <div class="modal-role-tabs">
          <button class="modal-tab" onclick="setRole('executive',this)"><i
              class="fas fa-crown me-1"></i>Executive</button>
          <button class="modal-tab active" onclick="setRole('admin',this)"><i
              class="fas fa-user-shield me-1"></i>Admin</button>
          <button class="modal-tab" onclick="setRole('staff',this)"><i class="fas fa-user me-1"></i>Staff</button>
        </div>
        <form id="loginForm" action="auth/login.php" method="GET">
          <input type="hidden" name="role" id="modalRole" value="admin">
          <button type="submit" class="modal-btn" onclick="goToLogin(event)">
            <i class="fas fa-arrow-right me-2"></i> Continue to Login
          </button>
        </form>
        <p style="text-align:center;font-size:.75rem;color:#9ca3af;margin-top:.75rem;">
          <i class="fas fa-shield-alt me-1" style="color:#f59e0b;"></i>
          Secured with Google Authenticator 2FA
        </p>
      </div>
    </div>
  </div>

  <script>
    function openLogin(e) { e && e.preventDefault(); document.getElementById('loginModal').classList.add('show'); }
    function closeLogin() { document.getElementById('loginModal').classList.remove('show'); }
    document.getElementById('loginModal').addEventListener('click', function (e) {
      if (e.target === this) closeLogin();
    });

    function setRole(role, btn) {
      document.querySelectorAll('.modal-tab').forEach(t => t.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('modalRole').value = role;
      const bw = document.getElementById('branchWrap');
      if (role === 'executive') { bw.classList.remove('show'); }
      else { bw.classList.add('show'); }
    }

    function goToLogin(e) {
      e.preventDefault();
      const role = document.getElementById('modalRole').value;
      const branch = document.querySelector('[name="branch"]')?.value || '';
      let url = 'auth/login.php?role=' + role;
      if (branch) url += '&branch_id=' + branch;
      window.location.href = url;
    }
    const sections = document.querySelectorAll("section");
    const navLinks = document.querySelectorAll(".nav-links a");

    window.addEventListener('load', () => openLogin());

    window.addEventListener("scroll", () => {
      let current = "";

      sections.forEach(section => {
        const sectionTop = section.offsetTop - 120;
        const sectionHeight = section.clientHeight;

        if (pageYOffset >= sectionTop && pageYOffset < sectionTop + sectionHeight) {
          current = section.getAttribute("id");
        }
      });

      navLinks.forEach(link => {
        link.classList.remove("active");

        if (link.getAttribute("href") === "#" + current) {
          link.classList.add("active");
        }
      });
    });
  </script>
</body>

</html>
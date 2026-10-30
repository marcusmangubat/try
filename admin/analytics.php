  <?php
  // analytics.php  –  DMW RO IV-A Admin Analytics
  session_start();
  require_once '../db.php';

  if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
  }

  date_default_timezone_set('Asia/Manila');
  $hour     = (int) date('H');
  $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

  $admin_name = $_SESSION['admin_name'] ?? '';
  if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $admin_name = $stmt->get_result()->fetch_assoc()['full_name'] ?? 'Admin';
    $stmt->close();
  }
  $parts    = explode(' ', trim($admin_name));
  $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr(end($parts), 0, 1));
  ?>
  <!DOCTYPE html>
  <html lang="en">

  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Analytics — DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <style>
      /* Skeleton */
      .skeleton {
        background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
        background-size: 200% 100%;
        animation: shimmer 1.4s infinite;
        border-radius: 6px
      }

      @keyframes shimmer {
        0% {
          background-position: 200% 0
        }

        100% {
          background-position: -200% 0
        }
      }

      .sk-num {
        height: 38px;
        width: 64px;
        display: inline-block
      }

      .sk-row {
        height: 14px;
        border-radius: 4px;
        margin: 6px 0
      }

      .sk-bar {
        height: 7px;
        border-radius: 4px;
        margin: 8px 0
      }

      /* KPI top-bar accent */
      .kpi-top-bar::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        border-radius: 14px 14px 0 0;
        background: var(--c)
      }

      /* Fade in */
      .fade-in {
        opacity: 0;
        transform: translateY(10px);
        animation: fi .4s ease forwards
      }

      @keyframes fi {
        to {
          opacity: 1;
          transform: translateY(0)
        }
      }

      .d1 {
        animation-delay: .05s
      }

      .d2 {
        animation-delay: .1s
      }

      .d3 {
        animation-delay: .15s
      }

      .d4 {
        animation-delay: .2s
      }

      .d5 {
        animation-delay: .25s
      }

      .topbar-brand img {
        width: 42px;
        height: auto;
        display: block;
        background: transparent;
        filter: none;

      }

      [data-theme="dark"] .bg-white {
        background-color: #1e293b !important;
      }

      [data-theme="dark"] .skeleton {
        background: linear-gradient(90deg, #263449 25%, #334155 50%, #263449 75%);
        background-size: 200% 100%;
      }

      /* Icon chips on KPI cards + avatars */
      [data-theme="dark"] .bg-blue-50 {
        background-color: rgba(59, 130, 246, .15) !important;
      }

      [data-theme="dark"] .bg-amber-50 {
        background-color: rgba(217, 119, 6, .15) !important;
      }

      [data-theme="dark"] .bg-indigo-50 {
        background-color: rgba(99, 102, 241, .15) !important;
      }

      [data-theme="dark"] .bg-emerald-50 {
        background-color: rgba(16, 185, 129, .15) !important;
      }

      [data-theme="dark"] .bg-blue-100 {
        background-color: rgba(59, 130, 246, .22) !important;
      }

      [data-theme="dark"] .bg-amber-100 {
        background-color: rgba(217, 119, 6, .22) !important;
      }

      [data-theme="dark"] .bg-indigo-100 {
        background-color: rgba(99, 102, 241, .22) !important;
      }

      [data-theme="dark"] .bg-emerald-100 {
        background-color: rgba(16, 185, 129, .22) !important;
      }

      [data-theme="dark"] .bg-orange-100 {
        background-color: rgba(234, 88, 12, .22) !important;
      }

      [data-theme="dark"] .bg-slate-200 {
        background-color: #334155 !important;
      }

      /* Progress-bar tracks / hover states / empty-state icon circles */
      [data-theme="dark"] .bg-slate-100 {
        background-color: #263449 !important;
      }

      [data-theme="dark"] .bg-slate-50 {
        background-color: #1a2332 !important;
      }

      [data-theme="dark"] .hover\:bg-slate-50:hover {
        background-color: #263449 !important;
      }

      [data-theme="dark"] .hover\:bg-slate-50\/70:hover {
        background-color: rgba(38, 52, 73, .7) !important;
      }

      /* Alert / info boxes (stage-alert, bottleneck, overdue, svc-peak, staff-info) */
      [data-theme="dark"] .bg-amber-50.border-amber-200 {
        background-color: rgba(217, 119, 6, .12) !important;
        border-color: rgba(251, 191, 36, .35) !important;
      }

      [data-theme="dark"] .bg-red-50.border-red-200 {
        background-color: rgba(220, 38, 38, .12) !important;
        border-color: rgba(248, 113, 113, .35) !important;
      }

      [data-theme="dark"] .bg-emerald-50.border-emerald-200 {
        background-color: rgba(16, 185, 129, .12) !important;
        border-color: rgba(52, 211, 153, .35) !important;
      }

      [data-theme="dark"] .bg-blue-50.border-blue-200 {
        background-color: rgba(59, 130, 246, .12) !important;
        border-color: rgba(96, 165, 250, .35) !important;
      }

      /* Rank badges (Top Services list) + amber/slate ring tints */
      [data-theme="dark"] .ring-amber-200 {
        --tw-ring-color: rgba(251, 191, 36, .35) !important;
      }

      [data-theme="dark"] .ring-slate-300 {
        --tw-ring-color: rgba(148, 163, 184, .35) !important;
      }

      [data-theme="dark"] .ring-orange-200 {
        --tw-ring-color: rgba(251, 146, 60, .35) !important;
      }

      /* Doughnut chart white borders would look like rings in dark mode —
   handled via JS below, but keep the legend/segment container transparent */
      [data-theme="dark"] canvas {
        background: transparent;
      }

      /* Custom pill-select dropdown panel (built in JS with bg-white) */
      [data-theme="dark"] [role="listbox"].bg-white {
        background-color: #1e293b !important;
        border-color: #2d3748 !important;
      }

      [data-theme="dark"] [role="option"].hover\:bg-\[\#f1f5fb\]:hover {
        background-color: #263449 !important;
      }

      [data-theme="dark"] .bg-blue-50.text-navy {
        background-color: rgba(59, 130, 246, .18) !important;
      }

      [data-theme="dark"] .border-bdr {
        border-color: #263449 !important;
      }

      /* Scrollbars for scrollable panels (svc-list, job-orders-list,
         overdue box, and the custom filter dropdown panel) */
      .overflow-y-auto,
      [role="listbox"] {
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 #f8fafc;
      }

      .overflow-y-auto::-webkit-scrollbar,
      [role="listbox"]::-webkit-scrollbar {
        width: 8px;
      }

      .overflow-y-auto::-webkit-scrollbar-track,
      [role="listbox"]::-webkit-scrollbar-track {
        background: #f8fafc;
      }

      .overflow-y-auto::-webkit-scrollbar-thumb,
      [role="listbox"]::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
      }

      .overflow-y-auto::-webkit-scrollbar-thumb:hover,
      [role="listbox"]::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
      }

      [data-theme="dark"] .overflow-y-auto,
      [data-theme="dark"] [role="listbox"] {
        scrollbar-width: thin;
        scrollbar-color: #475569 #1e293b;
      }

      [data-theme="dark"] .overflow-y-auto::-webkit-scrollbar-track,
      [data-theme="dark"] [role="listbox"]::-webkit-scrollbar-track {
        background: #1e293b;
      }

      [data-theme="dark"] .overflow-y-auto::-webkit-scrollbar-thumb,
      [data-theme="dark"] [role="listbox"]::-webkit-scrollbar-thumb {
        background: #475569;
      }

      [data-theme="dark"] .overflow-y-auto::-webkit-scrollbar-thumb:hover,
      [data-theme="dark"] [role="listbox"]::-webkit-scrollbar-thumb:hover {
        background: #64748b;
      }
    </style>
    <script>
      (function() {
        const t = localStorage.getItem('dmw_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
      })();
    </script>
  </head>

  <body class="bg-surface text-t1 min-h-screen pb-24 font-sans">

    <!-- ── TOPBAR ──── -->
    <header class="topbar">
      <div class="topbar-brand">
        <img src="../pictures/dmw_logo.png" alt="DMW" class="h-9">
        <div class="brand-divider"></div>
        <div class="brand-text">
          <h1>Department of Migrant Workers</h1>
          <p>REGIONAL OFFICE IV-A &nbsp;–&nbsp; ADMIN PORTAL</p>
        </div>
      </div>
      <div class="topbar-right">
        <span class="topbar-date" id="live-date"></span>

        <!-- Dark mode toggle -->
        <button type="button" class="theme-toggle" title="Toggle dark mode">
          <i class="fas fa-moon"></i>
          <i class="fas fa-sun"></i>
        </button>

        <div class="tb-btn"><i class="fas fa-bell"></i></div>
        <?php include __DIR__ . '/avatar-menu.php'; ?>
      </div>
    </header>

    <!-- ── SUBBAR ──── -->
    <div class="subbar">
      <div class="subbar-top">
        <div class="subbar-greeting">
          <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($admin_name) ?></h2>
          <p>Analytics overview — migrant workers application management</p>
        </div>
      </div>
      <div class="filter-scroll">
        <label class="filter-pill">
          <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
          <select id="filter-month" autocomplete="off">
            <option value="all" selected>All Time (<?= date('Y') ?>)</option>
            <?php
            for ($m = 1; $m <= 12; $m++):
              $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
              $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
            ?>
              <option value="<?= $val ?>"><?= $lbl ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label class="filter-pill">
          <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
          <select id="filter-province">
            <option value="">CALABARZON</option>
            <option value="Laguna">Laguna</option>
            <option value="Batangas">Batangas</option>
            <option value="Cavite">Cavite</option>
            <option value="Rizal">Rizal</option>
            <option value="Quezon">Quezon</option>
          </select>
        </label>
        <label class="filter-pill">
          <i class="fas fa-globe" style="color:var(--indigo)"></i>
          <select id="filter-country">
            <option value="">All Destinations</option>
          </select>
        </label>
      </div>
    </div>

    <!-- ── MAIN ────── -->
    <div class="px-3.5 pt-3.5 max-w-[1400px] mx-auto lg:px-8 lg:pt-5">

      <!-- ▸ SECTION HEADER: SUMMARY -->
      <div class="flex items-center gap-2 text-[10px] font-extrabold tracking-[.1em] uppercase text-t3 mb-2.5 pb-2 border-b border-bdr fade-in d1">
        <i class="fas fa-chart-line text-brand-lt"></i> Summary — all applications
      </div>

      <!-- KPI Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mb-4 fade-in d1">
        <!-- Total Applications -->
        <div class="kpi-top-bar bg-white border border-bdr rounded-[14px] p-4 relative overflow-hidden" style="--c:#1d4ed8">
          <div class="flex items-start justify-between mb-1">
            <div class="text-[9.5px] font-bold tracking-[.06em] uppercase text-t3">Total Applications</div>
            <div class="w-[34px] h-[34px] rounded-[9px] bg-blue-50 text-brand-lt flex items-center justify-center text-sm shrink-0"><i class="fas fa-folder-open"></i></div>
          </div>
          <div class="font-serif text-[28px] lg:text-[38px] leading-none text-navy skeleton sk-num" id="kpi-total"></div>
          <div class="text-[10px] text-t3 font-medium mt-1" id="kpi-total-sub"></div>
        </div>
        <!-- Unassigned -->
        <div class="kpi-top-bar bg-white border border-bdr rounded-[14px] p-4 relative overflow-hidden" style="--c:#d97706">
          <div class="flex items-start justify-between mb-1">
            <div class="text-[9.5px] font-bold tracking-[.06em] uppercase text-t3">Unassigned</div>
            <div class="w-[34px] h-[34px] rounded-[9px] bg-amber-50 text-warn flex items-center justify-center text-sm shrink-0"><i class="fas fa-hourglass-half"></i></div>
          </div>
          <div class="font-serif text-[28px] lg:text-[38px] leading-none text-warn skeleton sk-num" id="kpi-unassigned"></div>
          <div class="text-[10px] text-warn font-medium mt-1" id="kpi-unassigned-sub"></div>
        </div>
        <!-- Active Evaluators -->
        <div class="kpi-top-bar bg-white border border-bdr rounded-[14px] p-4 relative overflow-hidden" style="--c:#4f46e5">
          <div class="flex items-start justify-between mb-1">
            <div class="text-[9.5px] font-bold tracking-[.06em] uppercase text-t3">Active Evaluators</div>
            <div class="w-[34px] h-[34px] rounded-[9px] bg-indigo-50 text-indigo flex items-center justify-center text-sm shrink-0"><i class="fas fa-users-gear"></i></div>
          </div>
          <div class="font-serif text-[28px] lg:text-[38px] leading-none text-indigo skeleton sk-num" id="kpi-evaluators"></div>
          <div class="text-[10px] text-t3 font-medium mt-1" id="kpi-evaluators-sub"></div>
        </div>
        <!-- Registered Clients -->
        <div class="kpi-top-bar bg-white border border-bdr rounded-[14px] p-4 relative overflow-hidden" style="--c:#059669">
          <div class="flex items-start justify-between mb-1">
            <div class="text-[9.5px] font-bold tracking-[.06em] uppercase text-t3">Registered Clients</div>
            <div class="w-[34px] h-[34px] rounded-[9px] bg-emerald-50 text-ok flex items-center justify-center text-sm shrink-0"><i class="fas fa-users"></i></div>
          </div>
          <div class="font-serif text-[28px] lg:text-[38px] leading-none text-ok skeleton sk-num" id="kpi-clients"></div>
          <div class="text-[10px] text-t3 font-medium mt-1" id="kpi-clients-sub"></div>
        </div>
      </div>

      <!-- ▸ SECTION HEADER: PROCESS MONITORING -->
      <div class="flex items-center gap-2 text-[10px] font-extrabold tracking-[.1em] uppercase text-t3 mb-2.5 mt-5 pb-2 border-b border-bdr fade-in d2">
        <i class="fas fa-sitemap text-brand-lt"></i> Process Monitoring — Application Workflow
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3 fade-in d2">

        <!-- Stage counts -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center justify-between mb-3.5">
            <div class="flex items-center gap-1.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
              <span class="w-[7px] h-[7px] rounded-full bg-brand-lt shrink-0"></span>Applications by Stage
            </div>
            <a href="applications.php" class="text-[11px] font-semibold text-brand-lt opacity-75 flex items-center gap-1">
              All <i class="fas fa-arrow-right text-[9px]"></i>
            </a>
          </div>
          <div id="stage-list">
            <div class="skeleton sk-row" style="width:100%"></div>
            <div class="skeleton sk-row" style="width:85%"></div>
            <div class="skeleton sk-row" style="width:70%"></div>
            <div class="skeleton sk-row" style="width:55%"></div>
          </div>
          <div id="stage-alert" class="hidden items-center gap-1.5 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5 mt-3 text-[11px] text-t2">
            <i class="fas fa-triangle-exclamation text-warn text-[10px]"></i>
            <span id="stage-alert-text"></span>
          </div>
        </div>

        <!-- Avg time per stage -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center gap-1.5 mb-3.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
            <span class="w-[7px] h-[7px] rounded-full bg-warn shrink-0"></span>Avg. Time per Stage
          </div>
          <div id="avg-time-list">
            <div class="skeleton sk-bar" style="width:100%"></div>
            <div class="skeleton sk-bar" style="width:80%"></div>
            <div class="skeleton sk-bar" style="width:60%"></div>
            <div class="skeleton sk-bar" style="width:40%"></div>
          </div>
          <div id="bottleneck-alert" class="hidden items-center gap-1.5 bg-red-50 border border-red-200 rounded-lg px-2.5 py-1.5 mt-3 text-[11px] text-t2">
            <i class="fas fa-fire text-danger text-[10px]"></i>
            <span id="bottleneck-text"></span>
          </div>
          <!-- Overdue -->
          <div class="mt-3.5">
            <div class="text-[10px] font-bold tracking-[.06em] uppercase text-t3 mb-2">Pending Overdue (&gt;7 days)</div>
            <div id="overdue-box">
              <div class="skeleton sk-row" style="width:80%"></div>
            </div>
          </div>
        </div>

        <!-- Weekly trend chart -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center gap-1.5 mb-3.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
            <span class="w-[7px] h-[7px] rounded-full bg-ok shrink-0"></span>Weekly Submission Trend
          </div>
          <div class="relative h-[200px]"><canvas id="chartWeekly"></canvas></div>
          <div class="flex gap-3.5 flex-wrap mt-2.5">
            <span class="flex items-center gap-1 text-[10.5px] font-semibold text-t3"><span class="w-2.5 h-2.5 rounded-[3px] bg-blue-400 shrink-0"></span>Submitted</span>
            <span class="flex items-center gap-1 text-[10.5px] font-semibold text-t3"><span class="w-2.5 h-2.5 rounded-[3px] bg-emerald-600 shrink-0"></span>Approved</span>
            <span class="flex items-center gap-1 text-[10.5px] font-semibold text-t3"><span class="w-2.5 h-2.5 rounded-[3px] bg-red-500 shrink-0"></span>Disapproved</span>
          </div>
        </div>
      </div>

      <!-- ▸ SECTION HEADER: SERVICE MONITORING -->
      <div class="flex items-center gap-2 text-[10px] font-extrabold tracking-[.1em] uppercase text-t3 mb-2.5 mt-5 pb-2 border-b border-bdr fade-in d3">
        <i class="fas fa-briefcase text-indigo"></i> Service Monitoring — RSF / Job Orders
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3 fade-in d3">

        <!-- Top services -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center justify-between mb-1">
            <div class="flex items-center gap-1.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
              <span class="w-[7px] h-[7px] rounded-full bg-indigo shrink-0"></span>Top Services by Volume
            </div>
            <a href="job-orders.php" class="text-[11px] font-semibold text-indigo opacity-75 flex items-center gap-1 shrink-0">
              All <i class="fas fa-arrow-right text-[9px]"></i>
            </a>
          </div>
          <div class="text-[10px] text-t3 font-medium mb-3">Ranked by total applications received</div>
          <div id="svc-list" class="max-h-[360px] overflow-y-auto pr-1">
            <div class="skeleton sk-row" style="width:100%;height:56px;border-radius:10px;margin-bottom:8px"></div>
            <div class="skeleton sk-row" style="width:100%;height:56px;border-radius:10px;margin-bottom:8px"></div>
            <div class="skeleton sk-row" style="width:100%;height:56px;border-radius:10px"></div>
          </div>
        </div>

        <!-- Job orders posted vs applications received -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center gap-1.5 mb-3.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
            <span class="w-[7px] h-[7px] rounded-full bg-yellow-600 shrink-0"></span>Job Orders Posted vs Applications Received
          </div>
          <div class="relative h-[200px]"><canvas id="chartSvcUsage"></canvas></div>
          <div class="flex gap-3.5 flex-wrap mt-2.5">
            <span class="flex items-center gap-1 text-[10.5px] font-semibold text-t3"><span class="w-2.5 h-2.5 rounded-[3px] bg-indigo-500 shrink-0"></span>Job Orders Posted</span>
            <span class="flex items-center gap-1 text-[10.5px] font-semibold text-t3"><span class="w-2.5 h-2.5 rounded-[3px] bg-amber-500 shrink-0"></span>Applications Received</span>
          </div>
          <div id="svc-monthly-stats" class="grid grid-cols-3 gap-2 mt-3.5 pt-3.5 border-t border-bdr">
            <div class="text-center">
              <div class="font-serif text-[18px] leading-none text-indigo skeleton sk-num" style="width:100%;height:22px" id="svc-stat-posted"></div>
              <div class="text-[9px] font-bold tracking-[.05em] uppercase text-t3 mt-1">Total Posted</div>
            </div>
            <div class="text-center">
              <div class="font-serif text-[18px] leading-none text-warn skeleton sk-num" style="width:100%;height:22px" id="svc-stat-received"></div>
              <div class="text-[9px] font-bold tracking-[.05em] uppercase text-t3 mt-1">Total Received</div>
            </div>
            <div class="text-center">
              <div class="font-serif text-[18px] leading-none text-ok skeleton sk-num" style="width:100%;height:22px" id="svc-stat-ratio"></div>
              <div class="text-[9px] font-bold tracking-[.05em] uppercase text-t3 mt-1">Apps / Order</div>
            </div>
          </div>
          <div id="svc-peak-box" class="hidden items-center gap-1.5 bg-blue-50 border border-blue-200 rounded-lg px-2.5 py-1.5 mt-3 text-[11px] text-t2">
            <i class="fas fa-chart-line text-brand-lt text-[10px]"></i>
            <span id="svc-peak-text"></span>
          </div>
        </div>
        <!-- Top destinations + donut -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center gap-1.5 mb-3.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
            <span class="w-[7px] h-[7px] rounded-full bg-ok shrink-0"></span>Top Destination Countries
          </div>
          <div id="dest-bars">
            <div class="skeleton sk-bar" style="width:100%"></div>
            <div class="skeleton sk-bar" style="width:65%"></div>
          </div>
          <div class="flex items-center gap-3 pt-3 border-t border-bdr mt-3">
            <div class="shrink-0"><canvas id="chartPie" width="130" height="130"></canvas></div>
            <div id="pie-legend" class="flex-1"></div>
          </div>
        </div>
      </div>

      <!-- ▸ SECTION HEADER: ADMIN PERFORMANCE -->
      <div class="flex items-center gap-2 text-[10px] font-extrabold tracking-[.1em] uppercase text-t3 mb-2.5 mt-5 pb-2 border-b border-bdr fade-in d4">
        <i class="fas fa-users-gear text-ok"></i> Administrative Performance — Evaluator Staff
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-[2fr_1fr] gap-3 mb-8 fade-in d4">

        <!-- Staff list -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center gap-1.5 mb-3.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
            <span class="w-[7px] h-[7px] rounded-full bg-ok shrink-0"></span>Evaluator Performance &amp; Outcomes
          </div>
          <div id="staff-list" class="overflow-y-auto pr-1">
            <div class="skeleton sk-row" style="width:100%;height:48px;border-radius:10px;margin-bottom:8px"></div>
            <div class="skeleton sk-row" style="width:100%;height:48px;border-radius:10px;margin-bottom:8px"></div>
            <div class="skeleton sk-row" style="width:100%;height:48px;border-radius:10px"></div>
          </div>
          <div id="staff-info" class="hidden items-center gap-1.5 bg-blue-50 border border-blue-200 rounded-lg px-2.5 py-1.5 mt-3 text-[11px] text-t2">
            <i class="fas fa-info-circle text-brand-lt text-[10px]"></i>
            <span id="staff-info-text"></span>
          </div>
        </div>

        <!-- Workload donut + active job orders -->
        <div class="bg-white border border-bdr rounded-[14px] p-4">
          <div class="flex items-center gap-1.5 mb-3.5 text-[10.5px] font-bold tracking-[.07em] uppercase text-t2">
            <span class="w-[7px] h-[7px] rounded-full bg-brand-lt shrink-0"></span>Workload Distribution
          </div>
          <div class="flex items-center gap-3">
            <div class="shrink-0"><canvas id="chartWorkload" width="130" height="130"></canvas></div>
            <div id="workload-legend" class="flex-1"></div>
          </div>
          <div class="mt-4 pt-3.5 border-t border-bdr">
            <div class="text-[10px] font-bold tracking-[.06em] uppercase text-t3 mb-2.5">Active Job Orders</div>
            <div id="job-orders-list" class="max-h-[186px] overflow-y-auto pr-1">
              <div class="skeleton sk-row" style="width:100%;height:52px;border-radius:8px;margin-bottom:6px"></div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /main -->

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
      <nav class="dock">
        <a href="dashboard.php" class="dk" data-tip="Dashboard">
          <i class="fas fa-house"></i>
          <span class="dk-lbl">Home</span>
        </a>
        <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
          <?php
          // Dynamic badge count
          $badge_stmt = $database->prepare("
                  SELECT COUNT(*) as total 
                  FROM applications 
                  WHERE status IN ('pending', 'for_compliance', 'resubmitted')
              ");
          $badge_stmt->execute();
          $badge_result = $badge_stmt->get_result()->fetch_assoc();
          $active_apps = $badge_result['total'] ?? 0;
          ?>
          <span class="dk-badge"><?= $active_apps ?></span>
          <i class="fas fa-file-lines"></i>
          <span class="dk-lbl">Apps</span>
          <div class="flyout" id="appsMenu">
            <div class="flyout-arr"></div>
            <a href="applications.php" class="fi"><i class="fas fa-list" style="color:#60a5fa"></i>All Applications</a>
            <a href="applications.php?status=pending" class="fi"><i class="fas fa-clock" style="color:#fbbf24"></i>Pending</a>
            <a href="applications.php?status=unassign" class="fi"><i class="fas fa-user-slash" style="color:#f87171"></i>Unassign</a>
            <a href="applications.php?status=assign" class="fi"><i class="fas fa-user-check" style="color:#60a5fa"></i>Assign</a>
            <a href="applications.php?status=under_evaluation" class="fi"><i class="fas fa-search" style="color:#a78bfa"></i>Under Evaluation</a>
            <a href="applications.php?status=for_compliance" class="fi"><i class="fas fa-triangle-exclamation" style="color:#fb923c"></i>For Compliance</a>
            <a href="applications.php?status=approved" class="fi"><i class="fas fa-check-circle" style="color:#34d399"></i>Approved</a>
            <a href="applications.php?status=disapproved" class="fi"><i class="fas fa-times-circle" style="color:#f87171"></i>Disapproved</a>
          </div>
        </div>
        <div class="dk-div"></div>
        <!-- <a href="assignments.html" class="dk" data-tip="Assignments">
          <i class="fas fa-list-check"></i>
          <span class="dk-lbl">Assign</span>-->
        </a>
        <a href="job-orders.php" class="dk" data-tip="Job Orders">
          <i class="fas fa-briefcase"></i>
          <span class="dk-lbl">RSF</span>
        </a>
        <a href="reports.php" class="dk" data-tip="Reports">
          <i class="fas fa-chart-bar"></i>
          <span class="dk-lbl">Reports</span>
        </a>
        <a href="applicant-reports.php" class="dk" data-tip="Applicant Reports">
          <i class="fas fa-file-contract"></i>
          <span class="dk-lbl">Applicants</span>
        </a>
        <a href="analytics.php" class="dk active">
          <i class="fas fa-chart-bar text-blue-300 text-base leading-none"></i>
          <span class="dk-lbl">Analytics</span>
        </a>
        <a href="user.php" class="dk" data-tip="Users">
          <i class="fas fa-users-gear"></i>
          <span class="dk-lbl">Users</span>
        </a>
        <div class="dk-div"></div>
        <a href="settings.php" class="dk" data-tip="Settings">
          <i class="fas fa-gear"></i>
          <span class="dk-lbl">Settings</span>
        </a>
        <div class="dk" data-tip="Logout" onclick="logout('Admin')">
          <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
          <span class="dk-lbl" style="color:#f87171">Logout</span>
        </div>
      </nav>
    </div>

    <!-- JAVASCRIPT  -->
    <script>
      const API = 'analytics-api.php';

      function isDarkMode() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
      }

      function chartTextColor() {
        return isDarkMode() ? '#94a3b8' : '#475569';
      }

      function chartGridColor() {
        return isDarkMode() ? '#2d3748' : '#f1f5f9';
      }

      function chartRingColor() {
        return isDarkMode() ? '#1e293b' : '#fff';
      }

      function getChartOpts() {
        return {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              },
              ticks: {
                font: {
                  family: 'DM Sans',
                  size: 10
                },
                maxRotation: 0,
                color: chartTextColor()
              }
            },
            y: {
              grid: {
                color: chartGridColor()
              },
              ticks: {
                font: {
                  family: 'DM Sans',
                  size: 10
                },
                color: chartTextColor()
              }
            }
          }
        };
      }
      let chartWeekly = null,
        chartSvc = null,
        chartPie = null,
        chartWorkload = null;

      function getFilters() {
        const f = {};
        const month = document.getElementById('filter-month')?.value;
        const province = document.getElementById('filter-province')?.value;
        const country = document.getElementById('filter-country')?.value;
        if (month) f.month = month;
        if (province) f.province = province;
        if (country) f.country = country;
        return f;
      }

      function api(action, params = {}) {
        const qs = new URLSearchParams({
          action,
          ...getFilters(),
          ...params
        }).toString();
        return fetch(`${API}?${qs}`).then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        });
      }

      function rmSk(el) {
        if (el) {
          el.classList.remove('skeleton', 'sk-num', 'sk-row', 'sk-bar');
        }
      }

      /* Wraps a native <select> with a compact styled trigger + dropdown
         panel. The panel is appended to <body> and positioned `fixed`
         (from the trigger's on-screen position) so it never stretches
         .filter-scroll's horizontal scrollbar. The original <select>
         stays in the DOM (hidden) — its `id` and `.value` keep working
         exactly as before, so api() and the filter-month change listener
         below need zero changes. */
      function enhancePillSelect(select) {
        if (!select) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'relative inline-flex items-center';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('hidden');
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.className = 'inline-flex items-center gap-1.5 bg-transparent border-none cursor-pointer p-0 m-0 font-inherit text-inherit';
        trigger.innerHTML = '<span class="truncate max-w-[110px]"></span>' +
          '<i class="fas fa-chevron-down text-[9px] opacity-60 transition-transform flex-shrink-0"></i>';
        wrapper.appendChild(trigger);

        const panel = document.createElement('div');
        panel.setAttribute('role', 'listbox');
        panel.className = 'hidden fixed w-56 bg-white border-[1.5px] border-bdr rounded-lg shadow-[0_10px_30px_rgba(11,31,75,.16)] z-[999] overflow-hidden py-1 max-h-[240px] overflow-y-auto';
        document.body.appendChild(panel);

        function buildRow(optionEl) {
          const row = document.createElement('div');
          row.setAttribute('role', 'option');
          row.className = 'flex items-center justify-between gap-2 py-[7px] px-3 text-[12px] text-t1 cursor-pointer hover:bg-[#f1f5fb] transition-colors';
          row.dataset.value = optionEl.value;
          const label = document.createElement('span');
          label.className = 'truncate';
          label.textContent = optionEl.textContent;
          row.appendChild(label);
          row.addEventListener('click', () => {
            select.value = optionEl.value;
            closePanel();
            refresh();
            select.dispatchEvent(new Event('change', {
              bubbles: true
            }));
          });
          return row;
        }

        function renderList() {
          panel.innerHTML = '';
          Array.from(select.options).forEach(opt => panel.appendChild(buildRow(opt)));
          markSelected();
        }

        function markSelected() {
          panel.querySelectorAll('[role="option"]').forEach(row => {
            const selected = row.dataset.value === select.value;
            row.classList.toggle('bg-blue-50', selected);
            row.classList.toggle('text-navy', selected);
            row.classList.toggle('font-bold', selected);
            let check = row.querySelector('.fa-check');
            if (selected && !check) {
              const icon = document.createElement('i');
              icon.className = 'fas fa-check text-[10px] text-brand-lt flex-shrink-0';
              row.appendChild(icon);
            } else if (!selected && check) {
              check.remove();
            }
          });
        }

        function refresh() {
          const opt = select.options[select.selectedIndex];
          trigger.querySelector('span').textContent = opt ? opt.textContent : '';
          markSelected();
        }

        function positionPanel() {
          const rect = trigger.getBoundingClientRect();
          const panelWidth = 224;
          let left = rect.left;
          const maxLeft = window.innerWidth - panelWidth - 8;
          if (left > maxLeft) left = Math.max(8, maxLeft);
          panel.style.top = (rect.bottom + 6) + 'px';
          panel.style.left = left + 'px';
        }

        function onReposition() {
          if (!panel.classList.contains('hidden')) positionPanel();
        }

        function openPanel() {
          document.querySelectorAll('[role="listbox"]').forEach(p => {
            if (p !== panel) p.classList.add('hidden');
          });
          renderList();
          positionPanel();
          panel.classList.remove('hidden');
          trigger.setAttribute('aria-expanded', 'true');
          trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
          window.addEventListener('scroll', onReposition, true);
          window.addEventListener('resize', onReposition);
        }

        function closePanel() {
          panel.classList.add('hidden');
          trigger.setAttribute('aria-expanded', 'false');
          trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
          window.removeEventListener('scroll', onReposition, true);
          window.removeEventListener('resize', onReposition);
        }

        trigger.addEventListener('click', (e) => {
          e.stopPropagation();
          panel.classList.contains('hidden') ? openPanel() : closePanel();
        });
        trigger.addEventListener('keydown', (e) => {
          if (['Enter', ' ', 'ArrowDown'].includes(e.key)) {
            e.preventDefault();
            openPanel();
          }
        });
        panel.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') {
            closePanel();
            trigger.focus();
          }
        });
        panel.addEventListener('click', (e) => e.stopPropagation());
        document.addEventListener('click', closePanel);

        refresh();
        select.addEventListener('pill-refresh', refresh);
      }

      /* ── Live date + time ── */
      function updateLiveDateTime() {
        const el = document.getElementById('live-date');
        if (!el) return;

        const now = new Date();

        const datePart = now.toLocaleDateString('en-PH', {
          weekday: 'short',
          year: 'numeric',
          month: 'short',
          day: 'numeric'
        }).toUpperCase();

        const timePart = now.toLocaleTimeString('en-PH', {
          hour: 'numeric',
          minute: '2-digit',
          second: '2-digit',
          hour12: true
        }).toLowerCase();

        el.textContent = `${datePart} | ${timePart}`;
      }

      updateLiveDateTime();
      setInterval(updateLiveDateTime, 1000); // refresh every second

      /* ── 1. SUMMARY KPIs  */
      async function loadSummary() {
        const d = await api('summary');

        const tv = document.getElementById('kpi-total');
        tv.textContent = d.total.toLocaleString();
        rmSk(tv);
        document.getElementById('kpi-total-sub').textContent = 'All time';

        const uv = document.getElementById('kpi-unassigned');
        uv.textContent = d.unassigned.toLocaleString();
        rmSk(uv);
        const us = document.getElementById('kpi-unassigned-sub');
        us.textContent = d.unassigned > 0 ? 'Awaiting evaluator' : 'All assigned';
        us.style.color = d.unassigned > 0 ? '#d97706' : '#059669';

        const ev = document.getElementById('kpi-evaluators');
        ev.textContent = d.evaluators.toLocaleString();
        rmSk(ev);
        document.getElementById('kpi-evaluators-sub').textContent = 'Registered staff';

        const cv = document.getElementById('kpi-clients');
        cv.textContent = d.clients.toLocaleString();
        rmSk(cv);
        document.getElementById('kpi-clients-sub').textContent = 'Total applicants';
      }

      /* ── 2. STAGE LIST ───────────────────────────────────────── */
      async function loadStages() {
        const items = await api('stages');
        const el = document.getElementById('stage-list');
        el.innerHTML = '';

        let max = {
          label: '',
          count: 0
        };
        items.forEach(s => {
          if (s.count > max.count) max = s;
        });

        items.forEach(s => {
          if (s.count === 0) return; // skip empty stages
          el.innerHTML += `
      <div class="flex items-center py-1.5 border-b border-bdr last:border-0">
        <span class="w-2 h-2 rounded-full shrink-0" style="background:${s.color}"></span>
        <span class="text-[12.5px] font-semibold flex-1 ml-2">${s.label}</span>
        <span class="text-[12.5px] font-bold mr-2.5" style="color:${s.color}">${s.count}</span>
        <div class="w-14 h-1 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width:${s.pct}%;background:${s.color}"></div>
        </div>
      </div>`;
        });

        if (max.count > 0) {
          const alert = document.getElementById('stage-alert');
          document.getElementById('stage-alert-text').innerHTML =
            `Most apps are in <b class="text-warn">${max.label}</b> (${max.count})`;
          alert.classList.remove('hidden');
          alert.classList.add('flex');
        }
      }

      /* ── 3. AVG TIME PER STAGE  */
      function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('en-US', {
          month: 'long',
          day: 'numeric',
          year: 'numeric'
        });
      }

      function formatDuration(hours) {
        if (!hours || hours <= 0) return '—';
        if (hours >= 24) {
          const d = Math.floor(hours / 24);
          const h = Math.round(hours % 24);
          return `${d}d ${h}h`;
        }
        const h = Math.floor(hours);
        const m = Math.round((hours - h) * 60);
        return `${h}h ${m}m`;
      }

      async function loadAvgTime() {
        const items = await api('avg_time');
        const el = document.getElementById('avg-time-list');
        el.innerHTML = '';

        const barColors = ['#3b82f6', '#f59e0b', '#ef4444', '#059669'];

        items.forEach((s, i) => {
          el.innerHTML += `
      <div class="flex items-center gap-2">
        <span class="text-[11.5px] font-semibold text-t2 w-20 shrink-0">${s.label}</span>
        <div class="flex-1 h-[7px] bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width:${s.pct}%;background:${barColors[i]}"></div>
        </div>
        <span class="text-[11px] font-bold text-t3 w-16 text-right">${formatDuration(s.hours)}</span>
      </div>`;
        });

        // Identify bottleneck (max hours)
        const worst = items.reduce((a, b) => b.hours > a.hours ? b : a, items[0]);
        if (worst && worst.hours > 0) {
          const total = items.reduce((sum, s) => sum + s.hours, 0) || 1;
          const pct = Math.round(worst.hours / total * 100);
          const bk = document.getElementById('bottleneck-alert');
          document.getElementById('bottleneck-text').innerHTML =
            `Bottleneck: <b class="text-danger">${worst.label}</b> takes ${pct}% of total time`;
          bk.classList.remove('hidden');
          bk.classList.add('flex');
        }
      }

      /* ── 4. OVERDUE  */
      async function loadOverdue() {
        const d = await api('overdue');
        const el = document.getElementById('overdue-box');

        if (d.count === 0) {
          el.innerHTML = `<div class="flex items-center gap-1.5 bg-emerald-50 border border-emerald-200 rounded-lg px-2.5 py-2 text-[11px] text-t2">
      <i class="fas fa-circle-check text-ok text-[10px]"></i>
      <span>No overdue applications — pipeline is within threshold.</span></div>`;
        } else {
          let rows = '';
          d.items.forEach(a => {
            rows += `<a href="view-application.php?id=${a.id}" class="block text-[10.5px] text-t3 border-b border-bdr pb-1 mb-1 hover:bg-slate-50 hover:text-t1 transition-colors rounded px-1 -mx-1">
    ${a.applicant} · <b>${a.days_waiting}d</b> waiting</a>`;
          });

          el.innerHTML = `<div class="flex items-center gap-1.5 bg-red-50 border border-red-200 rounded-lg px-2.5 py-2 text-[11px] text-t2 mb-2">
      <i class="fas fa-triangle-exclamation text-danger text-[10px]"></i>
      <span><b class="text-danger">${d.count}</b> overdue application${d.count>1?'s':''} (&gt;7 days)</span></div>
      <div class="max-h-[96px] overflow-y-auto pr-1">${rows}</div>`;
        }
      }

      /* ── 5. WEEKLY TREND CHART  */
      async function loadWeekly() {
        const d = await api('weekly');
        if (chartWeekly) {
          chartWeekly.destroy();
          chartWeekly = null;
        }

        chartWeekly = new Chart(document.getElementById('chartWeekly'), {
          type: 'line',
          data: {
            labels: d.labels,
            datasets: [{
                data: d.submitted,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,.08)',
                tension: .4,
                fill: true,
                borderWidth: 1.5,
                pointRadius: 2
              },
              {
                data: d.approved,
                borderColor: '#059669',
                backgroundColor: 'rgba(5,150,105,.06)',
                tension: .4,
                fill: true,
                borderWidth: 1.5,
                pointRadius: 2
              },
              {
                data: d.disapproved,
                borderColor: '#dc2626',
                tension: .4,
                borderWidth: 1.5,
                pointRadius: 2
              }
            ]
          },
          options: {
            ...getChartOpts(),
            maintainAspectRatio: false
          }
        });
      }

      /*  6. TOP SERVICES  */
      function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;'
        } [c]));
      }

      async function loadServices() {
        const items = await api('services');
        const el = document.getElementById('svc-list');
        el.innerHTML = '';

        if (!items.length) {
          el.innerHTML = `
<div class="flex flex-col items-center justify-center py-6 text-center">
  <div class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-t3 mb-2">
    <i class="fas fa-briefcase text-sm"></i>
  </div>
  <p class="text-[11px] text-t3 font-medium">No job orders found for this period.</p>
</div>`;
          return;
        }

        const rankStyle = [
          'bg-amber-100 text-amber-700 ring-1 ring-amber-200', // 1st
          'bg-slate-200 text-slate-600 ring-1 ring-slate-300', // 2nd
          'bg-orange-100 text-orange-700 ring-1 ring-orange-200' // 3rd
        ];

        items.forEach((j, i) => {
          const rankCls = rankStyle[i] ?? 'bg-slate-50 text-t3';
          const title = escapeHtml(j.title.split('(')[0].trim());
          const employer = escapeHtml(j.employer.length > 26 ? j.employer.slice(0, 26) + '…' : j.employer);
          const country = escapeHtml(j.country || '—');
          const rsf = escapeHtml(j.rsf);

          const hasSlots = j.slots > 0;
          const fillPct = hasSlots ? Math.min(100, Math.round((j.applied / j.slots) * 100)) : 0;
          const fillColor = !hasSlots ? '#94a3b8' : fillPct >= 70 ? '#059669' : fillPct >= 35 ? '#6366f1' : '#d97706';

          el.innerHTML += `
      <div class="flex items-center gap-2.5 py-2.5 ${i < items.length - 1 ? 'border-b border-bdr' : ''} hover:bg-slate-50/70 transition-colors rounded-lg px-1.5 -mx-1.5">
        <div class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold ${rankCls}">${i + 1}</div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5 mb-0.5">
            <span class="text-[12px] font-bold text-t1 truncate" title="${title}">${title}</span>
            <span class="text-[9px] font-bold text-t3 bg-slate-100 px-1.5 py-[1px] rounded shrink-0">${rsf}</span>
          </div>
          <div class="flex items-center gap-1 text-[10.5px] text-t3 font-medium truncate">
            <i class="fas fa-building text-[9px] shrink-0"></i>
            <span class="truncate">${employer}</span>
            <span class="opacity-40 shrink-0">·</span>
            <i class="fas fa-globe-asia text-[9px] shrink-0"></i>
            <span class="shrink-0">${country}</span>
          </div>
          <div class="h-[5px] bg-slate-100 rounded-full overflow-hidden mt-1.5">
            <div class="h-full rounded-full" style="width:${fillPct}%;background:${fillColor}"></div>
          </div>
        </div>
        <div class="text-right shrink-0 pl-1">
          <div class="font-serif text-[16px] font-bold leading-none" style="color:${fillColor}">${j.applied}</div>
          <div class="text-[9px] text-t3 font-semibold mt-0.5">${hasSlots ? 'of ' + j.slots + ' slots' : 'no slot cap'}</div>
        </div>
      </div>`;
        });
      }
      /*  7. MONTHLY JOB ORDERS VS APPLICATIONS CHART  */
      async function loadSvcMonthly() {
        const d = await api('svc_monthly');
        if (chartSvc) {
          chartSvc.destroy();
          chartSvc = null;
        }

        const totalPosted = d.posted.reduce((a, b) => a + b, 0);
        const totalReceived = d.received.reduce((a, b) => a + b, 0);
        const ratio = totalPosted > 0 ? (totalReceived / totalPosted).toFixed(1) : '0.0';

        const pEl = document.getElementById('svc-stat-posted');
        pEl.textContent = totalPosted.toLocaleString();
        rmSk(pEl);
        const rEl = document.getElementById('svc-stat-received');
        rEl.textContent = totalReceived.toLocaleString();
        rmSk(rEl);
        const ratEl = document.getElementById('svc-stat-ratio');
        ratEl.textContent = ratio;
        rmSk(ratEl);

        const peakBox = document.getElementById('svc-peak-box');
        if (totalReceived > 0) {
          let peakIdx = 0;
          d.received.forEach((v, i) => {
            if (v > d.received[peakIdx]) peakIdx = i;
          });
          document.getElementById('svc-peak-text').innerHTML =
            `Peak demand: <b class="text-brand-lt">${d.labels[peakIdx]}</b> — ${d.received[peakIdx]} application${d.received[peakIdx] !== 1 ? 's' : ''} received against ${d.posted[peakIdx]} order${d.posted[peakIdx] !== 1 ? 's' : ''} posted`;
          peakBox.classList.remove('hidden');
          peakBox.classList.add('flex');
        } else {
          peakBox.classList.add('hidden');
          peakBox.classList.remove('flex');
        }

        chartSvc = new Chart(document.getElementById('chartSvcUsage'), {
          type: 'bar',
          data: {
            labels: d.labels,
            datasets: [{
                label: 'Job Orders Posted',
                data: d.posted,
                backgroundColor: 'rgba(79,70,229,.75)',
                borderRadius: 4
              },
              {
                label: 'Applications Received',
                data: d.received,
                backgroundColor: 'rgba(217,119,6,.75)',
                borderRadius: 4
              }
            ]
          },
          options: {
            ...getChartOpts(),
            maintainAspectRatio: false
          }
        });
      }

      /*  8. DESTINATIONS DOUGHNUT  */
      async function loadDestinations() {
        const d = await api('destinations');
        const el = document.getElementById('dest-bars');
        el.innerHTML = '';

        if (!d.labels.length) {
          el.innerHTML = '<p class="text-[11px] text-t3 italic">No data yet.</p>';
          return;
        }

        const maxVal = Math.max(...d.data, 1);
        d.labels.forEach((l, i) => {
          el.innerHTML += `
      <div class="flex items-center gap-2 mb-1.5">
        <span class="text-[11.5px] font-semibold text-t2 w-20 shrink-0 truncate">${l}</span>
        <div class="flex-1 h-[7px] bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width:${Math.round(d.data[i]/maxVal*100)}%;background:${d.colors[i]}"></div>
        </div>
        <span class="text-[11px] font-bold text-t3 w-6 text-right">${d.data[i]}</span>
      </div>`;
        });

        if (chartPie) {
          chartPie.destroy();
          chartPie = null;
        }
        chartPie = new Chart(document.getElementById('chartPie'), {
          type: 'doughnut',
          data: {
            labels: d.labels,
            datasets: [{
              data: d.data,
              backgroundColor: d.colors,
              borderWidth: 3,
              borderColor: chartRingColor(),
              hoverOffset: 5
            }]
          },
          options: {
            cutout: '62%',
            maintainAspectRatio: true,
            plugins: {
              legend: {
                display: false
              }
            }
          }
        });

        const leg = document.getElementById('pie-legend');
        leg.innerHTML = '';
        d.legend.forEach(l => {
          leg.innerHTML += `<div class="flex items-center gap-2 py-1 border-b border-bdr last:border-0">
        <span class="w-2 h-2 rounded-[3px] shrink-0" style="background:${l.color}"></span>
        <span class="text-[11.5px] font-semibold flex-1">${l.label}</span>
        <span class="text-[11px] font-bold text-t3">${l.pct}%</span></div>`;
        });
      }

      /* Show only the first `limit` evaluator rows; the rest scroll. */
      function capStaffList(limit = 4) {
        const el = document.getElementById('staff-list');
        if (!el) return;
        const rows = el.querySelectorAll('.staff-row');
        if (rows.length <= limit) {
          el.style.maxHeight = '';
          return;
        }
        let h = 0;
        for (let i = 0; i < limit; i++) h += rows[i].offsetHeight;
        el.style.maxHeight = h + 'px';
      }
      /*  9. EVALUATORS  */
      async function loadEvaluators() {
        const d = await api('evaluators');
        const el = document.getElementById('staff-list');
        el.innerHTML = '';

        const avatarColors = ['bg-emerald-100 text-ok', 'bg-amber-100 text-warn', 'bg-blue-100 text-brand-lt', 'bg-indigo-100 text-indigo'];

        const chip = (icon, color, bg, value, title) =>
          `<span title="${title}" class="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-[2px] rounded-md"
           style="color:${color};background:${bg}"><i class="fas ${icon} text-[8px]"></i>${value}</span>`;

        if (!d.staff.length) {
          el.innerHTML = '<p class="text-[11px] text-t3 italic">No evaluators registered.</p>';
        }

        d.staff.forEach((s, i) => {
          const cls = avatarColors[i % avatarColors.length];
          const status = s.active ?
            '<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-ok">Active</span>' :
            '<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-200 text-t3">Inactive</span>';
          const rate = s.approval_rate === null ? '—' : s.approval_rate + '%';

          el.innerHTML += `
      <div class="staff-row py-2.5 border-b border-bdr last:border-0">
        <div class="flex items-center gap-2.5">
          <div class="w-[34px] h-[34px] rounded-[10px] ${cls} flex items-center justify-center text-[11px] font-extrabold shrink-0">${escapeHtml(s.initials)}</div>
          <div class="flex-1 min-w-0">
            <div class="text-[12.5px] font-bold truncate">${escapeHtml(s.name)}</div>
            <div class="text-[10.5px] text-t3 font-medium truncate">${escapeHtml(s.email)}</div>
          </div>
          <div class="text-right shrink-0">${status}</div>
        </div>

        <div class="flex items-center gap-2 mt-2">
          <div class="flex-1 h-[5px] bg-slate-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width:${s.completion}%;background:${s.color}"></div>
          </div>
          <span class="text-[10px] font-bold text-t3 shrink-0">${s.completion}% decided · ${s.handled} handled</span>
        </div>

        <div class="flex flex-wrap gap-1.5 mt-2">
          ${chip('fa-check',       '#059669', 'rgba(5,150,105,.12)',  s.approved,    'Approved by this evaluator')}
          ${chip('fa-xmark',       '#dc2626', 'rgba(220,38,38,.12)',  s.disapproved, 'Disapproved by this evaluator')}
          ${chip('fa-inbox',       '#4f46e5', 'rgba(79,70,229,.12)',  s.queue + ' in queue', 'Currently in this evaluator\'s queue')}
          ${chip('fa-triangle-exclamation', '#ea580c', 'rgba(234,88,12,.12)', s.with_admin + ' with admin', 'Currently with the admin or waiting on the applicant')}
          ${chip('fa-user-shield', '#7c3aed', 'rgba(124,58,237,.12)', s.by_admin + ' by admin', 'Sent for compliance, then approved/disapproved by the admin')}
          ${chip('fa-right-left',  '#64748b', 'rgba(100,116,139,.15)', s.reassigned + ' reassigned', 'Reassigned to another evaluator')}
          ${chip('fa-percent',     '#0f766e', 'rgba(15,118,110,.12)', rate + ' approval', 'Approval rate (approved ÷ decided, evaluator\'s own decisions only)')}
          ${chip('fa-stopwatch',   '#475569', 'rgba(100,116,139,.15)',
                 s.avg_hours === null ? '—' : (s.avg_hours < 1/60 ? '&lt;1m' : formatDuration(s.avg_hours)),
                 'Avg. time from assignment to final decision (includes time waiting for applicant compliance)')}
        </div>
      </div>`;
        });
        capStaffList(4);
        const info = document.getElementById('staff-info');
        document.getElementById('staff-info-text').textContent =
          `${d.staff.length} evaluator${d.staff.length !== 1 ? 's' : ''} · Approved + Disapproved + In queue + With admin + By admin + Reassigned = Handled. Approved/Disapproved count only this evaluator's own decisions; admin-finalized and reassigned applications are shown separately.`;
        info.classList.remove('hidden');
        info.classList.add('flex');

        /* Workload donut */
        if (chartWorkload) {
          chartWorkload.destroy();
          chartWorkload = null;
        }
        if (d.workload_data.length) {
          chartWorkload = new Chart(document.getElementById('chartWorkload'), {
            type: 'doughnut',
            data: {
              labels: d.workload_names,
              datasets: [{
                data: d.workload_data,
                backgroundColor: d.workload_colors,
                borderWidth: 3,
                borderColor: chartRingColor(),
                hoverOffset: 4
              }]
            },
            options: {
              cutout: '62%',
              maintainAspectRatio: true,
              plugins: {
                legend: {
                  display: false
                }
              }
            }
          });

          const leg = document.getElementById('workload-legend');
          leg.innerHTML = '';
          d.workload_names.forEach((l, i) => {
            leg.innerHTML += `<div class="flex items-center gap-2 py-1 border-b border-bdr last:border-0">
        <span class="w-2 h-2 rounded-[3px] shrink-0" style="background:${d.workload_colors[i]}"></span>
        <span class="text-[11.5px] font-semibold flex-1">${escapeHtml(l)}</span>
        <span class="text-[11px] font-bold text-t3">${d.workload_data[i]}</span></div>`;
          });
        }
      }

      /*  10. ACTIVE JOB ORDERS (in workload panel)  */
      async function loadJobOrders() {
        const jobs = await api('job_orders');
        const el = document.getElementById('job-orders-list');
        el.innerHTML = '';

        if (!jobs.length) {
          el.innerHTML = '<p class="text-[11px] text-t3 italic">No active job orders.</p>';
          return;
        }

        jobs.forEach(j => {
          const daysLabel = j.days_left <= 0 ? '<span class="text-danger">Expired</span>' :
            j.days_left <= 3 ? `<span class="text-danger font-bold">${j.days_left}d left!</span>` :
            `<span class="text-t3">${j.days_left}d left</span>`;
          el.innerHTML += `
      <div class="mb-3">
        <div class="flex justify-between mb-1.5">
          <div>
            <div class="text-xs font-bold">${j.rsf}</div>
            <div class="text-[10.5px] text-t3 font-medium">${j.title.split('(')[0].trim()} – ${j.country}</div>
          </div>
          <div class="text-xs font-extrabold text-ok">${j.applied}<span class="text-t3 font-medium text-[10px]">/${j.slots}</span></div>
        </div>
        <div class="h-[5px] bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full bg-gradient-to-r from-emerald-600 to-emerald-400" style="width:${j.fill_pct}%"></div>
        </div>
        <div class="text-[10px] text-t3 font-medium mt-1">${j.employer.substring(0,30)}${j.employer.length>30?'…':''} · Deadline: ${formatDate(j.deadline)} · ${daysLabel}</div>
      </div>`;
        });
      }

      /*  Dock flyout  */
      function toggleFlyout(e, id) {
        e.stopPropagation();
        const el = document.getElementById(id);
        const was = el.classList.contains('open');
        document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
        if (!was) el.classList.add('open');
      }
      document.addEventListener('click', () => document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open')));

      /* ── Populate destination filter (full list, unfiltered — like dashboard) ── */
      async function loadFilterOptions() {
        const countries = await fetch(`${API}?action=countries`).then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        });
        const sel = document.getElementById('filter-country');
        const selected = sel.value;
        sel.innerHTML = '<option value="">All Destinations</option>';
        countries.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c;
          opt.textContent = c;
          sel.appendChild(opt);
        });
        if (selected && [...sel.options].some(o => o.value === selected)) {
          sel.value = selected;
        }
        sel.dispatchEvent(new Event('pill-refresh', {
          bubbles: true
        }));
      }

      /*  Init  */
      /*  Load everything, wired to the current filter selection  */
      async function loadAll() {
        try {
          await Promise.all([
            loadSummary(),
            loadStages(),
            loadAvgTime(),
            loadOverdue(),
            loadWeekly(),
            loadServices(),
            loadSvcMonthly(),
            loadDestinations(),
            loadEvaluators(),
            loadJobOrders(),
          ]);
        } catch (err) {
          console.error('Analytics error:', err);
        }
      }

      /*  Init  same pattern as dashboard.php  */
      ['filter-month', 'filter-province', 'filter-country']
      .forEach(id => enhancePillSelect(document.getElementById(id)));

      ['filter-month', 'filter-province', 'filter-country'].forEach(id => {
        document.getElementById(id).addEventListener('change', loadAll);
      });

      Promise.all([
        loadFilterOptions(),
        loadAll(),
      ]).catch(err => console.error('Analytics init error:', err));

      document.querySelector('.theme-toggle')?.addEventListener('click', () => {
        setTimeout(loadAll, 50);
      });

      /* Re-measure the evaluator list so exactly 4 rows stay visible after a resize */
      let staffResizeTimer;
      window.addEventListener('resize', () => {
        clearTimeout(staffResizeTimer);
        staffResizeTimer = setTimeout(() => capStaffList(4), 150);
      });
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
  </body>

  </html>
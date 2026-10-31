<?php
//dashboard.php  –  DMW RO IV-A Admin Dashboard
//Matches project structure from applications.php

session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// Admin name from session or DB
$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

// Avatar initials
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">

    <style>
        /* Skeleton loaders */
        .skeleton {
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.4s infinite;
            border-radius: 6px;
        }

        @keyframes shimmer {
            0% {
                background-position: 200% 0
            }

            100% {
                background-position: -200% 0
            }
        }

        .kpi-value.loading {
            height: 36px;
            width: 80px;
            display: inline-block;
        }

        .kpi-sub.loading {
            height: 14px;
            width: 120px;
            display: inline-block;
            margin-top: 4px;
        }

        #job-list {
            position: relative;
            max-height: 420px;
            overflow-y: auto;
            padding-right: 4px;
        }

        #job-list::-webkit-scrollbar {
            width: 5px;
        }

        #job-list::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }

        #job-list::-webkit-scrollbar-thumb:hover {
            background: var(--text3);
        }

        #job-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .dropdown-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .dropdown-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .dropdown-scroll::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }

        .dropdown-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--text3);
        }

        .monthly-card {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .monthly-chart-wrap {
            position: relative;
            flex: 1 1 auto;
            min-height: 180px;
        }

        .monthly-chart-wrap canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .monthly-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        .mstat {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
        }

        .mstat-lbl {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 4px;
        }

        .mstat-val {
            font-family: "DM Serif Display", serif;
            font-size: 20px;
            line-height: 1;
            color: var(--navy);
        }

        .mstat-sub {
            font-size: 10px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 4px;
        }

        @media (min-width: 640px) {
            .monthly-stats {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;

        }

        /* RESEARCH-OBJECTIVE DASHBOARD LAYOUT
           Groups analytics by purpose instead of one long row.
           Does not touch dashboard width — only internal arrangement. */

        .dash-section {
            margin-top: 22px;
        }

        .dash-section:first-of-type {
            margin-top: 0;
        }

        .dash-section-head {
            display: flex;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            padding-left: 2px;
        }

        .dash-section-head .dsh-bar {
            width: 4px;
            height: 15px;
            border-radius: 3px;
            background: var(--blue-lt, #1d4ed8);
            align-self: center;
        }

        .dash-section-head h3 {
            font-family: "DM Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text2, #334155);
            margin: 0;
        }

        .dash-section-head .dsh-sub {
            font-size: 10.5px;
            font-weight: 500;
            color: var(--text3, #94a3b8);
            text-transform: none;
            letter-spacing: 0;
        }

        /* Section 1: Monthly volume (wide) + status/jobs stack + destination/activity stack */
        .grid-overview {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 14px;
            align-items: stretch;
        }

        /* Section 2 & 3: balanced 2-up analytic cards */
        .grid-pair {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            align-items: stretch;
        }

        .stack-col {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 0;
        }

        .stack-col .card {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* ---- Recent Application Activity feed ---- */
        #activity-feed {
            max-height: 220px;
            overflow-y: auto;
            padding-right: 4px;
        }

        #activity-feed::-webkit-scrollbar {
            width: 5px;
        }

        #activity-feed::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }

        #activity-feed::-webkit-scrollbar-track {
            background: transparent;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .activity-text {
            font-size: 11.5px;
            color: var(--text1);
            line-height: 1.4;
        }

        .activity-text b {
            color: var(--navy);
        }

        .activity-time {
            font-size: 10px;
            color: var(--text3);
            margin-top: 2px;
        }

        .doc-extras {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .doc-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .doc-missing-title {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 4px;
        }

        @media (max-width: 480px) {
            .doc-stats {
                grid-template-columns: 1fr;
            }
        }

        /* ---- Responsive breakpoints ---- */
        @media (max-width: 1180px) {
            .grid-overview {
                grid-template-columns: 1.6fr 1fr;
            }

            .grid-overview .dest-col {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 860px) {
            .grid-overview {
                grid-template-columns: 1fr;
            }

            .grid-pair {
                grid-template-columns: 1fr;
            }

            .z-stats {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }

        @media (max-width: 480px) {
            .z-stats {
                grid-template-columns: 1fr !important;
            }
        }

        /* Evaluator summary table (fills the card's spare height) */
        .eval-card {
            display: flex;
            flex-direction: column;
        }

        .eval-summary {
            flex: 1;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
            overflow-y: auto;
            max-height: 150px;
        }

        .eval-row {
            display: grid;
            grid-template-columns: 1.6fr .7fr 1.2fr .8fr;
            gap: 8px;
            align-items: center;
            padding: 6px 0;
            font-size: 11.5px;
            color: var(--text1);
            border-bottom: 1px solid var(--border);
        }

        .eval-row:last-child {
            border-bottom: none;
        }

        .eval-row.head {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            padding-top: 0;
        }

        /* Failed-login list: 2 columns instead of the evaluator table's 4 */
        .eval-row.fail-cols {
            grid-template-columns: 1fr 70px;
        }

        .eval-row.fail-cols span:last-child {
            text-align: right;
        }

        .eval-row.head {
            position: sticky;
            top: 0;
            background: var(--surface);
            z-index: 1;
        }

        .eval-name {
            font-weight: 600;
            color: var(--navy);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .eval-rate {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .eval-rate-track {
            flex: 1;
            height: 5px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
        }

        .eval-rate-fill {
            height: 100%;
            background: #10b981;
            border-radius: 4px;
        }

        /* Single-line KPI strip (scrolls horizontally on small screens) */
        .kpi-strip {
            display: flex;
            flex-wrap: nowrap;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 6px;
            scrollbar-width: thin;
        }

        .kpi-strip .kpi-card {
            flex: 1 1 0;
            min-width: 150px;
        }

        .kpi-strip::-webkit-scrollbar {
            height: 6px;
        }

        .kpi-strip::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
        }

        .kpi-strip::-webkit-scrollbar-track {
            background: transparent;
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body>

    <!--  TOP BAR  -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <!-- Dark mode toggle -->
            <button type="button" class="theme-toggle" title="Toggle dark mode">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>
            <!-- Notification bell -->
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <!--    <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
            <?php include __DIR__ . '/avatar-menu.php'; ?>
        </div>
    </header>

    <!--  SUBBAR  -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($admin_name) ?></h2>
                <p>Current status of online applications</p>
            </div>
        </div>
        <div class="filter-scroll">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select id="filter-month" autocomplete="off">
                    <option value="" selected>All Time</option>
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                        $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
                        echo "<option value=\"$val\">$lbl</option>\n";
                    }
                    ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
                <select class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer" id="filter-province">
                    <option value="">CALABARZON</option>
                    <option value="Laguna">Laguna</option>
                    <option value="Batangas">Batangas</option>
                    <option value="Cavite">Cavite</option>
                    <option value="Rizal">Rizal</option>
                    <option value="Quezon">Quezon</option>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select id="filter-job">
                    <option value="">All Job Types</option>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select id="filter-country">
                    <option value="">All Destinations</option>
                </select>
            </label>
            <button type="button" class="filter-pill" id="print-export-btn" onclick="openReport()" style="cursor:pointer;border:none">
                <i class="fas fa-print" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Print / Export PDF</span>
            </button>
        </div>
    </div>

    <!--  MAIN  -->
    <div class="main">

        <!-- KPI Cards (single horizontal line) -->
        <div class="kpi-strip">

            <div class="kpi-card c-blue fade-in d1">
                <div class="kpi-top">
                    <div class="kpi-label">Total Apps</div>
                    <div class="kpi-icon c-blue"><i class="fas fa-folder-open"></i></div>
                </div>
                <div class="kpi-value c-blue skeleton loading" id="kpi-total"></div>
                <div class="kpi-sub skeleton loading" id="kpi-total-sub"></div>
            </div>

            <div class="kpi-card c-amber fade-in d2">
                <div class="kpi-top">
                    <div class="kpi-label">Pending</div>
                    <div class="kpi-icon c-amber"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="kpi-value c-amber skeleton loading" id="kpi-pending"></div>
                <div class="kpi-sub skeleton loading" id="kpi-pending-sub"></div>
            </div>

            <div class="kpi-card c-indigo fade-in d3">
                <div class="kpi-top">
                    <div class="kpi-label">Under Eval.</div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-magnifying-glass"></i></div>
                </div>
                <div class="kpi-value c-indigo skeleton loading" id="kpi-eval"></div>
                <div class="kpi-sub skeleton loading" id="kpi-eval-sub"></div>
            </div>

            <div class="kpi-card c-amber fade-in d3">
                <div class="kpi-top">
                    <div class="kpi-label">For Compliance</div>
                    <div class="kpi-icon c-amber"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-value c-amber skeleton loading" id="kpi-compliance"></div>
                <div class="kpi-sub skeleton loading" id="kpi-compliance-sub"></div>
            </div>

            <div class="kpi-card c-blue fade-in d3">
                <div class="kpi-top">
                    <div class="kpi-label">Resubmitted</div>
                    <div class="kpi-icon c-blue"><i class="fas fa-rotate-right"></i></div>
                </div>
                <div class="kpi-value c-blue skeleton loading" id="kpi-resubmitted"></div>
                <div class="kpi-sub skeleton loading" id="kpi-resubmitted-sub"></div>
            </div>

            <div class="kpi-card c-green fade-in d4">
                <div class="kpi-top">
                    <div class="kpi-label">Approved</div>
                    <div class="kpi-icon c-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="kpi-value c-green skeleton loading" id="kpi-approved"></div>
                <div class="kpi-sub skeleton loading" id="kpi-approved-sub"></div>
            </div>

            <div class="kpi-card c-green fade-in d4">
                <div class="kpi-top">
                    <div class="kpi-label">Approval Rate</div>
                    <div class="kpi-icon c-green"><i class="fas fa-percent"></i></div>
                </div>
                <div class="kpi-value c-green skeleton loading" id="kpi-rate"></div>
                <div class="kpi-sub skeleton loading" id="kpi-rate-sub"></div>
            </div>

            <div class="kpi-card c-indigo fade-in d5">
                <div class="kpi-top">
                    <div class="kpi-label">Avg. Processing</div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-stopwatch"></i></div>
                </div>
                <div class="kpi-value c-indigo skeleton loading" id="kpi-proc"></div>
                <div class="kpi-sub skeleton loading" id="kpi-proc-sub"></div>
            </div>

            <div class="kpi-card c-blue fade-in d5">
                <div class="kpi-top">
                    <div class="kpi-label">Active Job Orders</div>
                    <div class="kpi-icon c-blue"><i class="fas fa-briefcase"></i></div>
                </div>
                <div class="kpi-value c-blue skeleton loading" id="kpi-jobs"></div>
                <div class="kpi-sub skeleton loading" id="kpi-jobs-sub"></div>
            </div>

            <div class="kpi-card c-amber fade-in d6">
                <div class="kpi-top">
                    <div class="kpi-label">Slot Fill Rate</div>
                    <div class="kpi-icon c-amber"><i class="fas fa-chart-pie"></i></div>
                </div>
                <div class="kpi-value c-amber skeleton loading" id="kpi-fill"></div>
                <div class="kpi-sub skeleton loading" id="kpi-fill-sub"></div>
            </div>

            <div class="kpi-card c-blue fade-in d6">
                <div class="kpi-top">
                    <div class="kpi-label">Applicants</div>
                    <div class="kpi-icon c-blue"><i class="fas fa-users"></i></div>
                </div>
                <div class="kpi-value c-blue skeleton loading" id="kpi-applicants"></div>
                <div class="kpi-sub skeleton loading" id="kpi-applicants-sub"></div>
            </div>

        </div>

        <!-- SECTION 1: Application Volume, Status & Service Demand
             (Process monitoring + job/country demand + live activity) -->
        <div class="dash-section fade-in d4">
            <div class="dash-section-head">
                <span class="dsh-bar" style="background:var(--blue-lt,#1d4ed8)"></span>
                <h3>Application Volume, Status &amp; Service Demand</h3>
                <span class="dsh-sub">Submission trends, current processing status, job/country demand, and live activity</span>
            </div>

            <div class="grid-overview">

                <div class="card monthly-card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--blue-lt)"></span>
                            Monthly Application Volume
                        </div>
                        <a href="applications.php" class="card-link">
                            All <i class="fas fa-arrow-right" style="font-size:9px"></i>
                        </a>
                    </div>
                    <div class="monthly-chart-wrap">
                        <canvas id="chartMonthly"></canvas>
                    </div>
                    <div class="monthly-stats" id="monthly-stats">
                        <div class="mstat">
                            <div class="mstat-lbl">Vs last month</div>
                            <div class="mstat-val skeleton" style="height:20px;width:56px"></div>
                        </div>
                        <div class="mstat">
                            <div class="mstat-lbl">Monthly avg</div>
                            <div class="mstat-val skeleton" style="height:20px;width:42px"></div>
                        </div>
                        <div class="mstat">
                            <div class="mstat-lbl">Peak month</div>
                            <div class="mstat-val skeleton" style="height:20px;width:56px"></div>
                        </div>
                        <div class="mstat">
                            <div class="mstat-lbl">Quietest month</div>
                            <div class="mstat-val skeleton" style="height:20px;width:56px"></div>
                        </div>
                    </div>
                </div>

                <div class="stack-col">

                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">
                                <span class="ctdot" style="background:var(--indigo)"></span>
                                Status Breakdown
                            </div>
                            <a href="applications.php" class="card-link" style="font-size:10px">View all</a>
                        </div>
                        <div id="status-list">
                            <div class="skeleton" style="height:12px;margin:6px 0;width:100%"></div>
                            <div class="skeleton" style="height:12px;margin:6px 0;width:85%"></div>
                            <div class="skeleton" style="height:12px;margin:6px 0;width:70%"></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">
                                <span class="ctdot" style="background:var(--gold)"></span>
                                Active Job Orders
                            </div>
                            <a href="job-orders.php" class="card-link">
                                RSF <i class="fas fa-arrow-right" style="font-size:9px"></i>
                            </a>
                        </div>
                        <div id="job-list">
                            <div class="skeleton" style="height:52px;margin:6px 0;border-radius:8px"></div>
                            <div class="skeleton" style="height:52px;margin:6px 0;border-radius:8px"></div>
                        </div>
                    </div>

                </div>

                <div class="stack-col dest-col">

                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">
                                <span class="ctdot" style="background:var(--amber)"></span>
                                Destination Countries
                            </div>
                        </div>
                        <div class="pie-wrap max-sm:!flex-col max-sm:!items-center max-sm:!gap-3.5">
                            <div class="pie-canvas-wrap relative max-sm:!w-[min(220px,62vw)] max-sm:!flex-none max-sm:mx-auto">
                                <canvas id="chartPie" width="160" height="160"></canvas>
                            </div>
                            <div id="pie-legend" style="flex:1" class="max-sm:!flex-none max-sm:w-full max-sm:max-h-[210px] max-sm:overflow-y-auto max-sm:pr-1"></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">
                                <span class="ctdot" style="background:#6366f1"></span>
                                Recent Application Activity
                            </div>
                        </div>
                        <div id="activity-feed">
                            <div class="skeleton" style="height:40px;margin:6px 0;border-radius:8px"></div>
                            <div class="skeleton" style="height:40px;margin:6px 0;border-radius:8px"></div>
                            <div class="skeleton" style="height:40px;margin:6px 0;border-radius:8px"></div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <!--SECTION 2: Compliance & Document Processing
            (Bottleneck / round-trip monitoring) -->
        <div class="dash-section fade-in d5">
            <div class="dash-section-head">
                <span class="dsh-bar" style="background:var(--amber,#d97706)"></span>
                <h3>Compliance &amp; Document Processing</h3>
                <span class="dsh-sub">Compliance round-trips and document submission completeness</span>
            </div>

            <div class="grid-pair">

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--amber)"></span>
                            Compliance Cycles per Application
                        </div>
                    </div>
                    <div style="position:relative;height:200px">
                        <canvas id="chartCompliance"></canvas>
                    </div>
                    <div id="compliance-avg" style="font-size:11px;color:var(--text3);margin-top:8px;text-align:center"></div>
                    <div id="compliance-extras" class="doc-extras"></div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:#10b981"></span>
                            Document Submission Completeness
                        </div>
                    </div>
                    <div class="pie-wrap max-sm:!flex-col max-sm:!items-center max-sm:!gap-3.5">
                        <div class="pie-canvas-wrap relative max-sm:!w-[min(220px,62vw)] max-sm:!flex-none max-sm:mx-auto">
                            <canvas id="chartDocCompletion" width="150" height="150"></canvas>
                        </div>
                        <div id="doc-completion-legend" style="flex:1" class="max-sm:!flex-none max-sm:w-full max-sm:max-h-[210px] max-sm:overflow-y-auto max-sm:pr-1"></div>
                    </div>
                    <div id="doc-extras" class="doc-extras"></div>
                </div>

            </div>
        </div>

        <!--  SECTION 3: Evaluator & Administrative Performance
             (Workload, turnaround, and system integrity monitoring) -->
        <div class="dash-section fade-in d6">
            <div class="dash-section-head">
                <span class="dsh-bar" style="background:#6366f1"></span>
                <h3>Evaluator &amp; Administrative Performance</h3>
                <span class="dsh-sub">Workload distribution, decision turnaround, and login security</span>
            </div>

            <div class="grid-pair">

                <div class="card eval-card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            Evaluator Performance &amp; Workload
                        </div>
                    </div>
                    <div style="position:relative;height:200px">
                        <canvas id="chartEvaluator"></canvas>
                    </div>
                    <div id="evaluator-summary" class="eval-summary"></div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:#dc2626"></span>
                            Login Security (Last 30 Days)
                        </div>
                    </div>
                    <div style="position:relative;height:200px">
                        <canvas id="chartLoginSecurity"></canvas>
                    </div>
                    <div id="login-top-failed" class="eval-summary"></div>
                </div>

            </div>
        </div>

    </div><!-- /main -->


    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk active" data-tip="Dashboard">
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
            <!--<a href="assignments.html" class="dk" data-tip="Assignments">
                <i class="fas fa-list-check"></i>
                <span class="dk-lbl">Assign</span>
            </a>-->
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
            <a href="analytics.php" class="dk">
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


    <!--  JAVASCRIPT  -->
    <script>
        /* API endpoint — same folder as dashboard.php */
        const API = 'dashboard-api.php';

        let chartMonthly = null;
        let chartPie = null;

        /* Generic fetch helper — pass extra params as an object to filter */
        function api(action, params = {}) {
            const qs = new URLSearchParams({
                action,
                ...params
            }).toString();
            return fetch(`${API}?${qs}`).then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            });
        }

        /* Read the current state of the subbar filter pills */
        function getFilters() {
            const f = {};
            const month = document.getElementById('filter-month')?.value;
            const province = document.getElementById('filter-province')?.value;
            const job = document.getElementById('filter-job')?.value;
            const country = document.getElementById('filter-country')?.value;
            if (month) f.month = month;
            if (province) f.province = province;
            if (job) f.job_type = job;
            if (country) f.country = country;
            return f;
        }

        /* Open the printable / PDF report using the admin's current filters */
        function openReport() {
            const qs = new URLSearchParams(getFilters()).toString();
            window.open(`report.php${qs ? '?' + qs : ''}`, '_blank');
        }

        function removeSkeleton(el) {
            if (el) el.classList.remove('skeleton', 'loading');
        }

        function fmtPct(pct) {
            if (pct === null || pct === undefined) return '';
            const sign = pct >= 0 ? '▲' : '▼';
            const col = pct >= 0 ? '#10b981' : '#ef4444';
            return `<b style="color:${col}">${sign} ${Math.abs(pct)}%</b> vs last mo.`;
        }

        /* Wraps a native <select> with a compact styled trigger + dropdown
           panel, sized to sit inside a "filter-pill". The panel is appended
           to <body> and positioned `fixed` (from the trigger's on-screen
           position) so it never stretches .filter-scroll's horizontal
           scrollbar. The original <select> stays in the DOM (hidden) and
           its `id`, `.value`, and 'change' event all keep working exactly
           as before — bindFilterPills() below needs zero changes. */
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
            panel.className = 'hidden fixed w-56 bg-[var(--surface)] border-[1.5px] border-[var(--border)] rounded-lg shadow-[0_10px_30px_rgba(11,31,75,.16)] z-[999] overflow-hidden py-1 max-h-[240px] overflow-y-auto dropdown-scroll';
            document.body.appendChild(panel);

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.className = 'flex items-center justify-between gap-2 py-[7px] px-3 text-[12px] text-[var(--text1)] cursor-pointer hover:bg-[var(--hover-row)] transition-colors';
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
                    row.classList.toggle('bg-[var(--selected-row)]', selected);
                    row.classList.toggle('text-[var(--navy)]', selected);
                    row.classList.toggle('font-bold', selected);
                    let check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check text-[10px] text-[var(--blue)] flex-shrink-0';
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

            // Exposed so callers (e.g. after loadFilterOptions() repopulates
            // filter-job / filter-country with fresh <option>s) can refresh
            // the trigger label if needed.
            select._pillRefresh = refresh;
        }
        /*  Live date + time  */
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

        /*  0. Populate filter pills (job types + destinations), once  */
        async function loadFilterOptions() {
            const [jobTypes, dest] = await Promise.all([
                api('job_types'),
                api('destinations'), // unfiltered, gives the full country list
            ]);

            const jobSel = document.getElementById('filter-job');
            jobSel.innerHTML = '<option value="">All Job Types</option>';
            jobTypes.forEach(t => jobSel.innerHTML += `<option value="${t}">${t}</option>`);

            const countrySel = document.getElementById('filter-country');
            countrySel.innerHTML = '<option value="">All Destinations</option>';
            dest.labels.forEach(c => countrySel.innerHTML += `<option value="${c}">${c}</option>`);
        }

        /* ── Wire up filter pills: any change reloads the filtered views ── */
        function bindFilterPills() {
            ['filter-month', 'filter-province', 'filter-job', 'filter-country'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', refreshFilteredViews);
            });
        }

        function refreshFilteredViews() {
            Promise.all([
                loadStats(),
                loadMonthly(),
                loadStatus(),
                loadJobOrders(),
                loadPie(),
                loadEvaluatorPerformance(),
                loadDocumentCompletion(),
                loadComplianceCycles(),
            ]).catch(err => console.error('Dashboard filter error:', err));
        }

        /*  1. KPI Stats  */
        async function loadStats() {
            const d = await api('stats', getFilters());

            const tv = document.getElementById('kpi-total');
            tv.textContent = d.total.toLocaleString();
            removeSkeleton(tv);
            const ts = document.getElementById('kpi-total-sub');
            ts.innerHTML = d.change_pct !== null ? fmtPct(d.change_pct) : 'All time';
            removeSkeleton(ts);

            const pv = document.getElementById('kpi-pending');
            pv.textContent = d.pending.toLocaleString();
            removeSkeleton(pv);
            const ps = document.getElementById('kpi-pending-sub');
            ps.innerHTML = d.pending > 0 ?
                '<span style="color:#f59e0b">Needs attention</span>' :
                '<span style="color:#10b981">All clear</span>';
            removeSkeleton(ps);

            const ev = document.getElementById('kpi-eval');
            ev.textContent = d.under_evaluation.toLocaleString();
            removeSkeleton(ev);
            const es = document.getElementById('kpi-eval-sub');
            es.textContent = d.avg_eval_days ? `Avg. ${d.avg_eval_days} days` : 'In progress';
            removeSkeleton(es);

            const av = document.getElementById('kpi-approved');
            av.textContent = d.approved.toLocaleString();
            removeSkeleton(av);
            const as = document.getElementById('kpi-approved-sub');
            as.innerHTML = d.approved_change !== null ? fmtPct(d.approved_change) : '';
            removeSkeleton(as);

            /* Dock badge — show unassigned count so admin knows what needs action */
            const unassign = d.unassign ?? 0;
            if (unassign > 0) {
                const b = document.getElementById('dock-badge');
                b.textContent = unassign;
                b.style.display = '';
            }
        }

        /*  2. Monthly bar chart  */
        async function loadMonthly() {
            const d = await api('monthly', getFilters());

            if (chartMonthly) {
                chartMonthly.destroy();
                chartMonthly = null;
            }

            chartMonthly = new Chart(document.getElementById('chartMonthly'), {
                type: 'bar',
                data: {
                    labels: d.labels,
                    datasets: [{
                            label: 'Applications',
                            data: d.submitted,
                            backgroundColor: 'rgba(29,78,216,.12)',
                            borderColor: '#1d4ed8',
                            borderWidth: 2,
                            borderRadius: 6,
                            borderSkipped: false
                        },
                        {
                            label: 'Approved',
                            data: d.approved,
                            backgroundColor: 'rgba(5,150,105,.12)',
                            borderColor: '#059669',
                            borderWidth: 2,
                            borderRadius: 6,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'DM Sans',
                                    size: 11
                                },
                                boxWidth: 10,
                                padding: 12
                            }
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
                                maxRotation: 0
                            }
                        },
                        y: {
                            grid: {
                                color: '#f1f5f9'
                            },
                            ticks: {
                                font: {
                                    family: 'DM Sans',
                                    size: 10
                                }
                            }
                        }
                    }
                }
            });

            const submitted = Array.isArray(d.submitted) ? d.submitted : [];
            const labels = Array.isArray(d.labels) ? d.labels : [];
            const now = new Date();
            const monthSel = document.getElementById('filter-month')?.value || '';
            let focusIdx = now.getMonth();
            if (/^\d{4}-\d{2}$/.test(monthSel)) {
                focusIdx = parseInt(monthSel.slice(5, 7), 10) - 1;
            }
            focusIdx = Math.max(0, Math.min(11, focusIdx));
            const elapsed = submitted.slice(0, focusIdx + 1);

            const thisVal = submitted[focusIdx] || 0;
            const prevVal = focusIdx > 0 ? (submitted[focusIdx - 1] || 0) : null;
            let momHtml = '—';
            let momColor = 'var(--navy)';
            let momSub = focusIdx > 0 ? `vs ${labels[focusIdx - 1] || 'last month'}` : 'No prior month this year';
            if (focusIdx > 0 && prevVal > 0) {
                const pct = Math.round(((thisVal - prevVal) / prevVal) * 100);
                const up = pct >= 0;
                momColor = up ? 'var(--green)' : 'var(--red)';
                momHtml = `${up ? '▲' : '▼'} ${Math.abs(pct)}%`;
                momSub = `${labels[focusIdx]} vs ${labels[focusIdx - 1]} (${thisVal} vs ${prevVal})`;
            } else if (focusIdx > 0 && prevVal === 0 && thisVal > 0) {
                momColor = 'var(--green)';
                momHtml = 'New';
                momSub = `${thisVal.toLocaleString()} in ${labels[focusIdx]} · none in ${labels[focusIdx - 1]}`;
            } else if (focusIdx > 0) {
                momHtml = '0%';
                momSub = `No change vs ${labels[focusIdx - 1]}`;
            }

            const avg = elapsed.length ? Math.round(elapsed.reduce((a, b) => a + b, 0) / elapsed.length) : 0;
            const vsAvg = thisVal - avg;
            const avgSub = vsAvg === 0 ?
                `${labels[focusIdx]} is on the average` :
                `${labels[focusIdx]} is ${Math.abs(vsAvg)} ${vsAvg > 0 ? 'above' : 'below'} avg`;

            const peakIdx = elapsed.reduce((best, n, i) => n > elapsed[best] ? i : best, 0);
            const peakVal = elapsed[peakIdx] || 0;
            const peakLabel = peakVal > 0 ? (labels[peakIdx] || '—') : '—';

            const quietIdx = elapsed.reduce((best, n, i) => n < elapsed[best] ? i : best, 0);
            const quietVal = elapsed.length ? (elapsed[quietIdx] ?? 0) : 0;
            const quietLabel = elapsed.length ? (labels[quietIdx] || '—') : '—';

            const stats = document.getElementById('monthly-stats');
            if (stats) {
                stats.innerHTML = `
        <div class="mstat">
            <div class="mstat-lbl">Vs last month</div>
            <div class="mstat-val" style="color:${momColor}">${momHtml}</div>
            <div class="mstat-sub">${momSub}</div>
        </div>
        <div class="mstat">
            <div class="mstat-lbl">Monthly avg</div>
            <div class="mstat-val" style="color:var(--blue)">${avg.toLocaleString()}</div>
            <div class="mstat-sub">${avgSub}</div>
        </div>
        <div class="mstat">
            <div class="mstat-lbl">Peak month</div>
            <div class="mstat-val">${peakLabel}</div>
            <div class="mstat-sub">${peakVal > 0 ? peakVal.toLocaleString() + ' applications' : 'No submissions yet'}</div>
        </div>
        <div class="mstat">
            <div class="mstat-lbl">Quietest month</div>
            <div class="mstat-val">${quietLabel}</div>
            <div class="mstat-sub">${elapsed.length ? quietVal.toLocaleString() + ' applications' : 'No data yet'}</div>
        </div>`;
            }
        }

        /*  3. Status breakdown bars  */
        async function loadStatus() {
            const items = await api('status', getFilters());
            const maxCnt = Math.max(...items.map(s => s.count), 1);
            const el = document.getElementById('status-list');
            el.innerHTML = '';

            items.forEach(s => {
                const pct = Math.round(s.count / maxCnt * 100);
                el.innerHTML += `
        <div class="s-row">
            <span class="s-dot"  style="background:${s.color}"></span>
            <span class="s-name">${s.label}</span>
            <span class="s-num"  style="color:${s.color}">${s.count}</span>
            <div  class="s-bar-wrap">
                <div class="s-bar-fill" style="width:${pct}%;background:${s.color}"></div>
            </div>
        </div>`;
            });
        }

        /*  4. Active job orders  */
        const JOB_GRADIENTS = [
            ['#059669', '#34d399'],
            ['#1d4ed8', '#60a5fa'],
            ['#d97706', '#fbbf24'],
            ['#6366f1', '#a78bfa'],
            ['#dc2626', '#f87171'],
        ];

        /* Limit the list to exactly 4 visible job cards; the rest scroll */
        const JOB_LIST_VISIBLE = 4;

        function fitJobList() {
            const el = document.getElementById('job-list');
            if (!el) return;
            const items = el.querySelectorAll('.job-item');

            if (items.length <= JOB_LIST_VISIBLE) {
                el.style.maxHeight = 'none';
                el.style.overflowY = 'visible';
                return;
            }
            const last = items[JOB_LIST_VISIBLE - 1];
            el.style.maxHeight = (last.offsetTop + last.offsetHeight) + 'px';
            el.style.overflowY = 'auto';
        }

        let _jobFitTimer;
        window.addEventListener('resize', () => {
            clearTimeout(_jobFitTimer);
            _jobFitTimer = setTimeout(fitJobList, 150);
        });

        async function loadJobOrders() {
            const jobs = await api('job_orders', getFilters());
            const el = document.getElementById('job-list');
            el.innerHTML = '';

            if (!jobs.length) {
                el.innerHTML = '<p style="font-size:12px;color:#94a3b8;text-align:center;padding:16px">No active job orders</p>';
                return;
            }

            jobs.forEach((j, i) => {
                const [c1, c2] = JOB_GRADIENTS[i % JOB_GRADIENTS.length];
                const numColor = j.fill_pct > 80 ? '#dc2626' : (j.fill_pct > 50 ? '#d97706' : c1);
                const daysLabel = j.days_left <= 0 ?
                    '<span style="color:#dc2626;font-weight:700">Expired</span>' :
                    j.days_left <= 3 ?
                    `<span style="color:#ef4444;font-weight:700">${j.days_left}d left!</span>` :
                    `${j.days_left}d left`;

                el.innerHTML += `
        <div class="job-item">
            <div class="job-head">
                <div>
                    <div class="job-name">${j.rsf_number}</div>
                    <div class="job-sub">${j.title} &mdash; ${j.country}</div>
                    <div style="font-size:9px;color:#94a3b8;margin-top:2px">${daysLabel}</div>
                </div>
                <div class="job-count" style="color:${numColor}">
                    ${j.applied}<span style="color:var(--text3);font-weight:500;font-size:10px">/${j.slots}</span>
                </div>
            </div>
            <div class="job-track">
                <div class="job-fill" style="width:${j.fill_pct}%;background:linear-gradient(90deg,${c1},${c2})"></div>
            </div>
        </div>`;
            });
            fitJobList();
        }

        /*  5. Destination doughnut  */
        async function loadPie() {
            const d = await api('destinations', getFilters());

            if (chartPie) {
                chartPie.destroy();
                chartPie = null;
            }

            if (!d.labels.length) {
                document.getElementById('chartPie').parentElement.innerHTML =
                    '<p style="font-size:12px;color:#94a3b8;padding:24px;text-align:center">No data yet</p>';
                return;
            }

            chartPie = new Chart(document.getElementById('chartPie'), {
                type: 'doughnut',
                data: {
                    labels: d.labels,
                    datasets: [{
                        data: d.data,
                        backgroundColor: d.colors,
                        borderWidth: 3,
                        borderColor: '#fff',
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
            const total = d.data.reduce((a, b) => a + b, 0) || 1;
            leg.innerHTML = '';
            d.labels.forEach((l, i) => {
                const pct = Math.round(d.data[i] / total * 100);
                leg.innerHTML += `
            <div class="p-row max-sm:flex max-sm:items-center max-sm:gap-2 max-sm:py-1.5 max-sm:text-xs min-w-0">
            <span class="p-sw max-sm:shrink-0" style="background:${d.colors[i]}"></span>
            <span class="p-lbl max-sm:flex-1 max-sm:min-w-0 max-sm:!whitespace-normal max-sm:break-words">${escHtml(l)}</span>
            <span class="p-pct max-sm:shrink-0 max-sm:font-bold">${pct}%</span>
        </div>`;
            });
        }

        function escHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function renderEvaluatorSummary(d) {
            const box = document.getElementById('evaluator-summary');
            if (!box) return;
            if (!d.labels.length) {
                box.innerHTML = '';
                return;
            }

            const rows = d.labels.map((name, i) => {
                const a = d.approved[i] || 0;
                const x = d.disapproved[i] || 0;
                const t = a + x;
                return {
                    name,
                    total: t,
                    rate: t ? Math.round(a / t * 100) : null,
                    avg: d.avg_days[i]
                };
            }).sort((p, q) => q.total - p.total);

            box.innerHTML = `
                <div class="eval-row head">
                    <span>Evaluator</span><span>Decided</span><span>Approval</span><span>Avg. days</span>
                </div>` +
                rows.map(r => `
                <div class="eval-row">
                    <span class="eval-name" title="${escHtml(r.name)}">${escHtml(r.name)}</span>
                    <span><b>${r.total}</b></span>
                    <span class="eval-rate">
                        <span class="eval-rate-track"><span class="eval-rate-fill" style="display:block;width:${r.rate ?? 0}%"></span></span>
                        <span>${r.rate !== null ? r.rate + '%' : '—'}</span>
                    </span>
                    <span>${r.avg !== null && r.avg !== undefined ? r.avg : '—'}</span>
                </div>`).join('');
        }

        async function loadEvaluatorPerformance() {
            const d = await api('evaluator_performance', getFilters());
            renderEvaluatorSummary(d);
            const ctx = document.getElementById('chartEvaluator');
            if (window._chartEvaluator) window._chartEvaluator.destroy();

            if (!d.labels.length) {
                ctx.parentElement.innerHTML = '<p style="font-size:12px;color:#94a3b8;text-align:center;padding:24px">No evaluation activity yet</p>';
                return;
            }

            window._chartEvaluator = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: d.labels,
                    datasets: [{
                            label: 'Approved',
                            data: d.approved,
                            backgroundColor: '#10b981'
                        },
                        {
                            label: 'Disapproved',
                            data: d.disapproved,
                            backgroundColor: '#dc2626'
                        },
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 10
                                }
                            }
                        },
                        y: {
                            stacked: true,
                            grid: {
                                color: '#f1f5f9'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'DM Sans',
                                    size: 11
                                },
                                boxWidth: 10
                            }
                        },
                        tooltip: {
                            callbacks: {
                                afterBody: (items) => {
                                    const i = items[0].dataIndex;
                                    const avg = d.avg_days[i];
                                    return avg !== null ? `Avg. turnaround: ${avg} days` : 'No completed evaluations yet';
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderDocExtras(d) {
            const box = document.getElementById('doc-extras');
            if (!box) return;
            const n = v => (v ?? 0).toLocaleString();
            const missing = d.top_missing || [];

            box.innerHTML = `
                <div class="doc-stats">
                    <div class="mstat"><div class="mstat-lbl">Documents uploaded</div>
                        <div class="mstat-val">${n(d.uploaded_total)}</div></div>
                    <div class="mstat"><div class="mstat-lbl">Avg. per application</div>
                        <div class="mstat-val">${d.avg_per_app ?? 0}</div></div>
                    <div class="mstat"><div class="mstat-lbl">With reviewer comments</div>
                        <div class="mstat-val">${n(d.commented_docs)}</div></div>
                </div>
                <div class="doc-missing-title">Most missing required documents</div>` +
                (missing.length ?
                    missing.map(m => `
                    <div class="eval-row fail-cols">
                        <span class="eval-name" title="${escHtml(m.name)}">${escHtml(m.name)}</span>
                        <span style="color:#dc2626;font-weight:700">${m.count}</span>
                    </div>`).join('') :
                    '<p style="font-size:11px;color:#94a3b8;padding:6px 0">No missing required documents.</p>');
        }

        async function loadDocumentCompletion() {
            const d = await api('document_completion', getFilters());
            renderDocExtras(d);
            const canvas = document.getElementById('chartDocCompletion');
            if (window._chartDoc) window._chartDoc.destroy();

            const total = d.data.reduce((a, b) => a + b, 0);
            if (!total) {
                canvas.parentElement.parentElement.innerHTML = '<p style="font-size:12px;color:#94a3b8;padding:24px;text-align:center">No document data yet</p>';
                return;
            }

            window._chartDoc = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: d.labels,
                    datasets: [{
                        data: d.data,
                        backgroundColor: d.colors,
                        borderWidth: 3,
                        borderColor: '#fff'
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

            const leg = document.getElementById('doc-completion-legend');
            leg.innerHTML = '';
            d.labels.forEach((l, i) => {
                const pct = Math.round(d.data[i] / total * 100);
                leg.innerHTML += `
            <div class="p-row max-sm:flex max-sm:items-center max-sm:gap-2 max-sm:py-1.5 max-sm:text-xs min-w-0">
            <span class="p-sw max-sm:shrink-0" style="background:${d.colors[i]}"></span>
            <span class="p-lbl max-sm:flex-1 max-sm:min-w-0 max-sm:!whitespace-normal max-sm:break-words">${escHtml(l)}</span>
            <span class="p-pct max-sm:shrink-0 max-sm:font-bold">${d.data[i]} (${pct}%)</span>
        </div>`;
            });
        }

        async function loadComplianceCycles() {
            const d = await api('compliance_cycles', getFilters());
            const ctx = document.getElementById('chartCompliance');
            if (window._chartCompliance) window._chartCompliance.destroy();

            window._chartCompliance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: d.labels,
                    datasets: [{
                        label: 'Applications',
                        data: d.data,
                        backgroundColor: '#f97316',
                        borderRadius: 6
                    }]
                },
                options: {
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
                                    size: 9
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: '#f1f5f9'
                            }
                        }
                    }
                }
            });

            document.getElementById('compliance-avg').textContent =
                `Average of ${d.avg_cycles} compliance round(s) per application`;
        }

        async function loadLoginSecurity() {
            const d = await api('login_security');
            const ctx = document.getElementById('chartLoginSecurity');
            if (window._chartLogin) window._chartLogin.destroy();

            window._chartLogin = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: d.labels,
                    datasets: [{
                            label: 'Successful',
                            data: d.success,
                            borderColor: '#059669',
                            backgroundColor: 'rgba(5,150,105,.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Failed',
                            data: d.failed,
                            borderColor: '#dc2626',
                            backgroundColor: 'rgba(220,38,38,.1)',
                            fill: true,
                            tension: 0.3
                        },
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    size: 11
                                },
                                boxWidth: 10
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxTicksLimit: 8,
                                font: {
                                    size: 9
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: '#f1f5f9'
                            }
                        }
                    }
                }
            });

            const box = document.getElementById('login-top-failed');
            if (!d.top_failed.length) {
                box.innerHTML = '<p style="font-size:11px;color:#94a3b8;text-align:center;padding:8px 0">No failed login attempts recorded.</p>';
                return;
            }
            box.innerHTML = `
                <div class="eval-row fail-cols head">
                    <span>Account (most failed attempts)</span><span>Failed</span>
                </div>` +
                d.top_failed.map(t => `
                <div class="eval-row fail-cols">
                    <span class="eval-name" title="${escHtml(t.email)}">${escHtml(t.email)}</span>
                    <span style="color:#dc2626;font-weight:700">${t.count}</span>
                </div>`).join('');
        }

        /*  6. Recent Application Activity feed  */
        const STATUS_META = {
            pending: {
                color: '#94a3b8',
                icon: 'fa-clock'
            },
            under_evaluation: {
                color: '#6366f1',
                icon: 'fa-magnifying-glass'
            },
            for_compliance: {
                color: '#f97316',
                icon: 'fa-triangle-exclamation'
            },
            resubmitted: {
                color: '#0ea5e9',
                icon: 'fa-rotate-right'
            },
            approved: {
                color: '#10b981',
                icon: 'fa-circle-check'
            },
            disapproved: {
                color: '#dc2626',
                icon: 'fa-circle-xmark'
            },
            assign: {
                color: '#1d4ed8',
                icon: 'fa-user-check'
            },
            assigned: {
                color: '#1d4ed8',
                icon: 'fa-user-check'
            },
            unassign: {
                color: '#f59e0b',
                icon: 'fa-user-slash'
            },
        };

        function statusMeta(s) {
            return STATUS_META[s] || {
                color: '#64748b',
                icon: 'fa-circle'
            };
        }

        function timeAgo(mysqlDatetime) {
            const d = new Date((mysqlDatetime || '').replace(' ', 'T'));
            if (isNaN(d.getTime())) return '';
            const diff = Math.floor((Date.now() - d.getTime()) / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        async function loadActivityFeed() {
            const rows = await api('recent_activity');
            const el = document.getElementById('activity-feed');
            el.innerHTML = '';

            if (!rows.length) {
                el.innerHTML = '<p style="font-size:12px;color:#94a3b8;text-align:center;padding:16px">No recent activity</p>';
                return;
            }

            rows.forEach(r => {
                const meta = statusMeta(r.to_status);
                const name = r.applicant_name ? r.applicant_name.trim() : 'Applicant';
                const pos = r.position_title ? ` &mdash; ${r.position_title}` : '';
                const fromLabel = r.from_status ? r.from_status.replace(/_/g, ' ') : 'new';
                const toLabel = (r.to_status || '').replace(/_/g, ' ');

                el.innerHTML += `
        <div class="activity-item">
            <div class="activity-icon" style="background:${meta.color}1a;color:${meta.color}">
                <i class="fas ${meta.icon}"></i>
            </div>
            <div style="flex:1;min-width:0">
                <div class="activity-text">
                    <b>#${r.application_id}</b> ${name}${pos}<br>
                    ${fromLabel} <i class="fas fa-arrow-right" style="font-size:8px;opacity:.5"></i> <b>${toLabel}</b>
                </div>
                <div class="activity-time">${timeAgo(r.changed_at)}</div>
            </div>
        </div>`;
            });
        }

        /*  Dock flyout (same as applications.php)  */
        function toggleFlyout(e, id) {
            e.stopPropagation();
            const el = document.getElementById(id);
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
        });

        /*  Init: load everything in parallel  */
        ['filter-month', 'filter-province', 'filter-job', 'filter-country']
        .forEach(id => enhancePillSelect(document.getElementById(id)));
        bindFilterPills();
        Promise.all([
            loadFilterOptions(),
            loadStats(),
            loadMonthly(),
            loadStatus(),
            loadJobOrders(),
            loadPie(),
            loadEvaluatorPerformance(),
            loadDocumentCompletion(),
            loadComplianceCycles(),
            loadLoginSecurity(),
            loadActivityFeed(),
        ]).catch(err => console.error('Dashboard error:', err));

        /* Auto-refresh KPIs + activity feed every 60 s */
        setInterval(() => {
            loadStats();
            loadStatus();
            loadActivityFeed();
        }, 60_000);
    </script>
    <?php include '../includes/login_toast.php'; ?>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
    <script type="module" src="../js/main.js"></script>
    <script>
        (function() {
            function qs(id) {
                return document.getElementById(id);
            }

            function filters() {
                const f = {};
                const m = qs('filter-month')?.value,
                    p = qs('filter-province')?.value,
                    j = qs('filter-job')?.value,
                    c = qs('filter-country')?.value;
                if (m) f.month = m;
                if (p) f.province = p;
                if (j) f.job_type = j;
                if (c) f.country = c;
                return f;
            }

            function setKpi(id, value, sub) {
                const v = qs(id),
                    s = qs(id + '-sub');
                if (v) {
                    v.textContent = value;
                    v.classList.remove('skeleton', 'loading');
                }
                if (s) {
                    s.innerHTML = sub || '';
                    s.classList.remove('skeleton', 'loading');
                }
            }

            function pct(p) {
                if (p === null || p === undefined) return '';
                const up = p >= 0;
                return `<b style="color:${up ? '#10b981' : '#ef4444'}">${up ? '▲' : '▼'} ${Math.abs(p)}%</b> vs last mo.`;
            }

            window.loadStats = async function() {
                const q = new URLSearchParams({
                    action: 'stats',
                    ...filters()
                }).toString();
                const res = await fetch('dashboard-api.php?' + q);
                const d = await res.json();
                const n = v => (v ?? 0).toLocaleString();
                const na = '—';

                setKpi('kpi-total', n(d.total), d.change_pct !== null ? pct(d.change_pct) : 'All time');

                setKpi('kpi-pending', n(d.pending),
                    d.pending_aging > 0 ?
                    `<span style="color:#ef4444;font-weight:600">${n(d.pending_aging)} waiting &gt; 3 days</span>` :
                    (d.pending > 0 ? '<span style="color:#f59e0b">Needs attention</span>' :
                        '<span style="color:#10b981">All clear</span>'));

                setKpi('kpi-eval', n(d.under_evaluation),
                    `${n(d.active_evaluators)} active evaluator${d.active_evaluators === 1 ? '' : 's'}`);

                setKpi('kpi-compliance', n(d.for_compliance), 'Awaiting applicant action');

                setKpi('kpi-resubmitted', n(d.resubmitted),
                    d.resubmitted > 0 ? '<span style="color:#0ea5e9;font-weight:600">Ready for re-review</span>' :
                    '<span style="color:#10b981">None waiting</span>');

                setKpi('kpi-approved', n(d.approved),
                    d.approved_change !== null ? pct(d.approved_change) : `${n(d.disapproved)} disapproved`);

                setKpi('kpi-rate', d.approval_rate !== null ? d.approval_rate + '%' : na,
                    d.approval_rate !== null ? `${n(d.approved)} of ${n(d.approved + d.disapproved)} decided` :
                    'No decisions yet');

                setKpi('kpi-proc', d.avg_processing !== null ? d.avg_processing + 'd' : na,
                    'Submission → final decision');

                setKpi('kpi-jobs', n(d.active_jobs), `${n(d.total_slots)} total slots open`);

                setKpi('kpi-fill', d.slot_fill_rate !== null ? d.slot_fill_rate + '%' : na,
                    `${n(d.filled_slots)} of ${n(d.total_slots)} slots approved`);

                setKpi('kpi-applicants', n(d.applicants), 'Active registered accounts');
            };

            window.loadStats().catch(e => console.error('KPI load failed:', e));
        })();
    </script>
</body>

</html>
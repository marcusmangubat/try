<?php
// evaluator/index.php – DMW Evaluator Dashboard
session_start();
require_once '../db.php';
require_once '../auth.php';
date_default_timezone_set('Asia/Manila');
require_once '../includes/dashboard-data.php';

// Require evaluator login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

// Get evaluator info
$evaluator_name = 'Evaluator';
$evaluator_id   = null;

$stmt_ev = $database->prepare("
    SELECT eid, full_name 
    FROM evaluators 
    WHERE email = ? 
    LIMIT 1
");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();

if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// AJAX: refresh the "Open for Evaluation" queue (same file, no extra endpoint)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'incoming') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(evx_incoming_data($database, evx_clean_filters($_GET)), EVX_JSON);
    exit;
}

// Filtering is AJAX-driven (no page reload) — these are only used to
// pre-select dropdown options on first paint; they always start empty.
// The filter pills re-fetch data on change via ajax-dashboard-data.php.
$filter_month    = '';
$filter_province = '';
$filter_job      = '';
$filter_country  = '';

// Initial page load, no filters applied yet.
// evaluator_filter_sql() and format_turnaround() live in
// ../includes/dashboard-data.php — shared by this page and the AJAX
// endpoint so the two never drift out of sync.
$dashboard = evaluator_dashboard_data($database, (int)($evaluator_id ?? 0), []);

$stats                    = $dashboard['stats'];
$available_count          = $dashboard['available_count'];
$my_applications          = $dashboard['my_applications'];
$country_data             = $dashboard['country_data'];
$eval_trend_labels        = $dashboard['eval_trend']['labels'];
$eval_trend_approved      = $dashboard['eval_trend']['approved'];
$eval_trend_disapp        = $dashboard['eval_trend']['disapproved'];
$eval_trend_compliance    = $dashboard['eval_trend']['compliance'];
$turnaround_by_decision   = $dashboard['turnaround']['by_decision'];
$turnaround_overall_hours = $dashboard['turnaround']['overall_hours'];

// Open-queue + personal workload analytics (functions live in dashboard-data.php)
$incoming  = evx_incoming_data($database, evx_clean_filters([]));
$workload  = evx_workload_data($database, (int)($evaluator_id ?? 0));
$attention = evx_attention_data($database, (int)($evaluator_id ?? 0));
$activity  = evx_activity_data($database, $evaluator_email);

// Filter dropdown sources — scoped ONLY to job types/destinations/provinces
// this evaluator actually has applications for (via current_evaluator_id),
// never the full system-wide list of RSF job orders or all client provinces.
$evaluator_job_types = [];
$evaluator_countries = [];
$evaluator_provinces = [];

if ($evaluator_id) {
    $stmt_jt = $database->prepare("
        SELECT DISTINCT r.position_title
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.current_evaluator_id = ?
          AND r.position_title IS NOT NULL AND r.position_title <> ''
        ORDER BY r.position_title ASC
    ");
    $stmt_jt->bind_param("i", $evaluator_id);
    $stmt_jt->execute();
    $res_jt = $stmt_jt->get_result();
    while ($row = $res_jt->fetch_assoc()) {
        $evaluator_job_types[] = $row['position_title'];
    }
    $stmt_jt->close();

    $stmt_ct = $database->prepare("
        SELECT DISTINCT r.worksite_country
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.current_evaluator_id = ?
          AND r.worksite_country IS NOT NULL AND r.worksite_country <> ''
        ORDER BY r.worksite_country ASC
    ");
    $stmt_ct->bind_param("i", $evaluator_id);
    $stmt_ct->execute();
    $res_ct = $stmt_ct->get_result();
    while ($row = $res_ct->fetch_assoc()) {
        $evaluator_countries[] = $row['worksite_country'];
    }
    $stmt_ct->close();

    // Province comes from the applicant (clients.province), scoped to
    // only the applications currently assigned to this evaluator.
    $stmt_pv = $database->prepare("
        SELECT DISTINCT c.province
        FROM applications a
        INNER JOIN clients c ON a.client_id = c.cid
        WHERE a.current_evaluator_id = ?
          AND c.province IS NOT NULL AND c.province <> ''
        ORDER BY c.province ASC
    ");
    $stmt_pv->bind_param("i", $evaluator_id);
    $stmt_pv->execute();
    $res_pv = $stmt_pv->get_result();
    while ($row = $res_pv->fetch_assoc()) {
        $evaluator_provinces[] = $row['province'];
    }
    $stmt_pv->close();
}

// CALABARZON's 5 fixed provinces — always offered since this is a
// Regional Office IV-A system, merged with whatever provinces actually
// appear in this evaluator's assigned applications, then deduped/sorted.
$calabarzon_provinces = ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'];
$evaluator_provinces  = array_values(array_unique(array_merge($calabarzon_provinces, $evaluator_provinces)));
sort($evaluator_provinces);

// Active assignment count — used by the floating dock's "Tasks" badge.
// Intentionally NOT filtered: it reflects actual current workload.
$my_assigned_count = 0;
if ($evaluator_id) {
    $stmt_cnt = $database->prepare("
        SELECT COUNT(*) as cnt 
        FROM applications 
        WHERE current_evaluator_id = ? 
          AND handling_party = 'evaluator'
    ");
    $stmt_cnt->bind_param("i", $evaluator_id);
    $stmt_cnt->execute();
    $my_assigned_count = (int)$stmt_cnt->get_result()->fetch_assoc()['cnt'];
    $stmt_cnt->close();
}

// Greeting
$hour       = (int)date('G');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($evaluator_name))[0] ?: $evaluator_name;

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

// Status pill → Tailwind utility classes (same hex values as before,
// just expressed as Tailwind's matching palette tokens)
function pill_classes(string $status): string
{
    return match ($status) {
        'approved'         => 'bg-emerald-100 text-emerald-800',
        'under_evaluation' => 'bg-indigo-50 text-indigo-800',
        'for_compliance'   => 'bg-orange-50 text-amber-800',
        'disapproved'      => 'bg-red-100 text-red-800',
        'assign', 'pending', 'assigned' => 'bg-blue-50 text-blue-800',
        'resubmitted'      => 'bg-indigo-50 text-indigo-800',
        default            => 'bg-slate-100 text-slate-600',
    };
}

// KPI card renderer (keeps the 8 overview cards consistent)
function kpi_card(
    string $label,
    string $value,
    string $sub,
    string $icon,
    string $tone,
    string $valueId = '',
    string $subId = '',
    string $href = '',
    string $cardId = ''
): string {
    $tones = [
        'blue'    => ['text-blue-600',    'bg-blue-50 text-blue-500'],
        'emerald' => ['text-emerald-600', 'bg-emerald-50 text-emerald-600'],
        'indigo'  => ['text-indigo-600',  'bg-indigo-50 text-indigo-600'],
        'amber'   => ['text-amber-600',   'bg-amber-50 text-amber-600'],
    ];
    [$valCls, $icoCls] = $tones[$tone] ?? $tones['blue'];
    $tag   = $href !== '' ? 'a' : 'div';
    $attrs = ($href !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES) . '"' : '')
        . ($cardId !== '' ? ' id="' . htmlspecialchars($cardId, ENT_QUOTES) . '"' : '');
    $extra = $href !== '' ? ' no-underline block hover:-translate-y-0.5' : '';
    $vId   = $valueId !== '' ? ' id="' . htmlspecialchars($valueId, ENT_QUOTES) . '"' : '';
    $sId   = $subId   !== '' ? ' id="' . htmlspecialchars($subId, ENT_QUOTES) . '"' : '';

    return '<' . $tag . $attrs . ' class="group min-w-0 bg-white border border-slate-200 rounded-2xl p-3.5 sm:p-4 shadow-sm hover:shadow-md transition-all duration-200' . $extra . '">'
        . '<div class="flex items-start justify-between gap-2">'
        . '<div class="min-w-0">'
        . '<div class="text-[10.5px] font-semibold text-slate-400 uppercase tracking-wide truncate">' . htmlspecialchars($label) . '</div>'
        . '<div' . $vId . ' class="text-2xl font-extrabold ' . $valCls . ' mt-1 truncate">' . htmlspecialchars($value) . '</div>'
        . '</div>'
        . '<div class="w-9 h-9 rounded-xl ' . $icoCls . ' flex items-center justify-center text-sm shrink-0 transition-transform duration-200 group-hover:scale-110"><i class="fas ' . htmlspecialchars($icon) . '"></i></div>'
        . '</div>'
        . '<div' . $sId . ' class="text-[10.5px] text-slate-400 font-medium mt-2 truncate">' . htmlspecialchars($sub) . '</div>'
        . '</' . $tag . '>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluator Dashboard – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* The only rule Tailwind's CDN can't express as a utility class,
           kept so the untouched topbar markup renders identically. */
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Short fade-in entrance; content stays visible if animation never runs */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp .3s ease both;
        }

        @media (prefers-reduced-motion: reduce) {
            .fade-in {
                animation: none;
            }
        }

        .row-d {
            display: flex !important;
            flex-direction: row;
            align-items: flex-start !important;
            gap: 16px;
        }

        .row-d-col {
            flex: 1 1 0;
            min-width: 0;
            align-self: flex-start !important;
            height: auto !important;
        }

        .row-d-col>[data-collapsible] {
            height: auto !important;
        }

        @media (max-width: 1023px) {
            .row-d {
                flex-direction: column;
            }

            .row-d-col {
                width: 100%;
                flex: none;
            }
        }

        [data-theme="dark"] body {
            background: #0f172a;
        }

        [data-theme="dark"] .bg-white {
            background-color: #1e293b !important;
        }

        [data-theme="dark"] .border-slate-200 {
            border-color: #2d3748 !important;
        }

        [data-theme="dark"] .border-slate-100 {
            border-color: #2d3748 !important;
        }

        [data-theme="dark"] .bg-slate-100 {
            background-color: #263449 !important;
        }

        [data-theme="dark"] .bg-slate-50 {
            background-color: #1a2332 !important;
        }

        [data-theme="dark"] .hover\:bg-slate-50:hover {
            background-color: #263449 !important;
        }

        [data-theme="dark"] .text-slate-400 {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .text-slate-700 {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .text-slate-800 {
            color: #f1f5f9 !important;
        }

        [data-theme="dark"] .text-slate-500 {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .text-slate-200 {
            color: #334155 !important;
        }

        [data-theme="dark"] .text-slate-600 {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .bg-blue-50 {
            background-color: rgba(59, 130, 246, .15) !important;
        }

        [data-theme="dark"] .bg-emerald-50 {
            background-color: rgba(16, 185, 129, .15) !important;
        }

        [data-theme="dark"] .bg-indigo-50 {
            background-color: rgba(99, 102, 241, .15) !important;
        }

        [data-theme="dark"] .bg-amber-50 {
            background-color: rgba(217, 119, 6, .15) !important;
        }

        [data-theme="dark"] .bg-orange-50 {
            background-color: rgba(234, 88, 12, .15) !important;
        }

        [data-theme="dark"] .bg-emerald-100 {
            background-color: rgba(16, 185, 129, .2) !important;
        }

        [data-theme="dark"] .bg-red-100 {
            background-color: rgba(220, 38, 38, .2) !important;
        }

        [data-theme="dark"] .text-blue-500 {
            color: #60a5fa !important;
        }

        [data-theme="dark"] .text-blue-600 {
            color: #60a5fa !important;
        }

        [data-theme="dark"] .text-blue-800 {
            color: #93c5fd !important;
        }

        [data-theme="dark"] .text-emerald-600 {
            color: #34d399 !important;
        }

        [data-theme="dark"] .text-emerald-800 {
            color: #6ee7b7 !important;
        }

        [data-theme="dark"] .text-indigo-600 {
            color: #a5b4fc !important;
        }

        [data-theme="dark"] .text-indigo-800 {
            color: #c7d2fe !important;
        }

        [data-theme="dark"] .text-amber-600 {
            color: #fbbf24 !important;
        }

        [data-theme="dark"] .text-amber-800 {
            color: #fcd34d !important;
        }

        [data-theme="dark"] .text-red-800 {
            color: #fca5a5 !important;
        }

        [data-theme="dark"] .shadow-sm,
        [data-theme="dark"] .hover\:shadow-md:hover,
        [data-theme="dark"] .hover\:shadow-lg:hover {
            box-shadow: none !important;
        }

        [data-theme="dark"] [role="listbox"].bg-white {
            background-color: #1e293b !important;
            border-color: #2d3748 !important;
        }

        [data-theme="dark"] [role="option"].hover\:bg-\[\#f1f5fb\]:hover {
            background-color: #263449 !important;
        }

        [data-theme="dark"] .bg-\[\#eff6ff\] {
            background-color: rgba(59, 130, 246, .18) !important;
        }

        [data-theme="dark"] .filter-pill,
        [data-theme="dark"] .filter-pill:hover,
        [data-theme="dark"] .filter-pill:focus,
        [data-theme="dark"] .filter-pill:focus-within,
        [data-theme="dark"] .filter-pill:active,
        [data-theme="dark"] .filter-pill button,
        [data-theme="dark"] .filter-pill button:hover,
        [data-theme="dark"] .filter-pill button:focus,
        [data-theme="dark"] .filter-pill button:active,
        [data-theme="dark"] .filter-pill button:focus-visible {
            background: transparent !important;
            background-color: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }

        [data-theme="dark"] .filter-pill button:focus-visible {
            box-shadow: 0 0 0 2px #60a5fa !important;
            border-radius: 6px;
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body class="font-sans">

    <!--TOPBAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; EVALUATOR PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>

            <!-- Dark mode toggle -->
            <button type="button" class="theme-toggle" title="Toggle dark mode">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>

            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <?php include __DIR__ . '/avatar-menu.php'; ?>
        </div>
    </header>

    <!--SUBBAR — unchanged -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></h2>
                <p>(<span id="available-count-subbar"><?= number_format($available_count) ?></span>) Pending Applications</p>
            </div>
        </div>

        <!-- Filter pills — options are pulled ONLY from job types/destinations/
             provinces this evaluator is currently assigned to
             (current_evaluator_id), so an evaluator never sees job types,
             countries, or provinces they don't handle. -->
        <div class="filter-scroll">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select id="filter-month">
                    <option value="">All Time</option>
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                        $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
                        $sel = ($val === $filter_month) ? ' selected' : '';
                        echo "<option value=\"$val\"$sel>$lbl</option>\n";
                    }
                    ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
                <select id="filter-province">
                    <option value="">CALABARZON — All Provinces</option>
                    <?php foreach ($evaluator_provinces as $pv): ?>
                        <option value="<?= htmlspecialchars($pv, ENT_QUOTES) ?>" <?= $pv === $filter_province ? 'selected' : '' ?>><?= htmlspecialchars($pv) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select id="filter-job">
                    <option value="">All Job Types</option>
                    <?php foreach ($evaluator_job_types as $jt): ?>
                        <option value="<?= htmlspecialchars($jt, ENT_QUOTES) ?>" <?= $jt === $filter_job ? 'selected' : '' ?>><?= htmlspecialchars($jt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select id="filter-country">
                    <option value="">All Destinations</option>
                    <?php foreach ($evaluator_countries as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= $c === $filter_country ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <main class="max-w-[1580px] mx-auto px-3 sm:px-4 lg:px-6 pt-3 pb-28 space-y-4 sm:space-y-5">

        <!-- KPIs: 2 cols mobile · 4 tablet/laptop · 8 on ultra-wide -->
        <section aria-label="Overview" class="fade-in">
            <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-slate-400 mb-2">Overview</p>
            <div class="grid grid-cols-2 md:grid-cols-4 2xl:grid-cols-8 gap-2.5 sm:gap-3">
                <?= kpi_card('Total Applications', number_format($stats['total']), 'All applications you review', 'fa-file-lines', 'blue', 'kpi-total') ?>
                <?= kpi_card('Open for Evaluation', number_format($available_count), 'Unclaimed — first to open it gets it', 'fa-inbox', 'emerald', 'available-count', '', 'applications.php?status=available', 'available-kpi-card') ?>
                <?= kpi_card('Under Evaluation', number_format($stats['under_evaluation']), 'Currently being reviewed by you', 'fa-magnifying-glass', 'indigo', 'kpi-under-evaluation', '', 'applications.php?status=under_evaluation') ?>
                <?= kpi_card('Approved', number_format($stats['approved']), 'Approved applications you evaluated', 'fa-circle-check', 'emerald', 'kpi-approved') ?>

                <?= kpi_card('My Active Tasks', number_format($workload['active_tasks']), $workload['with_admin'] . ' awaiting applicant/admin', 'fa-list-check', 'indigo', '', '', 'applications.php?status=under_evaluation') ?>
                <?= kpi_card('Decided This Week', number_format($workload['decided_week']), $workload['decided_today'] . ' today · last 7 days', 'fa-calendar-check', 'blue') ?>
                <?= kpi_card(
                    'Approval Rate',
                    $workload['approval_rate'] !== null ? $workload['approval_rate'] . '%' : '—',
                    $workload['approved'] . ' approved · ' . $workload['disapproved'] . ' disapproved',
                    'fa-chart-pie',
                    'emerald'
                ) ?>
                <?= kpi_card('Oldest in Queue', $incoming['oldest_display'], 'Avg wait ' . $incoming['avg_display'] . ' · open queue', 'fa-hourglass-half', 'amber', 'kpi-oldest', 'kpi-avgwait') ?>
            </div>
        </section>

        <!-- Quick Access (compact) -->
        <section aria-label="Quick access" class="fade-in">
            <div class="grid grid-cols-3 gap-2 sm:gap-2.5">
                <a href="applications.php" class="group bg-white border border-slate-200 rounded-2xl px-2 py-2.5 sm:px-3 flex flex-col sm:flex-row items-center gap-1.5 sm:gap-2.5 text-center sm:text-left no-underline text-slate-500 font-bold text-[11px] sm:text-[12px] transition-all duration-200 hover:bg-slate-50 hover:-translate-y-0.5 hover:shadow-md active:scale-[.98]">
                    <span class="w-8 h-8 rounded-[10px] flex items-center justify-center text-sm bg-blue-50 text-blue-500 shrink-0"><i class="fas fa-list"></i></span>
                    <span class="truncate">My Assignments</span>
                </a>
                <a href="applications.php?status=for_compliance" class="group bg-white border border-slate-200 rounded-2xl px-2 py-2.5 sm:px-3 flex flex-col sm:flex-row items-center gap-1.5 sm:gap-2.5 text-center sm:text-left no-underline text-slate-500 font-bold text-[11px] sm:text-[12px] transition-all duration-200 hover:bg-slate-50 hover:-translate-y-0.5 hover:shadow-md active:scale-[.98]">
                    <span class="w-8 h-8 rounded-[10px] flex items-center justify-center text-sm bg-amber-50 text-amber-600 shrink-0"><i class="fas fa-triangle-exclamation"></i></span>
                    <span class="truncate">For Compliance</span>
                </a>
                <a href="reports.php" class="group bg-white border border-slate-200 rounded-2xl px-2 py-2.5 sm:px-3 flex flex-col sm:flex-row items-center gap-1.5 sm:gap-2.5 text-center sm:text-left no-underline text-slate-500 font-bold text-[11px] sm:text-[12px] transition-all duration-200 hover:bg-slate-50 hover:-translate-y-0.5 hover:shadow-md active:scale-[.98]">
                    <span class="w-8 h-8 rounded-[10px] flex items-center justify-center text-sm bg-emerald-50 text-emerald-600 shrink-0"><i class="fas fa-chart-bar"></i></span>
                    <span class="truncate">Reports</span>
                </a>
            </div>
        </section>

        <!-- Row A: Open for Evaluation + My Assigned -->
        <section class="grid grid-cols-1 lg:grid-cols-12 gap-4 fade-in">

            <div class="lg:col-span-7 min-w-0 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-col lg:h-[520px]">
                <div class="flex items-start justify-between gap-3 mb-2">
                    <div class="min-w-0">
                        <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            Open for Evaluation
                            <span id="incoming-count" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800"><?= (int)$incoming['total'] ?></span>
                        </span>
                        <p class="text-[10.5px] text-slate-400 font-medium mt-0.5">Oldest first · opening an application assigns it to you</p>
                    </div>
                    <a href="applications.php?status=available" class="text-[11px] font-bold text-blue-500 hover:text-blue-600 no-underline flex items-center gap-1 shrink-0">
                        View all <i class="fas fa-arrow-right text-[9px]"></i>
                    </a>
                </div>
                <div id="incoming-list" class="flex flex-col max-h-[420px] lg:max-h-none lg:flex-1 lg:min-h-0 overflow-y-auto pr-0.5 [scrollbar-width:thin]"></div>
                <p id="incoming-footer" class="text-[10.5px] text-slate-400 font-medium pt-2 mt-1 border-t border-slate-100"></p>
            </div>

            <div class="lg:col-span-5 min-w-0 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-col lg:h-[520px]">
                <div class="flex items-center justify-between mb-2">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                        My Assigned Applications
                    </span>
                    <a href="applications.php" class="text-[11px] font-bold text-blue-500 hover:text-blue-600 no-underline flex items-center gap-1">
                        View all <i class="fas fa-arrow-right text-[9px]"></i>
                    </a>
                </div>

                <div class="flex flex-col max-h-[420px] lg:max-h-none lg:flex-1 lg:min-h-0 overflow-y-auto pr-0.5 [scrollbar-width:thin]" id="my-applications-list">
                    <?php if (empty($my_applications)): ?>
                        <div class="py-10 px-4 text-center">
                            <i class="fas fa-inbox text-3xl text-slate-200 block mb-2.5"></i>
                            <p class="text-[12.5px] font-semibold text-slate-400">No applications assigned yet</p>
                            <span class="text-[11px] text-slate-400/80">Applications assigned to you will appear here once processed.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_applications as $app): ?>
                            <?php
                            $status     = strtolower($app['status'] ?? 'unknown');
                            $statusText = str_replace('_', ' ', ucwords($status));
                            $pillClass  = pill_classes($status);

                            $np  = explode(' ', trim($app['applicant_name']));
                            $avi = strtoupper(count($np) >= 2
                                ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                                : substr($app['applicant_name'], 0, 2));

                            $app_has_pic = !empty($app['applicant_picture']);
                            $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                            ?>
                            <a href="view-application.php?id=<?= (int)$app['id'] ?>"
                                class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
                                <div class="w-9 h-9 shrink-0 rounded-[10px] bg-indigo-50 text-indigo-600 text-[10.5px] font-extrabold tracking-wide flex items-center justify-center"
                                    <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                                    <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[12.5px] font-bold text-slate-800 truncate"><?= htmlspecialchars($app['applicant_name']) ?></div>
                                    <div class="text-[10.5px] text-slate-400 font-medium mt-0.5 truncate">
                                        <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                                        &nbsp;·&nbsp;<?= htmlspecialchars($app['position_title'] ?? '—') ?>
                                        &nbsp;·&nbsp;<?= htmlspecialchars($app['country'] ?? '—') ?>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-block text-[9.5px] font-bold tracking-wider uppercase px-2.5 py-[3px] rounded-full <?= $pillClass ?>">
                                        <?= htmlspecialchars($statusText) ?>
                                    </span>
                                    <div class="text-[9.5px] text-slate-400 font-medium mt-[3px]">
                                        <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Row B: Status · Queue Aging · Turnaround -->
        <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 fade-in">

            <div class="min-w-0 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                        Application Status
                    </span>
                </div>
                <div id="status-bars" class="flex flex-col gap-3 mb-4"></div>
                <div class="flex items-center gap-4 pt-4 border-t border-slate-100">
                    <div class="shrink-0 w-[110px] sm:w-[120px]"><canvas id="statusChart" width="120" height="120"></canvas></div>
                    <div id="status-legend" class="flex-1 min-w-0"></div>
                </div>
            </div>

            <div class="min-w-0 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        Open Queue Aging
                    </span>
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Since submission</span>
                </div>
                <div id="aging-bars" class="flex flex-col gap-3 mb-4"></div>
                <div class="pt-4 border-t border-slate-100">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mb-2">Top destinations waiting</p>
                    <div id="dest-bars" class="flex flex-col gap-2.5"></div>
                </div>
            </div>

            <div class="min-w-0 md:col-span-2 xl:col-span-1 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        Avg. Turnaround Time
                    </span>
                </div>

                <div class="text-center py-2 mb-3 border-b border-slate-100">
                    <div class="text-3xl font-extrabold text-blue-600" id="turnaround-overall"><?= format_turnaround($turnaround_overall_hours) ?></div>
                    <div class="text-[10.5px] font-semibold text-slate-400 uppercase tracking-wide mt-1">Assignment &rarr; Decision</div>
                </div>

                <div class="flex flex-col gap-3" id="turnaround-rows">
                    <?php
                    $turn_max = max(
                        $turnaround_by_decision['approved']['avg_hours'],
                        $turnaround_by_decision['disapproved']['avg_hours'],
                        $turnaround_by_decision['for_compliance']['avg_hours'],
                        1
                    );
                    $turn_rows = [
                        'approved'       => ['label' => 'Approved',       'color' => '#059669'],
                        'disapproved'    => ['label' => 'Disapproved',    'color' => '#dc2626'],
                        'for_compliance' => ['label' => 'For Compliance', 'color' => '#d97706'],
                    ];
                    $turn_any = false;
                    foreach ($turn_rows as $key => $meta):
                        $val = $turnaround_by_decision[$key]['avg_hours'];
                        $cnt = $turnaround_by_decision[$key]['cnt'];
                        if ($cnt === 0) continue;
                        $turn_any = true;
                        $pct = round($val / $turn_max * 100);
                    ?>
                        <div class="flex items-center gap-3">
                            <span class="text-[11.5px] font-bold text-slate-700 w-[92px] shrink-0 truncate"><?= $meta['label'] ?></span>
                            <div class="flex-1 h-[8px] bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></div>
                            </div>
                            <span class="text-[11px] font-extrabold text-slate-800 w-9 text-right shrink-0"><?= format_turnaround($val) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$turn_any): ?>
                        <p class="text-[11px] text-slate-400 italic">No completed evaluations with matching assignment data yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Row C: Evaluation Trend + Country -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start fade-in">
            <div class="min-w-0 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Your Evaluation Trend
                    </span>
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Last 6 Months</span>
                </div>
                <div class="relative w-full h-[240px] sm:h-[280px]"><canvas id="evalTrendChart"></canvas></div>
            </div>

            <div class="min-w-0 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                        Applications by Country
                    </span>
                </div>
                <div class="relative w-full h-[240px] sm:h-[280px]"><canvas id="countryChart"></canvas></div>
            </div>
        </section>

        <!-- Row D: Document completion + Recent activity -->
        <section class="row-d fade-in">

            <div class="row-d-col">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm" data-collapsible data-visible-items="4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            Document Completion
                        </span>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">
                            <?= $attention['avg_completion'] !== null ? 'Avg ' . (int)$attention['avg_completion'] . '%' : 'My active applications' ?>
                        </span>
                    </div>
                    <?php if (empty($attention['items'])): ?>
                        <div class="py-8 px-4 text-center">
                            <i class="fas fa-folder-open text-3xl text-slate-200 block mb-2.5"></i>
                            <p class="text-[12.5px] font-semibold text-slate-400">No active applications to check</p>
                        </div>
                    <?php else: ?>
                        <div class="flex flex-col pr-0.5 [scrollbar-width:thin]" data-collapse-list>
                            <?php foreach ($attention['items'] as $it):
                                $p = $it['pct'];
                                $barColor = $p === null ? '#94a3b8' : ($p >= 100 ? '#059669' : ($p >= 50 ? '#d97706' : '#dc2626'));
                            ?>
                                <a href="view-application.php?id=<?= (int)$it['id'] ?>" class="block py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="text-[12.5px] font-bold text-slate-800 truncate"><?= htmlspecialchars($it['applicant_name']) ?></div>
                                            <div class="text-[10.5px] text-slate-400 font-medium truncate"><?= htmlspecialchars($it['rsf_number'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($it['position_title'] ?? '—') ?></div>
                                        </div>
                                        <span class="shrink-0 text-[11px] font-extrabold text-slate-800"><?= $p !== null ? (int)$p . '%' : '—' ?></span>
                                    </div>
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <div class="flex-1 h-[6px] bg-slate-100 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full" style="width:<?= (int)($p ?? 0) ?>%;background:<?= $barColor ?>"></div>
                                        </div>
                                        <span class="text-[10px] text-slate-400 font-medium shrink-0">
                                            <?= $it['req_total'] > 0
                                                ? (int)$it['req_done'] . '/' . (int)$it['req_total'] . ' required'
                                                : 'No required docs · ' . (int)$it['uploaded_total'] . ' uploaded' ?>
                                            &nbsp;·&nbsp; held <?= htmlspecialchars($it['held_display']) ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" data-collapse-toggle aria-expanded="false"
                            class="hidden w-full mt-2 pt-2 border-t border-slate-100 text-[11px] font-bold text-blue-500 hover:text-blue-600 bg-transparent cursor-pointer items-center justify-center gap-1.5">
                            <span data-collapse-label>Show All</span>
                            <i class="fas fa-chevron-down text-[9px] transition-transform" data-collapse-icon></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row-d-col">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm" data-collapsible>
                    <div class="flex items-center justify-between mb-2">
                        <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            Recent Activity
                        </span>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wide">Your last actions</span>
                    </div>
                    <?php if (empty($activity)): ?>
                        <div class="py-8 px-4 text-center">
                            <i class="fas fa-clock-rotate-left text-3xl text-slate-200 block mb-2.5"></i>
                            <p class="text-[12.5px] font-semibold text-slate-400">No activity recorded yet</p>
                        </div>
                    <?php else: ?>
                        <div class="flex flex-col pr-0.5 [scrollbar-width:thin]" data-collapse-list>
                            <?php foreach ($activity as $ev): ?>
                                <a href="view-application.php?id=<?= (int)$ev['application_id'] ?>" class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
                                    <div class="w-8 h-8 shrink-0 rounded-[10px] flex items-center justify-center text-xs <?= htmlspecialchars($ev['tone']) ?>">
                                        <i class="fas <?= htmlspecialchars($ev['icon']) ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[12.5px] font-bold text-slate-800 truncate"><?= htmlspecialchars($ev['label']) ?> · <?= htmlspecialchars($ev['applicant_name']) ?></div>
                                        <div class="text-[10.5px] text-slate-400 font-medium truncate"><?= htmlspecialchars($ev['position_title'] ?? '—') ?></div>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-medium shrink-0"><?= htmlspecialchars($ev['ago']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" data-collapse-toggle aria-expanded="false"
                            class="hidden w-full mt-2 pt-2 border-t border-slate-100 text-[11px] font-bold text-blue-500 hover:text-blue-600 bg-transparent cursor-pointer items-center justify-center gap-1.5">
                            <span data-collapse-label>Show All</span>
                            <i class="fas fa-chevron-down text-[9px] transition-transform" data-collapse-icon></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main><!-- /main -->

    <!-- FLOATING DOCK — unchanged -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk active">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
                <?php if ($my_assigned_count > 0): ?>
                    <span class="dk-badge"><?= $my_assigned_count > 99 ? '99+' : $my_assigned_count ?></span>
                <?php endif; ?>
            </a>

            <div class="dk-div"></div>

            <a href="evaluator-reports.php" class="dk" data-tip="Applicant Reports">
                <i class="fas fa-file-contract"></i>
                <span class="dk-lbl">Applicants</span>
            </a>

            <a href="reports.php" class="dk">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>

            <div class="dk-div"></div>

            <a href="settings.php" class="dk">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>

            <div class="dk" onclick="logout('Evaluator')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <script>
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
    </script>

    <!-- Filter pills: month / province / job type / destination.
         Selecting any pill re-fetches dashboard data via AJAX (see the
         dashboard-charts script below) and re-renders every section in
         place — no page reload. -->
    <script>
        (function() {
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
                panel.className = 'hidden fixed w-56 bg-white border-[1.5px] border-[var(--border)] rounded-lg shadow-[0_10px_30px_rgba(11,31,75,.16)] z-[999] overflow-hidden py-1 max-h-[240px] overflow-y-auto';
                document.body.appendChild(panel);

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.className = 'flex items-center justify-between gap-2 py-[7px] px-3 text-[12px] text-[var(--text1)] cursor-pointer hover:bg-[#f1f5fb] transition-colors';
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
                        row.classList.toggle('bg-[#eff6ff]', selected);
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
            }

            const monthSel = document.getElementById('filter-month');
            const provinceSel = document.getElementById('filter-province');
            const jobSel = document.getElementById('filter-job');
            const countrySel = document.getElementById('filter-country');

            [monthSel, provinceSel, jobSel, countrySel].forEach(enhancePillSelect);

            // fetchAndApplyFilters() is defined in the dashboard-charts
            // script below; it fetches ajax-dashboard-data.php and
            // re-renders every section in place — no reload.
            if (monthSel) monthSel.addEventListener('change', () => window.fetchAndApplyFilters());
            if (provinceSel) provinceSel.addEventListener('change', () => window.fetchAndApplyFilters());
            if (jobSel) jobSel.addEventListener('change', () => window.fetchAndApplyFilters());
            if (countrySel) countrySel.addEventListener('change', () => window.fetchAndApplyFilters());
        })();
    </script>

    <!-- Dashboard charts + no-reload filtering -->
    <script>
        let statusChartInstance, countryChartInstance, evalTrendChartInstance;

        Chart.defaults.font.family = "'DM Sans', sans-serif";

        function isDarkMode() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }
        Chart.defaults.color = isDarkMode() ? '#94a3b8' : '#64748b';
        let chartGridColor = isDarkMode() ? '#2d3748' : '#f1f5f9';
        let chartRingColor = isDarkMode() ? '#1e293b' : '#fff';

        const statusLabels = ['Assign', 'Under Eval', 'For Compliance', 'Approved', 'Disapproved'];
        const statusColors = ['#3b82f6', '#4f46e5', '#d97706', '#059669', '#dc2626'];
        const statusKeys = ['assign', 'under_evaluation', 'for_compliance', 'approved', 'disapproved'];

        // Escapes text AND quotes so values are safe inside attributes too
        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, ch => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [ch]));
        }
        // Mirrors $locked_statuses in applications.php
        const LOCKED_STATUSES = ['for_compliance', 'resubmitted', 'approved', 'disapproved'];

        // "Open for Evaluation": confirm before claiming (same wording as applications.php)
        function claimAndOpen(id) {
            Swal.fire({
                icon: 'question',
                title: 'Evaluate this application?',
                text: 'Opening this will claim it for evaluation and assign it to you. It will then leave the shared Open for Evaluation queue and other evaluators will no longer be able to pick it up.',
                showCancelButton: true,
                confirmButtonColor: '#1e40af',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Yes, claim & evaluate',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (result.isConfirmed) window.location = 'view-application.php?id=' + id;
            });
            return false;
        }

        // "My Assigned Applications": block locked cases with a popup
        function openAssigned(id, status) {
            if (LOCKED_STATUSES.includes(status)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Application Locked',
                    text: 'This application is currently with Admin or has already been finalized. It cannot be accessed until it is reopened or reassigned to you.',
                    confirmButtonColor: '#1e40af',
                    confirmButtonText: 'Understood'
                });
                return false;
            }
            window.location = 'view-application.php?id=' + id;
            return false;
        }

        function pillClasses(status) {
            switch (status) {
                case 'approved':
                    return 'bg-emerald-100 text-emerald-800';
                case 'under_evaluation':
                    return 'bg-indigo-50 text-indigo-800';
                case 'for_compliance':
                    return 'bg-orange-50 text-amber-800';
                case 'disapproved':
                    return 'bg-red-100 text-red-800';
                case 'assign':
                case 'pending':
                case 'assigned':
                    return 'bg-blue-50 text-blue-800';
                case 'resubmitted':
                    return 'bg-indigo-50 text-indigo-800';
                default:
                    return 'bg-slate-100 text-slate-600';
            }
        }

        function renderStatusChart(stats) {
            const data = statusKeys.map(k => stats[k] || 0);
            const total = data.reduce((a, b) => a + b, 0);
            const maxVal = Math.max(...data, 1);

            const barsEl = document.getElementById('status-bars');
            barsEl.innerHTML = '';
            statusLabels.forEach((label, i) => {
                if (data[i] === 0) return;
                barsEl.innerHTML += `
            <div class="flex items-center gap-3">
                <span class="text-[12.5px] font-bold text-slate-700 w-[110px] shrink-0 truncate">${label}</span>
                <div class="flex-1 h-[10px] bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all" style="width:${Math.round(data[i]/maxVal*100)}%;background:${statusColors[i]}"></div>
                </div>
                <span class="text-[12.5px] font-extrabold text-slate-800 w-5 text-right shrink-0">${data[i]}</span>
            </div>`;
            });
            if (total === 0) barsEl.innerHTML = '<p class="text-[11px] text-slate-400 italic">No applications yet.</p>';

            if (!statusChartInstance) {
                statusChartInstance = new Chart(document.getElementById('statusChart'), {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data,
                            backgroundColor: statusColors,
                            borderWidth: 4,
                            borderColor: chartRingColor,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        cutout: '68%',
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            } else {
                statusChartInstance.data.datasets[0].data = data;
                statusChartInstance.update();
            }

            const legendEl = document.getElementById('status-legend');
            legendEl.innerHTML = '';
            statusLabels.forEach((label, i) => {
                if (data[i] === 0) return;
                const pct = total > 0 ? Math.round(data[i] / total * 100) : 0;
                legendEl.innerHTML += `
            <div class="flex items-center gap-2.5 py-[7px] border-b border-slate-100 last:border-0">
                <span class="w-[11px] h-[11px] rounded-full shrink-0" style="background:${statusColors[i]}"></span>
                <span class="text-[13px] font-semibold text-slate-700 flex-1">${label}</span>
                <span class="text-[13px] font-extrabold text-slate-800">${pct}%</span>
            </div>`;
            });
            if (total === 0) legendEl.innerHTML = '<p class="text-[11px] text-slate-400 italic">No data yet.</p>';
        }

        function renderCountryChart(countryData) {
            const labels = countryData.map(c => c.country);
            const totals = countryData.map(c => c.total);
            const approved = countryData.map(c => c.approved);

            if (!countryChartInstance) {
                countryChartInstance = new Chart(document.getElementById('countryChart'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                                label: 'All Reviewed',
                                data: totals,
                                backgroundColor: '#3b82f6',
                                borderRadius: 4,
                                maxBarThickness: 28
                            },
                            {
                                label: 'Approved',
                                data: approved,
                                backgroundColor: '#059669',
                                borderRadius: 4,
                                maxBarThickness: 28
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 11,
                                        weight: '600'
                                    }
                                }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 11
                                    }
                                },
                                grid: {
                                    color: chartGridColor
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 8,
                                    boxHeight: 8,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    font: {
                                        size: 11,
                                        weight: '600'
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                countryChartInstance.data.labels = labels;
                countryChartInstance.data.datasets[0].data = totals;
                countryChartInstance.data.datasets[1].data = approved;
                countryChartInstance.update();
            }
        }

        function renderTrendChart(trend) {
            if (!evalTrendChartInstance) {
                evalTrendChartInstance = new Chart(document.getElementById('evalTrendChart'), {
                    type: 'bar',
                    data: {
                        labels: trend.labels,
                        datasets: [{
                                label: 'Approved',
                                data: trend.approved,
                                backgroundColor: '#059669',
                                borderRadius: 4,
                                maxBarThickness: 22
                            },
                            {
                                label: 'Disapproved',
                                data: trend.disapproved,
                                backgroundColor: '#dc2626',
                                borderRadius: 4,
                                maxBarThickness: 22
                            },
                            {
                                label: 'For Compliance',
                                data: trend.compliance,
                                backgroundColor: '#d97706',
                                borderRadius: 4,
                                maxBarThickness: 22
                            }
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
                                        size: 11,
                                        weight: '600'
                                    }
                                }
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 11
                                    }
                                },
                                grid: {
                                    color: chartGridColor
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 8,
                                    boxHeight: 8,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    font: {
                                        size: 11,
                                        weight: '600'
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                evalTrendChartInstance.data.labels = trend.labels;
                evalTrendChartInstance.data.datasets[0].data = trend.approved;
                evalTrendChartInstance.data.datasets[1].data = trend.disapproved;
                evalTrendChartInstance.data.datasets[2].data = trend.compliance;
                evalTrendChartInstance.update();
            }
        }

        function renderTurnaround(turn) {
            document.getElementById('turnaround-overall').textContent = turn.overall_display;

            const rowsEl = document.getElementById('turnaround-rows');
            const meta = {
                approved: {
                    label: 'Approved',
                    color: '#059669'
                },
                disapproved: {
                    label: 'Disapproved',
                    color: '#dc2626'
                },
                for_compliance: {
                    label: 'For Compliance',
                    color: '#d97706'
                }
            };
            const maxVal = Math.max(
                turn.by_decision.approved.avg_hours,
                turn.by_decision.disapproved.avg_hours,
                turn.by_decision.for_compliance.avg_hours,
                1
            );

            rowsEl.innerHTML = '';
            let any = false;
            Object.keys(meta).forEach(key => {
                const d = turn.by_decision[key];
                if (d.cnt === 0) return;
                any = true;
                const pct = Math.round(d.avg_hours / maxVal * 100);
                rowsEl.innerHTML += `
            <div class="flex items-center gap-3">
                <span class="text-[11.5px] font-bold text-slate-700 w-[92px] shrink-0 truncate">${meta[key].label}</span>
                <div class="flex-1 h-[8px] bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width:${pct}%;background:${meta[key].color}"></div>
                </div>
                <span class="text-[11px] font-extrabold text-slate-800 w-9 text-right shrink-0">${d.display}</span>
            </div>`;
            });
            if (!any) {
                rowsEl.innerHTML = '<p class="text-[11px] text-slate-400 italic">No completed evaluations with matching assignment data yet.</p>';
            }
        }

        function renderApplicationsList(apps) {
            const list = document.getElementById('my-applications-list');
            if (apps.length === 0) {
                list.innerHTML = `
            <div class="py-10 px-4 text-center">
                <i class="fas fa-filter-circle-xmark text-3xl text-slate-200 block mb-2.5"></i>
                <p class="text-[12.5px] font-semibold text-slate-400">No assigned applications match this filter</p>
                <span class="text-[11px] text-slate-400/80">Try a different month, province, job type, or destination.</span>
            </div>`;
                return;
            }

            list.innerHTML = apps.map(app => {
                const status = (app.status || 'unknown').toLowerCase();
                const nameParts = (app.applicant_name || '').trim().split(' ');
                const initials = nameParts.length >= 2 ?
                    (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase() :
                    (app.applicant_name || '').substring(0, 2).toUpperCase();

                const avatarStyle = app.picture_url ?
                    `style="background-image:url('${escapeHtml(app.picture_url)}');background-size:cover;background-position:center;"` :
                    '';

                return `
            <a href="view-application.php?id=${app.id}" class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
                <div class="w-9 h-9 shrink-0 rounded-[10px] bg-indigo-50 text-indigo-600 text-[10.5px] font-extrabold tracking-wide flex items-center justify-center" ${avatarStyle}>
                    ${app.picture_url ? '' : escapeHtml(initials)}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[12.5px] font-bold text-slate-800 truncate">${escapeHtml(app.applicant_name)}</div>
                    <div class="text-[10.5px] text-slate-400 font-medium mt-0.5 truncate">
                        ${escapeHtml(app.rsf_number || '—')} &nbsp;·&nbsp;${escapeHtml(app.position_title || '—')} &nbsp;·&nbsp;${escapeHtml(app.country || '—')}
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <span class="inline-block text-[9.5px] font-bold tracking-wider uppercase px-2.5 py-[3px] rounded-full ${pillClasses(status)}">
                        ${escapeHtml(app.status_text)}
                    </span>
                    <div class="text-[9.5px] text-slate-400 font-medium mt-[3px]">${escapeHtml(app.updated_display)}</div>
                </div>
            </a>`;
            }).join('');
        }

        function applyDashboardData(data) {
            document.getElementById('kpi-total').textContent = data.stats.total.toLocaleString();
            document.getElementById('kpi-under-evaluation').textContent = data.stats.under_evaluation.toLocaleString();
            document.getElementById('kpi-approved').textContent = data.stats.approved.toLocaleString();
            document.getElementById('available-count').textContent = data.available_count.toLocaleString();
            document.getElementById('available-count-subbar').textContent = data.available_count.toLocaleString();

            renderApplicationsList(data.my_applications);
            renderStatusChart(data.stats);
            renderCountryChart(data.country_data);
            renderTrendChart(data.eval_trend);
            renderTurnaround(data.turnaround);
        }

        // Initial render from the PHP-embedded data (no fetch needed on load)
        applyDashboardData({
            stats: <?= json_encode($stats, EVX_JSON) ?>,
            available_count: <?= (int)$available_count ?>,
            my_applications: <?= json_encode(array_map(function ($app) {
                                    $app['status_text']     = str_replace('_', ' ', ucwords(strtolower($app['status'] ?? 'unknown')));
                                    $app['updated_display'] = $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—';
                                    $app['picture_url']     = !empty($app['applicant_picture']) ? '../' . $app['applicant_picture'] : null;
                                    return $app;
                                }, $my_applications), EVX_JSON) ?>,
            country_data: <?= json_encode($country_data, EVX_JSON) ?>,
            eval_trend: {
                labels: <?= json_encode($eval_trend_labels, EVX_JSON) ?>,
                approved: <?= json_encode($eval_trend_approved, EVX_JSON) ?>,
                disapproved: <?= json_encode($eval_trend_disapp, EVX_JSON) ?>,
                compliance: <?= json_encode($eval_trend_compliance, EVX_JSON) ?>
            },
            turnaround: {
                overall_display: <?= json_encode(format_turnaround($turnaround_overall_hours), EVX_JSON) ?>,
                by_decision: {
                    approved: {
                        avg_hours: <?= (float)$turnaround_by_decision['approved']['avg_hours'] ?>,
                        cnt: <?= (int)$turnaround_by_decision['approved']['cnt'] ?>,
                        display: <?= json_encode(format_turnaround($turnaround_by_decision['approved']['avg_hours']), EVX_JSON) ?>
                    },
                    disapproved: {
                        avg_hours: <?= (float)$turnaround_by_decision['disapproved']['avg_hours'] ?>,
                        cnt: <?= (int)$turnaround_by_decision['disapproved']['cnt'] ?>,
                        display: <?= json_encode(format_turnaround($turnaround_by_decision['disapproved']['avg_hours']), EVX_JSON) ?>
                    },
                    for_compliance: {
                        avg_hours: <?= (float)$turnaround_by_decision['for_compliance']['avg_hours'] ?>,
                        cnt: <?= (int)$turnaround_by_decision['for_compliance']['cnt'] ?>,
                        display: <?= json_encode(format_turnaround($turnaround_by_decision['for_compliance']['avg_hours']), EVX_JSON) ?>
                    }
                }
            }
        });

        // ── "Open for Evaluation" queue + aging ──────────────────────
        const agingLabels = ['Under 24 hours', '1–3 days', '3–7 days', '7+ days'];
        const agingColors = ['#059669', '#3b82f6', '#d97706', '#dc2626'];
        const tierClasses = {
            new: 'bg-emerald-100 text-emerald-800',
            normal: 'bg-slate-100 text-slate-600',
            aging: 'bg-orange-50 text-amber-800',
            stale: 'bg-red-100 text-red-800'
        };

        function emptyState(icon, title, sub) {
            return `<div class="py-10 px-4 text-center">
        <i class="fas ${icon} text-3xl text-slate-200 block mb-2.5"></i>
        <p class="text-[12.5px] font-semibold text-slate-400">${title}</p>
        <span class="text-[11px] text-slate-400/80">${sub}</span></div>`;
        }

        function barRowHtml(label, value, max, color, labelWidth) {
            const pct = max > 0 ? Math.round(value / max * 100) : 0;
            return `<div class="flex items-center gap-3">
        <span class="text-[11.5px] font-bold text-slate-700 ${labelWidth} shrink-0 truncate">${escapeHtml(label)}</span>
        <div class="flex-1 h-[8px] bg-slate-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width:${pct}%;background:${color}"></div>
        </div>
        <span class="text-[11px] font-extrabold text-slate-800 w-6 text-right shrink-0">${value}</span></div>`;
        }

        function incomingItemHtml(it) {
            const name = (it.applicant_name || '').trim();
            const parts = name.split(/\s+/);
            const initials = (parts.length >= 2 ? parts[0][0] + parts[parts.length - 1][0] : name.substring(0, 2)).toUpperCase();
            const pic = it.picture_url ?
                `style="background-image:url('${escapeHtml(it.picture_url)}');background-size:cover;background-position:center;"` : '';
            const docsOk = it.docs_total > 0 && it.docs_done >= it.docs_total;
            const docs = it.docs_total > 0 ?
                `<span class="${docsOk ? 'text-emerald-600' : 'text-amber-600'}" title="Required documents uploaded"><i class="fas fa-paperclip text-[9px]"></i> ${it.docs_done}/${it.docs_total} docs</span>` : '';
            const prov = it.province ?
                `<span><i class="fas fa-location-dot text-[9px]"></i> ${escapeHtml(it.province)}</span>` : '';

            return `<a href="javascript:void(0)" onclick="return claimAndOpen(${Number(it.id)})" class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
        <div class="w-9 h-9 shrink-0 rounded-[10px] bg-emerald-50 text-emerald-600 text-[10.5px] font-extrabold tracking-wide flex items-center justify-center" ${pic}>${it.picture_url ? '' : escapeHtml(initials)}</div>
        <div class="flex-1 min-w-0">
            <div class="text-[12.5px] font-bold text-slate-800 truncate">${escapeHtml(name)}</div>
            <div class="text-[10.5px] text-slate-400 font-medium mt-0.5 truncate">${escapeHtml(it.rsf_number || '—')} &nbsp;·&nbsp;${escapeHtml(it.position_title || '—')} &nbsp;·&nbsp;${escapeHtml(it.country || '—')}</div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10.5px] text-slate-400 font-medium mt-0.5">${prov}${docs}</div>
        </div>
        <div class="text-right shrink-0">
            <span class="inline-block text-[9.5px] font-bold tracking-wider uppercase px-2.5 py-[3px] rounded-full ${tierClasses[it.tier] || tierClasses.normal}" title="Waiting since submission">${escapeHtml(it.waiting_display)}</span>
            <div class="text-[10px] font-bold text-blue-500 mt-1">Review <i class="fas fa-arrow-right text-[8px]"></i></div>
        </div>
    </a>`;
        }

        function applyIncomingData(d) {
            document.getElementById('incoming-count').textContent = d.total.toLocaleString();

            const list = document.getElementById('incoming-list');
            list.innerHTML = d.items.length ?
                d.items.map(incomingItemHtml).join('') :
                (d.filtered ?
                    emptyState('fa-filter-circle-xmark', 'No open applications match this filter', 'Try a different month, province, job type, or destination.') :
                    emptyState('fa-circle-check', 'No applications are waiting', 'New submissions will appear here automatically.'));

            document.getElementById('incoming-footer').textContent = d.total > d.items.length ?
                `Showing ${d.items.length} of ${d.total} — open "View all" for the full queue` :
                (d.total ? `Showing all ${d.total}` : '');

            document.getElementById('kpi-oldest').textContent = d.oldest_display;
            document.getElementById('kpi-avgwait').textContent = `Avg wait ${d.avg_display} · open queue`;

            const maxB = Math.max(...d.buckets, 1);
            document.getElementById('aging-bars').innerHTML = d.total ?
                d.buckets.map((v, i) => barRowHtml(agingLabels[i], v, maxB, agingColors[i], 'w-[104px]')).join('') :
                '<p class="text-[11px] text-slate-400 italic">The open queue is empty.</p>';

            const maxD = Math.max(...d.destinations.map(x => x.cnt), 1);
            document.getElementById('dest-bars').innerHTML = d.destinations.length ?
                d.destinations.map(x => barRowHtml(x.country, x.cnt, maxD, '#3b82f6', 'w-[104px]')).join('') :
                '<p class="text-[11px] text-slate-400 italic">No destinations waiting.</p>';
        }

        applyIncomingData(<?= json_encode($incoming, EVX_JSON) ?>);

        // ── No-reload filtering + queue refresh ──────────────────────
        let filterAbortController = null;
        let incomingAbortController = null;

        function currentFilterParams() {
            const params = new URLSearchParams();
            ['month', 'province', 'job', 'country'].forEach(k => {
                const v = document.getElementById('filter-' + k)?.value || '';
                if (v) params.set(k, v);
            });
            return params;
        }

        // Open-queue refresh (same file, ?ajax=incoming)
        window.fetchIncoming = function fetchIncoming() {
            const params = currentFilterParams();
            params.set('ajax', 'incoming');

            if (incomingAbortController) incomingAbortController.abort();
            incomingAbortController = new AbortController();

            fetch('index.php?' + params.toString(), {
                    signal: incomingAbortController.signal,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(res => {
                    if (!res.ok || !(res.headers.get('content-type') || '').includes('application/json')) {
                        throw new Error('Unexpected response (session expired?)');
                    }
                    return res.json();
                })
                .then(applyIncomingData)
                .catch(err => {
                    if (err.name !== 'AbortError') console.error('Open queue refresh failed:', err);
                });
        };

        // No-reload filtering: re-renders every section in place.
        // Exposed on window so the filter-pills script above can call it.
        window.fetchAndApplyFilters = function fetchAndApplyFilters() {
            if (filterAbortController) filterAbortController.abort();
            filterAbortController = new AbortController();

            fetch('ajax-dashboard-data.php?' + currentFilterParams().toString(), {
                    signal: filterAbortController.signal
                })
                .then(res => res.json())
                .then(data => {
                    if (data.error) return;
                    applyDashboardData(data);
                })
                .catch(err => {
                    if (err.name !== 'AbortError') console.error('Dashboard filter fetch failed:', err);
                });

            window.fetchIncoming();
        };

        // Keep the queue fresh while the tab is visible
        setInterval(() => {
            if (document.visibilityState === 'visible') window.fetchIncoming();
        }, 60000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') window.fetchIncoming();
        });

        document.querySelector('.theme-toggle')?.addEventListener('click', () => {
            setTimeout(() => {
                Chart.defaults.color = isDarkMode() ? '#94a3b8' : '#64748b';
                chartGridColor = isDarkMode() ? '#2d3748' : '#f1f5f9';
                chartRingColor = isDarkMode() ? '#1e293b' : '#fff';

                [statusChartInstance, countryChartInstance, evalTrendChartInstance].forEach(chart => {
                    if (!chart) return;
                    if (chart.options.scales?.y?.grid) chart.options.scales.y.grid.color = chartGridColor;
                    if (chart.data.datasets[0]?.borderColor !== undefined) chart.data.datasets[0].borderColor = chartRingColor;
                    chart.update();
                });
            }, 50);
        });
    </script>

    <script type="module" src="../js/main.js"></script>
    <script>
        (function() {
            const DEFAULT_VISIBLE_ITEMS = 5;

            document.querySelectorAll('[data-collapsible]').forEach(function(section) {
                const list = section.querySelector('[data-collapse-list]');
                const btn = section.querySelector('[data-collapse-toggle]');
                if (!list || !btn) return;

                const visibleItems = parseInt(section.dataset.visibleItems, 10) || DEFAULT_VISIBLE_ITEMS;
                const items = Array.from(list.children);
                const label = btn.querySelector('[data-collapse-label]');
                const icon = btn.querySelector('[data-collapse-icon]');
                let expanded = false; // state local to this section only

                if (items.length <= visibleItems) return; // nothing to collapse; button stays hidden

                btn.classList.remove('hidden');
                btn.classList.add('flex');

                function collapsedHeight() {
                    let h = 0;
                    for (let i = 0; i < visibleItems; i++) h += items[i].offsetHeight;
                    return h;
                }

                function apply() {
                    if (expanded) {
                        list.style.maxHeight = 'none';
                        list.style.overflowY = 'visible';
                        label.textContent = 'Show Less';
                    } else {
                        list.style.maxHeight = collapsedHeight() + 'px';
                        list.style.overflowY = 'auto';
                        list.scrollTop = 0;
                        label.textContent = 'Show All (' + items.length + ')';
                    }
                    icon.style.transform = expanded ? 'rotate(180deg)' : '';
                    btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                }

                btn.addEventListener('click', function() {
                    expanded = !expanded;
                    apply();
                });

                window.addEventListener('resize', function() {
                    if (!expanded) apply();
                });
                window.addEventListener('load', function() {
                    if (!expanded) apply();
                });

                apply();
            });
        })();
    </script>
    <script>
        window.DMW_USER_EMAIL = <?= json_encode($evaluator_email, EVX_JSON) ?>;
        window.DMW_NOTIF_API = '../api/poll_notifications.php';
        window.DMW_APP_VIEW_URL = 'view-application.php?id=';
    </script>
    <script src="../js/live-notifications.js"></script>
    <?php include '../includes/login_toast.php'; ?>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
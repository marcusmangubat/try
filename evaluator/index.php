<?php
// evaluator/index.php – DMW Evaluator Dashboard
session_start();
require_once '../db.php';
require_once '../auth.php';
require_once '../includes/app-helpers.php';
date_default_timezone_set('Asia/Manila');
require_once '../includes/dashboard-data.php';

// Require evaluator login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

releaseResubmissionsToEvaluator($database);

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

// Filtering is AJAX-driven (no page reload) — these only pre-select
// dropdown options on first paint; they always start empty.
$filter_month    = '';
$filter_province = '';
$filter_job      = '';
$filter_country  = '';

// Initial page load, no filters applied yet.
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

// Open-queue + personal workload analytics
$incoming  = evx_incoming_data($database, evx_clean_filters([]));
$workload  = evx_workload_data($database, (int)($evaluator_id ?? 0));
$attention = evx_attention_data($database, (int)($evaluator_id ?? 0));
$activity  = evx_activity_data($database, $evaluator_email);

// Turnaround extremes (fastest / slowest) from evaluations + assignments
$turn_extra = ['fastest' => null, 'slowest' => null, 'total' => 0];
if ($evaluator_id) {
    $stmt_tx = $database->prepare("
        SELECT MIN(t.h) AS fastest, MAX(t.h) AS slowest, COUNT(*) AS total
        FROM (
            SELECT TIMESTAMPDIFF(
                       MINUTE,
                       (SELECT MAX(asg.assigned_at)
                        FROM assignments asg
                        WHERE asg.application_id = e.application_id
                          AND asg.evaluator_id   = e.evaluator_id
                          AND asg.assigned_at   <= e.evaluated_at),
                       e.evaluated_at
                   ) / 60 AS h
            FROM evaluations e
            WHERE e.evaluator_id = ?
              AND e.decision IN ('approved','disapproved','for_compliance')
        ) t
        WHERE t.h IS NOT NULL
    ");
    $stmt_tx->bind_param("i", $evaluator_id);
    $stmt_tx->execute();
    $tx = $stmt_tx->get_result()->fetch_assoc();
    $stmt_tx->close();
    if ($tx && (int)$tx['total'] > 0) {
        $turn_extra = [
            'fastest' => (float)$tx['fastest'],
            'slowest' => (float)$tx['slowest'],
            'total'   => (int)$tx['total'],
        ];
    }
}
$turn_mix_total = $turnaround_by_decision['approved']['cnt']
    + $turnaround_by_decision['disapproved']['cnt']
    + $turnaround_by_decision['for_compliance']['cnt'];

// Filter dropdown sources — scoped ONLY to job types/destinations/provinces
// this evaluator actually has applications for (via current_evaluator_id).
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
    // Province filter is restricted to CALABARZON (Region IV-A) only.
    // The fixed list below is the sole source, so no outside province can leak in.
}

// CALABARZON's 5 fixed provinces only (Regional Office IV-A).
$evaluator_provinces = ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'];

// Active assignment count — used by the floating dock's "Tasks" badge.
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

// Leave / reassignment status for the dashboard banner
$active_leave    = $evaluator_id ? getActiveLeave($database, (int) $evaluator_id) : null;
$pending_request = $evaluator_id ? getPendingReassignmentRequest($database, (int) $evaluator_id) : null;

// Greeting
$hour       = (int)date('G');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($evaluator_name))[0] ?: $evaluator_name;

// Avatar initials (also read by avatar-menu.php)
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

/* ───────────── View helpers (UI only) ───────────── */

// Status pill → Tailwind utility classes
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

// Admin-style KPI tile (.kpi-card from dashboard.css)
function kpi_card(
    string $label,
    string $value,
    string $sub,
    string $icon,
    string $tone,
    string $valueId = '',
    string $subId = '',
    string $href = '',
    string $cardId = '',
    string $delay = 'd1'
): string {
    $c     = ['blue' => 'c-blue', 'emerald' => 'c-green', 'indigo' => 'c-indigo', 'amber' => 'c-amber'][$tone] ?? 'c-blue';
    $tag   = $href !== '' ? 'a' : 'div';
    $attrs = ($href !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES) . '" style="text-decoration:none;color:inherit"' : '')
        . ($cardId !== '' ? ' id="' . htmlspecialchars($cardId, ENT_QUOTES) . '"' : '');
    $vId   = $valueId !== '' ? ' id="' . htmlspecialchars($valueId, ENT_QUOTES) . '"' : '';
    $sId   = $subId   !== '' ? ' id="' . htmlspecialchars($subId, ENT_QUOTES) . '"' : '';

    return '<' . $tag . $attrs . ' class="kpi-card ' . $c . ' fade-in ' . $delay . '">'
        . '<div class="kpi-top">'
        . '<div class="kpi-label">' . htmlspecialchars($label) . '</div>'
        . '<div class="kpi-icon ' . $c . '"><i class="fas ' . htmlspecialchars($icon) . '"></i></div>'
        . '</div>'
        . '<div' . $vId . ' class="kpi-value ' . $c . '">' . htmlspecialchars($value) . '</div>'
        . '<div' . $sId . ' class="kpi-sub">' . htmlspecialchars($sub) . '</div>'
        . '</' . $tag . '>';
}

// Admin-style section header (.dash-section-head)
function evui_section_head(string $bar, string $title, string $sub): string
{
    return '<div class="dash-section-head">'
        . '<span class="dsh-bar" style="background:' . $bar . '"></span>'
        . '<h3>' . htmlspecialchars($title) . '</h3>'
        . '<span class="dsh-sub">' . htmlspecialchars($sub) . '</span>'
        . '</div>';
}

// Admin-style card header (.card-head / .card-title / .ctdot)
function evui_card_head(string $dot, string $title, string $right = ''): string
{
    return '<div class="card-head"><div class="card-title"><span class="ctdot" style="background:' . $dot . '"></span>'
        . htmlspecialchars($title) . '</div>' . $right . '</div>';
}

function evui_link(string $href, string $label): string
{
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="card-link">' . htmlspecialchars($label)
        . ' <i class="fas fa-arrow-right" style="font-size:9px"></i></a>';
}

function evui_caption(string $text): string
{
    return '<span class="text-[10px] font-bold uppercase tracking-wide" style="color:var(--text3)">' . htmlspecialchars($text) . '</span>';
}

function evui_empty(string $icon, string $title, string $sub = ''): string
{
    return '<div class="py-10 px-4 text-center">'
        . '<i class="fas ' . htmlspecialchars($icon) . ' text-3xl text-slate-200 block mb-2.5"></i>'
        . '<p class="text-[12.5px] font-semibold text-slate-400">' . htmlspecialchars($title) . '</p>'
        . ($sub !== '' ? '<span class="text-[11px] text-slate-400/80">' . htmlspecialchars($sub) . '</span>' : '')
        . '</div>';
}

function evui_quick(string $href, string $icon, string $tone, string $label): string
{
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="group flex items-center gap-2.5 px-3 py-2.5 rounded-2xl no-underline font-bold text-[11.5px] sm:text-[12px] bg-[var(--surface)] border border-[var(--border)] text-[var(--text2)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md active:scale-[.98]">'
        . '<span class="w-8 h-8 rounded-[10px] flex items-center justify-center text-sm shrink-0 ' . $tone . '"><i class="fas ' . htmlspecialchars($icon) . '"></i></span>'
        . '<span class="truncate">' . htmlspecialchars($label) . '</span></a>';
}

$collapseBtn = '<button type="button" data-collapse-toggle aria-expanded="false" class="hidden w-full mt-2 pt-2 border-t border-slate-100 text-[11px] font-bold text-blue-500 hover:text-blue-600 bg-transparent cursor-pointer items-center justify-center gap-1.5">'
    . '<span data-collapse-label>Show All</span>'
    . '<i class="fas fa-chevron-down text-[9px] transition-transform" data-collapse-icon></i></button>';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluator Dashboard – DMW Regional Office IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"DM Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>

    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* ── Admin-matched layout: sections, grids, KPI strip ── */
        .dash-section {
            margin-top: 22px;
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

        .grid-overview {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 14px;
            align-items: stretch;
        }

        .grid-pair {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            align-items: stretch;
        }

        .grid-overview>*,
        .grid-pair>* {
            min-width: 0;
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

        @media (max-width: 1180px) {
            .grid-overview {
                grid-template-columns: 1.6fr 1fr;
            }

            .grid-overview .dest-col {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 860px) {

            .grid-overview,
            .grid-pair {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .fade-in {
                animation: none !important;
            }
        }

        /* ── Dark-mode overrides for Tailwind utilities used inside JS-rendered lists ── */
        [data-theme="dark"] .bg-white {
            background-color: #1e293b !important;
        }

        [data-theme="dark"] .border-slate-200,
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

        [data-theme="dark"] .text-slate-400,
        [data-theme="dark"] .text-slate-500,
        [data-theme="dark"] .text-slate-600 {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .text-slate-200 {
            color: #334155 !important;
        }

        [data-theme="dark"] .text-slate-700 {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .text-slate-800 {
            color: #f1f5f9 !important;
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

        [data-theme="dark"] .text-blue-500,
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

        [data-theme="dark"] .text-amber-700,
        [data-theme="dark"] .text-amber-800 {
            color: #fcd34d !important;
        }

        [data-theme="dark"] .text-red-800 {
            color: #fca5a5 !important;
        }

        [data-theme="dark"] .hover\:shadow-md:hover {
            box-shadow: none !important;
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

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; EVALUATOR PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
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

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></h2>
                <p>(<span id="available-count-subbar"><?= number_format($available_count) ?></span>) Pending Applications</p>
            </div>
        </div>

        <!-- Options come ONLY from job types/destinations/provinces this evaluator is assigned to. -->
        <div class="filter-scroll">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select id="filter-month" autocomplete="off">
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

    <!-- MAIN -->
    <div class="main">

        <?php if ($active_leave || $pending_request): ?>
            <a href="settings.php#availability" class="flex items-start sm:items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 mb-3 no-underline fade-in">
                <i class="fas <?= $active_leave ? 'fa-user-clock' : 'fa-hourglass-half' ?> text-amber-600 mt-0.5 sm:mt-0"></i>
                <span class="flex-1 min-w-0 text-[12.5px] text-slate-700">
                    <?php if ($active_leave): ?>
                        <b>You are marked unavailable</b>
                        since <?= date('M d, Y', strtotime($active_leave['start_at'])) ?>
                        <?= $active_leave['end_at'] ? ' until ' . date('M d, Y', strtotime($active_leave['end_at'])) : '' ?>.
                        You will not receive new applications.
                    <?php else: ?>
                        <b>Reassignment request pending</b> admin approval (<?= (int) $pending_request['item_count'] ?> application(s)).
                    <?php endif; ?>
                </span>
                <span class="text-[11px] font-bold text-blue-500 shrink-0">Manage <i class="fas fa-arrow-right text-[9px]"></i></span>
            </a>
        <?php endif; ?>

        <!-- KPI strip (single horizontal line, same as admin) -->
        <div class="kpi-strip">
            <?= kpi_card('Total Applications', number_format($stats['total']), 'All applications you review', 'fa-file-lines', 'blue', 'kpi-total', '', '', '', 'd1') ?>
            <?= kpi_card('Open for Evaluation', number_format($available_count), 'Unclaimed — first to open it gets it', 'fa-inbox', 'emerald', 'available-count', '', 'applications.php?status=available', 'available-kpi-card', 'd2') ?>
            <?= kpi_card('Under Evaluation', number_format($stats['under_evaluation']), 'Currently being reviewed by you', 'fa-magnifying-glass', 'indigo', 'kpi-under-evaluation', '', 'applications.php?status=under_evaluation', '', 'd3') ?>
            <?= kpi_card('Approved', number_format($stats['approved']), 'Approved applications you evaluated', 'fa-circle-check', 'emerald', 'kpi-approved', '', '', '', 'd3') ?>
            <?= kpi_card('My Active Tasks', number_format($workload['active_tasks']), $workload['with_admin'] . ' awaiting applicant/admin', 'fa-list-check', 'indigo', '', '', 'applications.php?status=under_evaluation', '', 'd4') ?>
            <?= kpi_card('Decided This Week', number_format($workload['decided_week']), $workload['decided_today'] . ' today · last 7 days', 'fa-calendar-check', 'blue', '', '', '', '', 'd4') ?>
            <?= kpi_card(
                'Approval Rate',
                $workload['approval_rate'] !== null ? $workload['approval_rate'] . '%' : '—',
                $workload['approved'] . ' approved · ' . $workload['disapproved'] . ' disapproved',
                'fa-chart-pie',
                'emerald',
                '',
                '',
                '',
                '',
                'd5'
            ) ?>
            <?= kpi_card('Oldest in Queue', $incoming['oldest_display'], 'Avg wait ' . $incoming['avg_display'] . ' · open queue', 'fa-hourglass-half', 'amber', 'kpi-oldest', 'kpi-avgwait', '', '', 'd5') ?>
        </div>

        <!-- Quick access -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3 mt-3 fade-in d5">
            <?= evui_quick('applications.php', 'fa-list', 'bg-blue-50 text-blue-500', 'My Assignments') ?>
            <?= evui_quick('applications.php?status=for_compliance', 'fa-triangle-exclamation', 'bg-amber-50 text-amber-600', 'For Compliance') ?>
            <?= evui_quick('reports.php', 'fa-chart-bar', 'bg-emerald-50 text-emerald-600', 'Reports') ?>
            <?= evui_quick('settings.php#availability', 'fa-people-arrows', 'bg-indigo-50 text-indigo-600', 'Request Reassignment') ?>
        </div>

        <!-- SECTION 1: Queue, Assignments & Status -->
        <div class="dash-section fade-in d4">
            <?= evui_section_head('var(--blue-lt,#1d4ed8)', 'Queue, Assignments & Status', 'Open applications, your assigned work, current status and recent activity') ?>

            <div class="grid-overview">

                <!-- Open for Evaluation (wide) -->
                <div class="card flex flex-col">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:#10b981"></span>
                            Open for Evaluation
                            <span id="incoming-count" class="ml-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800"><?= (int)$incoming['total'] ?></span>
                        </div>
                        <?= evui_link('applications.php?status=available', 'View all') ?>
                    </div>
                    <p class="text-[10.5px] text-slate-400 font-medium -mt-1 mb-2">Oldest first · opening an application assigns it to you</p>
                    <div id="incoming-list" class="flex flex-col flex-1 min-h-0 max-h-[520px] overflow-y-auto pr-0.5 [scrollbar-width:thin]"></div>
                    <p id="incoming-footer" class="text-[10.5px] text-slate-400 font-medium pt-2 mt-1 border-t border-slate-100"></p>
                </div>

                <!-- Status + Queue aging -->
                <div class="stack-col">
                    <div class="card">
                        <?= evui_card_head('var(--indigo)', 'Application Status') ?>
                        <div id="status-bars" class="flex flex-col gap-3 mb-4"></div>
                        <div class="flex items-center gap-4 pt-4 border-t border-slate-100">
                            <div class="shrink-0 w-[100px]"><canvas id="statusChart" width="120" height="120"></canvas></div>
                            <div id="status-legend" class="flex-1 min-w-0"></div>
                        </div>
                    </div>

                    <div class="card">
                        <?= evui_card_head('var(--amber)', 'Open Queue Aging', evui_caption('Since submission')) ?>
                        <div id="aging-bars" class="flex flex-col gap-3 mb-4"></div>
                        <div class="pt-4 border-t border-slate-100">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mb-2">Top destinations waiting</p>
                            <div id="dest-bars" class="flex flex-col gap-2.5"></div>
                        </div>
                    </div>
                </div>

                <!-- Assigned + Activity -->
                <div class="stack-col dest-col">
                    <div class="card">
                        <?= evui_card_head('#3b82f6', 'My Assigned Applications', evui_link('applications.php', 'View all')) ?>
                        <div id="my-applications-list" class="flex flex-col max-h-[300px] overflow-y-auto pr-0.5 [scrollbar-width:thin]">
                            <?php if (empty($my_applications)): ?>
                                <?= evui_empty('fa-inbox', 'No applications assigned yet', 'Applications assigned to you will appear here once processed.') ?>
                            <?php else: ?>
                                <?php foreach ($my_applications as $app): ?>
                                    <?php
                                    $status     = strtolower($app['status'] ?? 'unknown');
                                    $statusText = str_replace('_', ' ', ucwords($status));
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
                                            <span class="inline-block text-[9.5px] font-bold tracking-wider uppercase px-2.5 py-[3px] rounded-full <?= pill_classes($status) ?>">
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

                    <div class="card" data-collapsible>
                        <?= evui_card_head('#6366f1', 'Recent Activity', evui_caption('Your last actions')) ?>
                        <?php if (empty($activity)): ?>
                            <?= evui_empty('fa-clock-rotate-left', 'No activity recorded yet') ?>
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
                            <?= $collapseBtn ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- SECTION 2: Document Processing & Turnaround -->
        <div class="dash-section fade-in d5">
            <?= evui_section_head('var(--amber,#d97706)', 'Document Processing & Turnaround', 'Document completeness on your active applications and decision speed') ?>

            <div class="grid-pair">

                <div class="card" data-collapsible data-visible-items="4">
                    <?= evui_card_head('var(--amber)', 'Document Completion', evui_caption($attention['avg_completion'] !== null ? 'Avg ' . (int)$attention['avg_completion'] . '%' : 'My active applications')) ?>
                    <?php if (empty($attention['items'])): ?>
                        <?= evui_empty('fa-folder-open', 'No active applications to check') ?>
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
                        <?= $collapseBtn ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <?= evui_card_head('#3b82f6', 'Avg. Turnaround Time') ?>
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

                    <!-- Turnaround extremes + decision mix -->
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <div class="grid grid-cols-3 gap-2 mb-4">
                            <div class="rounded-xl bg-slate-50 px-2 py-2.5 text-center">
                                <div class="text-[15px] font-extrabold text-emerald-600">
                                    <?= $turn_extra['fastest'] !== null ? format_turnaround($turn_extra['fastest']) : '—' ?>
                                </div>
                                <div class="text-[9.5px] font-bold text-slate-400 uppercase tracking-wide mt-0.5">Fastest</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 px-2 py-2.5 text-center">
                                <div class="text-[15px] font-extrabold text-amber-600">
                                    <?= $turn_extra['slowest'] !== null ? format_turnaround($turn_extra['slowest']) : '—' ?>
                                </div>
                                <div class="text-[9.5px] font-bold text-slate-400 uppercase tracking-wide mt-0.5">Slowest</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 px-2 py-2.5 text-center">
                                <div class="text-[15px] font-extrabold text-blue-600"><?= number_format($turn_extra['total']) ?></div>
                                <div class="text-[9.5px] font-bold text-slate-400 uppercase tracking-wide mt-0.5">Decisions</div>
                            </div>
                        </div>

                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mb-2">Decision mix</p>
                        <?php if ($turn_mix_total > 0): ?>
                            <div class="flex h-[10px] rounded-full overflow-hidden bg-slate-100 mb-2.5">
                                <?php foreach ($turn_rows as $key => $meta):
                                    $c = (int)$turnaround_by_decision[$key]['cnt'];
                                    if ($c === 0) continue; ?>
                                    <div style="width:<?= round($c / $turn_mix_total * 100, 1) ?>%;background:<?= $meta['color'] ?>"
                                        title="<?= htmlspecialchars($meta['label']) ?>: <?= $c ?>"></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                <?php foreach ($turn_rows as $key => $meta):
                                    $c = (int)$turnaround_by_decision[$key]['cnt'];
                                    if ($c === 0) continue; ?>
                                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-600">
                                        <span class="w-[8px] h-[8px] rounded-full" style="background:<?= $meta['color'] ?>"></span>
                                        <?= htmlspecialchars($meta['label']) ?>
                                        <b class="text-slate-800"><?= $c ?></b>
                                        <span class="text-slate-400">(<?= round($c / $turn_mix_total * 100) ?>%)</span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-[11px] text-slate-400 italic">No decisions recorded yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- SECTION 3: Evaluation Performance & Demand -->
        <div class="dash-section fade-in d6">
            <?= evui_section_head('#6366f1', 'Evaluation Performance & Demand', 'Your decisions over the last 6 months and where applications are headed') ?>

            <div class="grid-pair">
                <div class="card">
                    <?= evui_card_head('#10b981', 'Your Evaluation Trend', evui_caption('Last 6 Months')) ?>
                    <div style="position:relative;height:260px"><canvas id="evalTrendChart"></canvas></div>
                </div>
                <div class="card">
                    <?= evui_card_head('#60a5fa', 'Applications by Country') ?>
                    <div style="position:relative;height:260px"><canvas id="countryChart"></canvas></div>
                </div>
            </div>
        </div>

    </div><!-- /main -->

    <!-- FLOATING DOCK -->
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
        /* Live date + time */
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
        setInterval(updateLiveDateTime, 1000);
    </script>

    <!-- Filter pills (same dropdown panel as the admin dashboard) -->
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
            }

            ['filter-month', 'filter-province', 'filter-job', 'filter-country'].forEach(id => {
                const el = document.getElementById(id);
                enhancePillSelect(el);
                if (el) el.addEventListener('change', () => window.fetchAndApplyFilters());
            });
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

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, ch => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [ch]));
        }

        // "Open for Evaluation": confirm before claiming
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

        function emptyState(icon, title, sub) {
            return `<div class="py-10 px-4 text-center">
        <i class="fas ${icon} text-3xl text-slate-200 block mb-2.5"></i>
        <p class="text-[12.5px] font-semibold text-slate-400">${title}</p>
        <span class="text-[11px] text-slate-400/80">${sub}</span></div>`;
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
                <span class="text-[12px] font-bold text-slate-700 w-[96px] shrink-0 truncate">${label}</span>
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
            <div class="flex items-center gap-2 py-[6px] border-b border-slate-100 last:border-0">
                <span class="w-[10px] h-[10px] rounded-full shrink-0" style="background:${statusColors[i]}"></span>
                <span class="text-[12px] font-semibold text-slate-700 flex-1 truncate">${label}</span>
                <span class="text-[12px] font-extrabold text-slate-800">${pct}%</span>
            </div>`;
            });
            if (total === 0) legendEl.innerHTML = '<p class="text-[11px] text-slate-400 italic">No data yet.</p>';
        }

        // Shared Chart.js options for both bar charts
        function barOptions(stacked) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked,
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
                        stacked,
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
            };
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
                    options: barOptions(false)
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
                    options: barOptions(true)
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
                list.innerHTML = emptyState('fa-filter-circle-xmark', 'No assigned applications match this filter', 'Try a different month, province, job type, or destination.');
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
            <a href="view-application.php?id=${Number(app.id)}" class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
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

        // ── "Open for Evaluation" queue + aging ──
        const agingLabels = ['Under 24 hours', '1–3 days', '3–7 days', '7+ days'];
        const agingColors = ['#059669', '#3b82f6', '#d97706', '#dc2626'];
        const tierClasses = {
            new: 'bg-emerald-100 text-emerald-800',
            normal: 'bg-slate-100 text-slate-600',
            aging: 'bg-orange-50 text-amber-800',
            stale: 'bg-red-100 text-red-800'
        };

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
            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10.5px] text-slate-400 font-medium mt-0.5">${prov}${docs}${it.closing_soon ? `<span class="text-amber-700 font-bold"><i class="fas fa-clock text-[9px]"></i> ${it.days_to_deadline < 0 ? 'Deadline passed' : (it.days_to_deadline === 0 ? 'Closes today' : 'Closes in ' + it.days_to_deadline + 'd')}</span>` : ''}</div>
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
                d.buckets.map((v, i) => barRowHtml(agingLabels[i], v, maxB, agingColors[i], 'w-[96px]')).join('') :
                '<p class="text-[11px] text-slate-400 italic">The open queue is empty.</p>';

            const maxD = Math.max(...d.destinations.map(x => x.cnt), 1);
            document.getElementById('dest-bars').innerHTML = d.destinations.length ?
                d.destinations.map(x => barRowHtml(x.country, x.cnt, maxD, '#3b82f6', 'w-[96px]')).join('') :
                '<p class="text-[11px] text-slate-400 italic">No destinations waiting.</p>';
        }

        applyIncomingData(<?= json_encode($incoming, EVX_JSON) ?>);

        // ── No-reload filtering + queue refresh ──
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

    <!-- Collapsible lists (Recent Activity / Document Completion) -->
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
                let expanded = false;

                if (items.length <= visibleItems) return;

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
<?php
// evaluator/applications.php – List applications for evaluation
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';
require_once '../includes/handover-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

releaseResubmissionsToEvaluator($database);

$evaluator_email = $_SESSION['user_email'];

// Get evaluator eid
$evaluator_name = 'Evaluator';
$evaluator_id   = null;
$stmt_ev = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();
if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

$evaluator_on_leave = $evaluator_id ? !isEvaluatorAvailable($database, $evaluator_id) : false;
// Flash message carried over from view-application.php after a successful
// evaluation. Redirecting back to view-application.php isn't reliable once
// the decision is final (approved/disapproved) or handed to Admin
// (for_compliance) — the evaluator no longer owns the case on reload, so
// the success alert is shown here instead.
$flash_success = null;
if (isset($_GET['evaluated'])) {
    $decisionLabel = match ($_GET['decision'] ?? '') {
        'approved'       => 'Approved',
        'disapproved'    => 'Disapproved',
        'for_compliance' => 'For Compliance',
        default          => null,
    };
    $flash_success = $decisionLabel
        ? "Evaluation recorded — application marked as {$decisionLabel}."
        : 'Evaluation recorded successfully.';
}

// Filter by status ('available' = the shared queue every evaluator can see
// and pick up, shown oldest-waiting-first; 'assign' includes legacy
// assigned)
$valid_statuses = ['available', 'assign', 'assigned', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$status_filter  = $_GET['status'] ?? '';
$status_filter  = in_array($status_filter, $valid_statuses, true) ? $status_filter : '';

// Search box — matches applicant name, email, position, or employer.
$search_filter = trim($_GET['q'] ?? '');

//  Subbar filters: Month & Country 
// Month filters applications by their submission date (YYYY-MM).
$month_filter = trim($_GET['month'] ?? '');
$month_filter = preg_match('/^\d{4}-\d{2}$/', $month_filter) ? $month_filter : '';
// Period: 'today' or 'week' (Mon–Sun), based on submission date.
$period_filter = $_GET['period'] ?? '';
$period_filter = in_array($period_filter, ['today', 'week'], true) ? $period_filter : '';

// Country filters by the RSF job order's worksite country.
$country_filter = trim($_GET['country'] ?? '');

/**
 * Builds a reusable " AND ..." WHERE fragment (+ matching bind types/params)
 * for the Month / Country subbar filters. Assumes applications is aliased
 * `a` and rsf_job_orders is aliased/joined as `r` in the calling query.
 */
function buildEvalFilters(string $month, string $country, string $period = ''): array
{
    $where  = '';
    $types  = '';
    $params = [];

    if ($month !== '') {
        $start = $month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $where   .= " AND a.submitted_at BETWEEN ? AND ?";
        $types   .= 'ss';
        $params[] = $start;
        $params[] = $end;
    }
    if ($country !== '') {
        $where   .= " AND r.worksite_country = ?";
        $types   .= 's';
        $params[] = $country;
    }
    if ($period === 'today') {
        $where .= " AND DATE(a.submitted_at) = CURDATE()";
    } elseif ($period === 'week') {
        $where .= " AND YEARWEEK(a.submitted_at, 1) = YEARWEEK(CURDATE(), 1)";
    }

    return [$where, $types, $params];
}

/** Builds the "base" URL (status + month + country, no search term) used
 *  for the stat/filter tile links — search term is appended client-side. */
function build_tile_base(?string $status, string $month, string $country, string $period = ''): string
{
    $params = [];
    if ($status)         $params['status']  = $status;
    if ($month !== '')   $params['month']   = $month;
    if ($country !== '') $params['country'] = $country;
    if ($period !== '')  $params['period']  = $period;
    return 'applications.php' . ($params ? '?' . http_build_query($params) : '');
}

[$filterWhere, $filterTypes, $filterParams] = buildEvalFilters($month_filter, $country_filter, $period_filter);

// Distinct countries (from job orders that actually have applications) for
// the Country subbar dropdown.
$countries = [];
$countries_res = $database->query("
    SELECT DISTINCT r.worksite_country
    FROM rsf_job_orders r
    JOIN applications a ON a.rsf_id = r.id
    WHERE r.worksite_country IS NOT NULL AND r.worksite_country <> ''
    ORDER BY r.worksite_country ASC
");
if ($countries_res) {
    while ($row = $countries_res->fetch_assoc()) {
        $countries[] = $row['worksite_country'];
    }
}
// Pagination
$show_all = isset($_GET['all']) && $_GET['all'] === '1';
$per_page = 10;
$page     = max(1, (int) ($_GET['page'] ?? 1));

// Build query.
// 'available' is the one shared view: every evaluator sees the same
// unclaimed queue so any of them can pick up and evaluate an application.
// Every other tab is scoped strictly to the logged-in evaluator — an
// evaluator can only see/manage applications currently assigned to them.
$applications    = [];
$total_filtered  = 0;
$total_pages     = 1;

if ($status_filter === 'available' && $evaluator_on_leave) {
    // On leave: the shared queue is hidden so nothing can be picked up.
    $applications = [];
} elseif ($status_filter === 'available') {
    // Shared queue, visible to all evaluators, oldest-waiting first —
    // first passed into the queue, first shown.
    $sql = "
                SELECT a.id, a.status, a.updated_at,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name, r.deadline,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email,
               c.profile_picture AS applicant_picture
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients   c ON a.client_id = c.cid
        WHERE a.handling_party IS NULL
          AND a.status IN ('pending','assign','assigned','resubmitted') {$filterWhere}
    ";
    $countSql = "
        SELECT COUNT(*) AS cnt
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients   c ON a.client_id = c.cid
        WHERE a.handling_party IS NULL
          AND a.status IN ('pending','assign','assigned','resubmitted') {$filterWhere}
    ";
    $params = $filterParams;
    $types  = $filterTypes;

    if ($search_filter !== '') {
        $searchCond = " AND (
            CONCAT(c.first_name, ' ', c.last_name) LIKE ?
            OR c.email LIKE ?
            OR r.position_title LIKE ?
            OR r.employer_name LIKE ?
            OR r.rsf_number LIKE ?
            OR r.worksite_country LIKE ?
        )";
        $sql      .= $searchCond;
        $countSql .= $searchCond;
        $like = '%' . $search_filter . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= "ssssss";
    }

    $stmt_count = $database->prepare($countSql);
    if ($params) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_filtered = (int) ($stmt_count->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt_count->close();

    if ($show_all) {
        $total_pages = 1;
        $page = 1;
        $sql .= " ORDER BY " . sql_deadline_priority('r.deadline') . ", a.updated_at ASC";
    } else {
        $total_pages = max(1, (int) ceil($total_filtered / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql .= " ORDER BY " . sql_deadline_priority('r.deadline') . ", a.updated_at ASC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;
        $types   .= "ii";
    }

    $stmt = $database->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($evaluator_id) {
    // My cases: anything on record as mine, including resubmissions,
    // compliance, and finalized decisions. Status does not hide a case.
    $sql = "
                SELECT a.id, a.status, a.updated_at, a.handling_party,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name, r.deadline,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email,
               c.profile_picture AS applicant_picture
        FROM applications a
        JOIN rsf_job_orders  r ON a.rsf_id           = r.id
        LEFT JOIN clients    c ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ? {$filterWhere}
    ";
    $countSql = "
        SELECT COUNT(*) AS cnt
        FROM applications a
        JOIN rsf_job_orders  r ON a.rsf_id           = r.id
        LEFT JOIN clients    c ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ? {$filterWhere}
    ";
    $params = array_merge([$evaluator_id], $filterParams);
    $types  = "i" . $filterTypes;

    if ($status_filter === 'assign' || $status_filter === 'assigned') {
        $sql      .= " AND a.status IN ('assign','assigned')";
        $countSql .= " AND a.status IN ('assign','assigned')";
    } elseif ($status_filter) {
        $sql      .= " AND a.status = ?";
        $countSql .= " AND a.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    if ($search_filter !== '') {
        $searchCond = " AND (
            CONCAT(c.first_name, ' ', c.last_name) LIKE ?
            OR c.email LIKE ?
            OR r.position_title LIKE ?
            OR r.employer_name LIKE ?
            OR r.rsf_number LIKE ?
            OR r.worksite_country LIKE ?
        )";
        $sql      .= $searchCond;
        $countSql .= $searchCond;
        $like = '%' . $search_filter . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= "ssssss";
    }

    $stmt_count = $database->prepare($countSql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_filtered = (int) ($stmt_count->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt_count->close();

    if ($show_all) {
        $total_pages = 1;
        $page = 1;
        $sql .= " ORDER BY " . sql_deadline_priority('r.deadline', "a.status NOT IN ('approved','disapproved','draft')") . ", a.updated_at DESC";
    } else {
        $total_pages = max(1, (int) ceil($total_filtered / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql .= " ORDER BY " . sql_deadline_priority('r.deadline', "a.status NOT IN ('approved','disapproved','draft')") . ", a.updated_at DESC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;
        $types   .= "ii";
    }

    $stmt = $database->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Stats for the filter tiles.
// 'available' reflects the shared queue (same for every evaluator).
// Every other bucket is scoped to the logged-in evaluator's own cases so
// the counts always match what that evaluator can actually see in the list.
$stats = ['available' => 0, 'assign' => 0, 'under_evaluation' => 0, 'for_compliance' => 0, 'approved' => 0, 'disapproved' => 0, 'resubmitted' => 0];

$availStatsSql = "
    SELECT COUNT(*) AS cnt
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    WHERE a.handling_party IS NULL
      AND a.status IN ('pending','assign','assigned','resubmitted')
      {$filterWhere}
";
$stmt_avail = $database->prepare($availStatsSql);
if ($filterTypes !== '') {
    $stmt_avail->bind_param($filterTypes, ...$filterParams);
}
$stmt_avail->execute();
$stats['available'] = (int) ($stmt_avail->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt_avail->close();

if ($evaluator_id) {
    $mineStatsSql = "
        SELECT a.status, COUNT(*) as cnt
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.current_evaluator_id = ?
          {$filterWhere}
        GROUP BY a.status
    ";
    $mineStatsTypes  = "i" . $filterTypes;
    $mineStatsParams = array_merge([$evaluator_id], $filterParams);

    $stmt_stats = $database->prepare($mineStatsSql);
    $stmt_stats->bind_param($mineStatsTypes, ...$mineStatsParams);
    $stmt_stats->execute();
    $res = $stmt_stats->get_result();
    while ($row = $res->fetch_assoc()) {
        $s   = $row['status'];
        $cnt = (int) $row['cnt'];
        if ($s === 'assigned' || $s === 'assign') {
            $stats['assign'] += $cnt;
        } elseif (isset($stats[$s])) {
            $stats[$s] += $cnt;
        }
    }
    $stmt_stats->close();
}

if ($evaluator_on_leave) {
    $stats['available'] = 0;
}

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

// Job orders that still have unfinished applications, nearest deadline first.
$deadline_jobs = [];
$dl_res = $database->query("
    SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
           r.worksite_country, r.deadline,
           SUM(a.status IN ('pending','assign','assigned','resubmitted','under_evaluation','for_compliance')) AS open_cnt
    FROM rsf_job_orders r
    LEFT JOIN applications a ON a.rsf_id = r.id
    WHERE r.deadline IS NOT NULL
    GROUP BY r.id
    HAVING open_cnt > 0
    ORDER BY r.deadline ASC
");
if ($dl_res) {
    $deadline_jobs = $dl_res->fetch_all(MYSQLI_ASSOC);
}

$page_title = $status_filter
    ? ucwords(str_replace('_', ' ', $status_filter))
    : 'My Cases';

// Single source of truth for status colors/icons/labels — used for the
// tabs AND the status pill on every row, so a status is only ever defined
// once. 'dark' is the text color used on dark backgrounds.
$status_style = [
    'available' => ['bg' => '#d1fae5', 'fg' => '#065f46', 'dark' => '#6ee7b7', 'icon' => 'fa-inbox', 'label' => 'Open for Evaluation'],
    'pending'          => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'dark' => '#93c5fd', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'assign'           => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'dark' => '#93c5fd', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'assigned'         => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'dark' => '#93c5fd', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'under_evaluation' => ['bg' => '#e0e7ff', 'fg' => '#3730a3', 'dark' => '#a5b4fc', 'icon' => 'fa-magnifying-glass',     'label' => 'Under Evaluation'],
    'for_compliance'   => ['bg' => '#ffedd5', 'fg' => '#92400e', 'dark' => '#fbbf24', 'icon' => 'fa-triangle-exclamation', 'label' => 'For Compliance'],
    'approved'         => ['bg' => '#d1fae5', 'fg' => '#065f46', 'dark' => '#6ee7b7', 'icon' => 'fa-circle-check',         'label' => 'Approved'],
    'disapproved'      => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'dark' => '#fca5a5', 'icon' => 'fa-circle-xmark',         'label' => 'Disapproved'],
    'resubmitted'      => ['bg' => '#fef3c7', 'fg' => '#92400e', 'dark' => '#fcd34d', 'icon' => 'fa-rotate-right',         'label' => 'Resubmitted'],
    'default'          => ['bg' => '#f1f5f9', 'fg' => '#475569', 'dark' => '#cbd5e1', 'icon' => 'fa-circle',               'label' => 'Unknown'],
];

function status_color(string $key, array $map): array
{
    return $map[$key] ?? $map['default'];
}

/** Tailwind classes for a status pill (light = tinted pill, dark = plain colored text). */
function status_badge_cls(array $sc): string
{
    return "bg-[{$sc['bg']}] text-[{$sc['fg']}] [[data-theme=dark]_&]:bg-transparent [[data-theme=dark]_&]:px-0 [[data-theme=dark]_&]:text-[{$sc['dark']}]";
}

// Order + keys for the tab row (mirrors the original 7 filters)
$filter_keys = ['available', 'assign', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$total_count = array_sum($stats) - $stats['available'];

// Presentation-only: the four "needs attention" summary cards
$summary_cards = [
    ['key' => 'available',        'note' => 'Shared queue, ready to claim', 'color' => '#10b981'],
    ['key' => 'under_evaluation', 'note' => 'Your active cases',            'color' => '#6366f1'],
    ['key' => 'resubmitted',      'note' => 'Back in your queue',           'color' => '#f59e0b'],
    ['key' => 'for_compliance',   'note' => 'Waiting on the applicant',     'color' => '#fb923c'],
];
$action_label = ($status_filter === 'available') ? 'Claim' : 'Open';
$card_heading = $status_filter
    ? status_color($status_filter, $status_style)['label'] . ' Applications'
    : 'All Applications';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> – Evaluator Portal</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <!-- Shared design system (topbar / dock / CSS variables live here) -->
    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        [data-theme="dark"] .filter-pill,
        [data-theme="dark"] .filter-pill span,
        [data-theme="dark"] .filter-pill i,
        [data-theme="dark"] .filter-pill button {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .filter-pill button[aria-expanded="true"] {
            color: #fff !important;
        }

        [data-theme="dark"] .filter-pill,
        [data-theme="dark"] .filter-pill:hover,
        [data-theme="dark"] .filter-pill:focus-within,
        [data-theme="dark"] .filter-pill button,
        [data-theme="dark"] .filter-pill button:hover,
        [data-theme="dark"] .filter-pill button:focus,
        [data-theme="dark"] .filter-pill button:active {
            background: transparent !important;
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

<body class="bg-[var(--bg,#f4f6fb)] font-['DM_Sans',sans-serif]">
    <!-- TOPBAR (unchanged) -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="!w-[42px] !h-auto block bg-transparent !filter-none">
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

    <!-- PAGE HEADER — breadcrumb + title/count (left), Month & Country filters (right) -->
    <div class="subbar flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-3">
        <div class="min-w-0">
            <h2 id="page-heading-text" class="font-['DM_Serif_Display',serif] text-[18px] md:text-[22px] leading-tight text-[var(--navy)] truncate">
                <?= htmlspecialchars($page_title) ?>
            </h2>
            <p class="font-medium text-[var(--text3)] mt-0.5 text-xs" id="app-count-line">
                <?= $total_filtered ?> application<?= $total_filtered !== 1 ? 's' : '' ?> found<?= $status_filter ? ' · filtered by "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?><?= $search_filter !== '' ? ' · matching "' . htmlspecialchars($search_filter) . '"' : '' ?> · <?= $show_all ? 'Showing all' : 'Page ' . $page . ' of ' . $total_pages ?>
            </p>
        </div>
    </div>

    <main class="max-w-[1400px] mx-auto px-3 sm:px-4 pt-4 sm:pt-5 pb-[calc(var(--dock-h)_+_28px_+_env(safe-area-inset-bottom))]">

        <?php if ($evaluator_on_leave): ?>
            <div class="mb-4 flex items-center gap-2.5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-[12.5px] font-medium text-amber-800 [[data-theme=dark]_&]:border-amber-500/45 [[data-theme=dark]_&]:bg-amber-500/10 [[data-theme=dark]_&]:text-amber-300">
                <i class="fas fa-plane-departure"></i>
                You are marked as on leave. You can still view your existing cases, but the Open for Evaluation queue is hidden and you cannot submit decisions until your leave ends.
            </div>
        <?php endif; ?>

        <!-- SUMMARY CARDS -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 sm:mb-5">
            <?php foreach ($summary_cards as $card):
                $sc = status_color($card['key'], $status_style); ?>
                <div class="rounded-xl border border-[var(--border)] border-l-4 bg-[var(--card)] px-4 py-3.5" style="border-left-color:<?= $card['color'] ?>">
                    <div class="text-[11.5px] font-semibold text-[var(--text3)]"><?= htmlspecialchars($sc['label']) ?></div>
                    <div class="mt-0.5 text-2xl font-extrabold leading-tight text-[var(--text1)]"><?= $stats[$card['key']] ?></div>
                    <div class="text-[11.5px] text-[var(--text3)]"><?= htmlspecialchars($card['note']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- APPLICATIONS CARD -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">

            <!-- Heading + Search (live) + Month / Country filters -->
            <div class="flex flex-col gap-3 p-3 sm:p-4">
                <div class="card-title">
                    <span class="ctdot" style="background:var(--indigo)"></span>
                    <span id="cardHeading"><?= htmlspecialchars($card_heading) ?></span>
                </div>

                <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                    <div class="relative flex-1">
                        <i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-[13px] text-[var(--text3)]"></i>
                        <input
                            type="text"
                            id="app-search-input"
                            value="<?= htmlspecialchars($search_filter) ?>"
                            placeholder="Search by name, email, position, employer, RSF #, or country…"
                            autocomplete="off"
                            aria-label="Search applications"
                            class="w-full rounded-xl border border-[var(--border)] bg-[var(--card)] py-2.5 pl-11 pr-10 text-[13px] font-medium text-[var(--text1)] outline-none transition focus:border-[var(--navy)] focus:ring-1 focus:ring-[var(--navy)]">
                        <button type="button" id="app-search-clear"
                            class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-[var(--text3)] hover:text-[var(--text1)]"
                            title="Clear search" aria-label="Clear search" style="<?= $search_filter === '' ? 'display:none' : '' ?>">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="filter-scroll flex items-center gap-2 flex-wrap">
                        <label class="filter-pill">
                            <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                            <select id="filter-month">
                                <option value="">All Time</option>
                                <?php
                                $cur_year = (int) date('Y');
                                for ($m = 1; $m <= 12; $m++) {
                                    $val = $cur_year . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                                    $lbl = date('M Y', mktime(0, 0, 0, $m, 1, $cur_year));
                                    $sel = ($val === $month_filter) ? 'selected' : '';
                                    echo "<option value=\"$val\" $sel>$lbl</option>\n";
                                }
                                ?>
                            </select>
                        </label>
                        <label class="filter-pill">
                            <i class="fas fa-calendar-day" style="color:var(--blue-lt)"></i>
                            <select id="filter-period">
                                <option value="">Any Day</option>
                                <option value="today" <?= $period_filter === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= $period_filter === 'week' ? 'selected' : '' ?>>This Week</option>
                            </select>
                        </label>
                        <label class="filter-pill">
                            <i class="fas fa-globe" style="color:var(--indigo)"></i>
                            <select id="filter-country">
                                <option value="">All Destinations</option>
                                <?php foreach ($countries as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= $c === $country_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Status tabs -->
            <nav class="flex overflow-x-auto border-b border-[var(--border)] px-2 sm:px-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Filter by status">
                <?php
                $tabBase = 'filter-tile group -mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-3 sm:px-3.5 py-3 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition hover:bg-[var(--surface)] hover:text-[var(--text1)] '
                    . 'data-[active=true]:border-[var(--navy)] data-[active=true]:font-bold data-[active=true]:text-[var(--navy)] '
                    . '[[data-theme=dark]_&]:data-[active=true]:border-blue-400 [[data-theme=dark]_&]:data-[active=true]:text-blue-300';
                $countBase = 'min-w-[22px] rounded-full bg-[var(--surface)] px-2 py-px text-center text-[11px] font-bold text-[var(--text2)] '
                    . 'group-data-[active=true]:bg-[var(--navy)] group-data-[active=true]:text-white '
                    . '[[data-theme=dark]_&]:group-data-[active=true]:bg-blue-500';
                $allBase = build_tile_base(null, $month_filter, $country_filter, $period_filter);
                $allHref = $allBase . ($search_filter !== '' ? (str_contains($allBase, '?') ? '&' : '?') . 'q=' . urlencode($search_filter) : '');
                ?>
                <a href="applications.php<?= $search_filter !== '' ? '?q=' . urlencode($search_filter) : '' ?>"
                    onclick="return filterByStatus('', this)"
                    class="<?= $tabBase ?>"
                    data-base="applications.php"
                    data-active="<?= !$status_filter ? 'true' : 'false' ?>">
                    <i class="fas fa-list text-[11px] text-blue-400"></i> All
                    <span class="<?= $countBase ?>"><?= $total_count ?></span>
                </a>

                <?php foreach ($filter_keys as $i => $key):
                    $sc       = status_color($key, $status_style);
                    $isActive = ($key === 'assign')
                        ? ($status_filter === 'assign' || $status_filter === 'assigned')
                        : ($status_filter === $key);
                    $tileBase = build_tile_base($key, $month_filter, $country_filter, $period_filter);
                    $tileHref = $tileBase . ($search_filter !== '' ? (str_contains($tileBase, '?') ? '&' : '?') . 'q=' . urlencode($search_filter) : '');
                ?>
                    <a href="applications.php?status=<?= $key ?><?= $search_filter !== '' ? '&q=' . urlencode($search_filter) : '' ?>"
                        onclick="return filterByStatus('<?= $key ?>', this)"
                        class="<?= $tabBase ?>"
                        data-base="applications.php?status=<?= $key ?>"
                        data-active="<?= $isActive ? 'true' : 'false' ?>">
                        <i class="fas <?= $sc['icon'] ?> text-[11px]" style="color:<?= $sc['fg'] ?>"></i>
                        <?= htmlspecialchars($sc['label']) ?>
                        <span class="<?= $countBase ?>"><?= $stats[$key] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Empty state -->
            <div id="empty-state" class="px-4 sm:px-6 py-12 sm:py-16 text-center" style="<?= empty($applications) ? '' : 'display:none' ?>">
                <i class="fas fa-inbox text-4xl text-[var(--border)] block mb-3"></i>
                <p class="text-sm font-bold text-[var(--text2)]">No applications found</p>
                <span class="text-xs text-[var(--text3)]" id="empty-state-text">
                    <?php if ($search_filter !== ''): ?>
                        No results for "<?= htmlspecialchars($search_filter) ?>"<?= $status_filter ? ' in "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?>. Try a different name, email, position, employer, RSF #, or country.
                    <?php elseif ($status_filter === 'available'): ?>
                        Nothing waiting to be claimed right now — check back later.
                    <?php elseif ($status_filter): ?>
                        No cases of yours with status "<?= htmlspecialchars(str_replace('_', ' ', $status_filter)) ?>".
                    <?php else: ?>
                        You have no cases yet — check the Open for Evaluation tab to claim one.
                    <?php endif; ?>
                </span>
            </div>

            <div id="results-wrapper" style="<?= empty($applications) ? 'display:none' : '' ?>">

                <!-- Desktop / tablet: table -->
                <div class="hidden md:block overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <table class="w-full min-w-[960px] border-collapse text-sm">
                        <thead>
                            <tr class="bg-[var(--surface)] text-[11px] font-bold uppercase tracking-wide text-[var(--text3)]">
                                <th class="px-5 py-3 text-left">Applicant</th>
                                <th class="px-5 py-3 text-left">Position &amp; Employer</th>
                                <th class="px-5 py-3 text-left">RSF / Country</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th class="px-5 py-3 text-left">Updated</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="app-tbody" class="divide-y divide-[var(--border)]">
                            <?php foreach ($applications as $app):
                                $status     = strtolower($app['status'] ?? 'unknown');
                                $statusText = str_replace('_', ' ', ucwords($status));
                                $sc         = status_color($status, $status_style);

                                $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                                $avi = strtoupper(count($np) >= 2
                                    ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                                    : substr($app['applicant_name'] ?? 'NA', 0, 2));
                                $app_has_pic = !empty($app['applicant_picture']);
                                $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                            ?>
                                <tr class="group cursor-pointer transition hover:bg-[var(--surface)]"
                                    onclick="openApplication(<?= (int)$app['id'] ?>, '<?= htmlspecialchars($status, ENT_QUOTES) ?>')">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-[#eef2ff] text-xs font-extrabold text-[var(--indigo)] [[data-theme=dark]_&]:bg-indigo-500/20 [[data-theme=dark]_&]:text-indigo-300"
                                                <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                                                <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="truncate text-[13.5px] font-semibold text-[var(--text1)]"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                                                <div class="truncate text-[11.5px] text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="text-[13px] font-semibold text-[var(--text1)]"><?= htmlspecialchars($app['position_title'] ?? '—') ?></div>
                                        <div class="text-[11.5px] text-[var(--text3)]"><?= htmlspecialchars($app['employer_name'] ?? '—') ?></div>
                                        <?= deadline_badge($app['deadline'] ?? null, $status) ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="text-[13px] font-bold text-[var(--blue)]"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></div>
                                        <div class="text-[11.5px] text-[var(--text3)]"><?= htmlspecialchars($app['country'] ?? '—') ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="status-badge inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-[10.5px] font-bold uppercase tracking-wide <?= status_badge_cls($sc) ?>"
                                            data-status="<?= htmlspecialchars($status) ?>">
                                            <i class="fas <?= $sc['icon'] ?> text-[9px]"></i><?= htmlspecialchars($statusText) ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-[var(--text3)]">
                                        <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] px-3.5 py-1.5 text-xs font-semibold text-[var(--text2)] transition group-hover:border-[var(--navy)] group-hover:text-[var(--navy)] [[data-theme=dark]_&]:group-hover:border-blue-400 [[data-theme=dark]_&]:group-hover:text-blue-300">
                                            <?= $action_label ?> <i class="fas fa-chevron-right text-[10px]"></i>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile: stacked cards -->
                <div class="md:hidden flex flex-col divide-y divide-[var(--border)]" id="app-cards">
                    <?php foreach ($applications as $i => $app):
                        $status     = strtolower($app['status'] ?? 'unknown');
                        $statusText = str_replace('_', ' ', ucwords($status));
                        $sc         = status_color($status, $status_style);

                        $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                        $avi = strtoupper(count($np) >= 2
                            ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                            : substr($app['applicant_name'] ?? 'NA', 0, 2));
                        $app_has_pic = !empty($app['applicant_picture']);
                        $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                    ?>
                        <a href="javascript:void(0)"
                            onclick="return openApplication(<?= (int)$app['id'] ?>, '<?= htmlspecialchars($status, ENT_QUOTES) ?>')"
                            class="flex items-start gap-3 p-4 no-underline text-inherit transition hover:bg-[var(--surface)]">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-[#eef2ff] text-xs font-extrabold text-[var(--indigo)] [[data-theme=dark]_&]:bg-indigo-500/20 [[data-theme=dark]_&]:text-indigo-300"
                                <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                                <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-[13.5px] font-semibold text-[var(--text1)]"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                                        <div class="truncate text-[11px] text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                                    </div>
                                    <span class="status-badge inline-flex flex-shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-[9.5px] font-bold uppercase tracking-wide <?= status_badge_cls($sc) ?>"
                                        data-status="<?= htmlspecialchars($status) ?>">
                                        <i class="fas <?= $sc['icon'] ?>"></i><?= htmlspecialchars($statusText) ?>
                                    </span>
                                </div>
                                <div class="mt-2 text-[12.5px] font-semibold text-[var(--text1)]"><?= htmlspecialchars($app['position_title'] ?? '—') ?></div>
                                <div class="text-[11px] text-[var(--text3)]"><?= htmlspecialchars($app['employer_name'] ?? '—') ?> · <?= htmlspecialchars($app['country'] ?? '—') ?></div>
                                <?= deadline_badge($app['deadline'] ?? null, $status) ?>
                                <div class="mt-1.5 flex items-center justify-between text-[11px] text-[var(--text3)]">
                                    <span class="font-bold text-[var(--blue)]"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></span>
                                    <span><?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

            </div>

            <?php if ($total_pages > 1 || $show_all): ?>
                <div id="pagination-wrap" class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--border)] px-4 sm:px-5 py-3.5">
                    <span class="text-xs font-medium text-[var(--text3)]" id="pagination-page-label">
                        <?= $show_all ? 'Showing all ' . $total_filtered . ' result' . ($total_filtered !== 1 ? 's' : '') : 'Page ' . $page . ' of ' . $total_pages ?>
                    </span>
                    <div class="flex flex-wrap items-center gap-1.5" id="pagination-links">
                        <?php
                        $pgBase = 'flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--border)] text-xs font-semibold transition';
                        $pgIdle = 'bg-[var(--surface)] text-[var(--text2)] hover:bg-[var(--border)]';
                        ?>
                        <?php if (!$show_all): ?>
                            <a href="javascript:void(0)" onclick="goToPage(<?= max(1, $page - 1) ?>)"
                                class="<?= $pgBase ?> <?= $page <= 1 ? 'pointer-events-none opacity-40' : $pgIdle ?>" aria-label="Previous page">
                                <i class="fas fa-chevron-left text-[10px]"></i>
                            </a>
                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                <a href="javascript:void(0)" onclick="goToPage(<?= $p ?>)"
                                    class="<?= $pgBase ?> <?= $p === $page ? 'bg-[var(--navy)] text-white border-transparent [[data-theme=dark]_&]:!bg-blue-600 [[data-theme=dark]_&]:!text-white' : $pgIdle ?>">
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>
                            <a href="javascript:void(0)" onclick="goToPage(<?= min($total_pages, $page + 1) ?>)"
                                class="<?= $pgBase ?> <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : $pgIdle ?>" aria-label="Next page">
                                <i class="fas fa-chevron-right text-[10px]"></i>
                            </a>
                        <?php endif; ?>
                        <button type="button" id="view-all-btn" onclick="toggleViewAll()"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[var(--border)] px-3 text-xs font-semibold transition <?= $pgIdle ?>">
                            <i class="fas <?= $show_all ? 'fa-table-list' : 'fa-list-ul' ?>"></i>
                            <?= $show_all ? 'Back to Pages' : 'View All Applications' ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </main>

    <!-- FLOATING DOCK (unchanged) -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk active">
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
        /* ── Live date + time ────────────────────────────────────────────── */
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

        let CURRENT_STATUS = <?= json_encode($status_filter) ?>;
        let CURRENT_PAGE = <?= json_encode($page) ?>;
        let SHOW_ALL = <?= json_encode($show_all) ?>;
        let lastTotalCount = <?= json_encode($total_filtered) ?>;
        /**
         * Gate for opening an application row/card.
         * Returns false in all cases to stop any default <a>/onclick
         * navigation from firing alongside this handler.
         */
        function openApplication(id, status) {
            // Coming from the shared Available queue — clicking through
            // claims the case for this evaluator, so confirm first.
            if (CURRENT_STATUS === 'available') {
                Swal.fire({
                    icon: 'question',
                    title: 'Evaluate this application?',
                    text: 'Opening this will claim it for evaluation and assign it to you. It will then leave the shared Open for Evaluation queue and other evaluators will no longer be able to pick it up.',
                    showCancelButton: true,
                    confirmButtonColor: '#1e40af',
                    cancelButtonColor: '#94a3b8',
                    confirmButtonText: 'Yes, claim & evaluate',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location = 'view-application.php?id=' + id;
                    }
                });
                return false;
            }

            window.location = 'view-application.php?id=' + id;
            return false;
        }

        // ══════════════════════════════════════════
        // LIVE SEARCH — filters the list as you type,
        // no page reload. Mirrors the PHP status_style table above so
        // rendered rows look identical to the server-rendered ones.
        // ══════════════════════════════════════════


        const STATUS_STYLE = {
            available: {
                bg: '#d1fae5',
                fg: '#065f46',
                dark: '#6ee7b7',
                icon: 'fa-inbox',
                label: 'Open for Evaluation'
            },
            pending: {
                bg: '#dbeafe',
                fg: '#1e40af',
                dark: '#93c5fd',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            assign: {
                bg: '#dbeafe',
                fg: '#1e40af',
                dark: '#93c5fd',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            assigned: {
                bg: '#dbeafe',
                fg: '#1e40af',
                dark: '#93c5fd',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            under_evaluation: {
                bg: '#e0e7ff',
                fg: '#3730a3',
                dark: '#a5b4fc',
                icon: 'fa-magnifying-glass',
                label: 'Under Evaluation'
            },
            for_compliance: {
                bg: '#ffedd5',
                fg: '#92400e',
                dark: '#fbbf24',
                icon: 'fa-triangle-exclamation',
                label: 'For Compliance'
            },
            approved: {
                bg: '#d1fae5',
                fg: '#065f46',
                dark: '#6ee7b7',
                icon: 'fa-circle-check',
                label: 'Approved'
            },
            disapproved: {
                bg: '#fee2e2',
                fg: '#991b1b',
                dark: '#fca5a5',
                icon: 'fa-circle-xmark',
                label: 'Disapproved'
            },
            resubmitted: {
                bg: '#fef3c7',
                fg: '#92400e',
                dark: '#fcd34d',
                icon: 'fa-rotate-right',
                label: 'Resubmitted'
            },
            default: {
                bg: '#f1f5f9',
                fg: '#475569',
                dark: '#cbd5e1',
                icon: 'fa-circle',
                label: 'Unknown'
            },
        };
        const statusStyle = (key) => STATUS_STYLE[key] || STATUS_STYLE.default;

        // Same Tailwind classes as status_badge_cls() in PHP.
        const badgeCls = (sc) =>
            `bg-[${sc.bg}] text-[${sc.fg}] [[data-theme=dark]_&]:bg-transparent [[data-theme=dark]_&]:px-0 [[data-theme=dark]_&]:text-[${sc.dark}]`;

        const AVATAR_CLS = 'flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-[#eef2ff] text-xs font-extrabold text-[var(--indigo)] [[data-theme=dark]_&]:bg-indigo-500/20 [[data-theme=dark]_&]:text-indigo-300';
        const PG_BASE = 'flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--border)] text-xs font-semibold transition';
        const PG_IDLE = 'bg-[var(--surface)] text-[var(--text2)] hover:bg-[var(--border)]';

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            const d = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric'
            });
        }

        function deadlineBadge(deadline, status) {
            if (!deadline || ['approved', 'disapproved', 'draft'].includes(status)) return '';
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const days = Math.floor((new Date(deadline + 'T00:00:00') - today) / 86400000);
            const red = 'bg-red-100 text-red-700 [[data-theme=dark]_&]:bg-red-500/15 [[data-theme=dark]_&]:text-red-300';
            const amber = 'bg-amber-100 text-amber-800 [[data-theme=dark]_&]:bg-amber-500/15 [[data-theme=dark]_&]:text-amber-300';
            let txt, cls, ico;
            if (days < 0) {
                const ago = Math.abs(days);
                txt = ago === 1 ? 'Deadline passed 1 day ago' : `Deadline passed ${ago} days ago`;
                cls = red;
                ico = 'fa-circle-exclamation';
            } else if (days <= 3) {
                txt = days === 0 ? 'Due today' : `Due in ${days} day${days > 1 ? 's' : ''}`;
                cls = red;
                ico = 'fa-fire';
            } else if (days <= 7) {
                txt = `Due in ${days} days`;
                cls = amber;
                ico = 'fa-clock';
            } else {
                txt = `${days} days left`;
                cls = 'bg-slate-100 text-slate-600 [[data-theme=dark]_&]:bg-slate-500/15 [[data-theme=dark]_&]:text-slate-300';
                ico = 'fa-calendar-day';
            }
            return `<span title="Job order deadline: ${escapeHtml(deadline)}" class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${cls}"><i class="fas ${ico} text-[9px]"></i>${txt}</span>`;
        }

        function avatarInitials(name) {
            const parts = (name || '').trim().split(/\s+/).filter(Boolean);
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }
            return (name || 'NA').substring(0, 2).toUpperCase();
        }

        function renderRow(app) {
            const status = (app.status || 'unknown').toLowerCase();
            const statusText = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const sc = statusStyle(status);
            const avi = avatarInitials(app.applicant_name);
            const hasPic = !!app.applicant_picture;
            const picUrl = hasPic ? '../' + app.applicant_picture : '';
            const avatarStyle = hasPic ? `style="background-image:url('${picUrl}');background-size:cover;background-position:center;"` : '';
            const actionLabel = CURRENT_STATUS === 'available' ? 'Claim' : 'Open';

            return `
                <tr class="group cursor-pointer transition hover:bg-[var(--surface)]"
                    onclick="openApplication(${app.id}, '${status}')">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3">
                            <div class="${AVATAR_CLS}" ${avatarStyle}>${hasPic ? '' : escapeHtml(avi)}</div>
                            <div class="min-w-0">
                                <div class="truncate text-[13.5px] font-semibold text-[var(--text1)]">${escapeHtml(app.applicant_name || '—')}</div>
                                <div class="truncate text-[11.5px] text-[var(--text3)]">${escapeHtml(app.applicant_email || '')}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-4">
                        <div class="text-[13px] font-semibold text-[var(--text1)]">${escapeHtml(app.position_title || '—')}</div>
                        <div class="text-[11.5px] text-[var(--text3)]">${escapeHtml(app.employer_name || '—')}</div>
${deadlineBadge(app.deadline, status)}
                    </td>
                    <td class="px-5 py-4">
                        <div class="text-[13px] font-bold text-[var(--blue)]">${escapeHtml(app.rsf_number || '—')}</div>
                        <div class="text-[11.5px] text-[var(--text3)]">${escapeHtml(app.country || '—')}</div>
                    </td>
                    <td class="px-5 py-4">
                        <span class="status-badge inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-[10.5px] font-bold uppercase tracking-wide ${badgeCls(sc)}" data-status="${status}">
                            <i class="fas ${sc.icon} text-[9px]"></i>${escapeHtml(statusText)}
                        </span>
                    </td>
                    <td class="px-5 py-4 text-xs text-[var(--text3)]">${formatDate(app.updated_at)}</td>
                    <td class="px-5 py-4 text-right">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] px-3.5 py-1.5 text-xs font-semibold text-[var(--text2)] transition group-hover:border-[var(--navy)] group-hover:text-[var(--navy)] [[data-theme=dark]_&]:group-hover:border-blue-400 [[data-theme=dark]_&]:group-hover:text-blue-300">
                            ${actionLabel} <i class="fas fa-chevron-right text-[10px]"></i>
                        </span>
                    </td>
                </tr>`;
        }

        function renderCard(app, i) {
            const status = (app.status || 'unknown').toLowerCase();
            const statusText = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const sc = statusStyle(status);
            const avi = avatarInitials(app.applicant_name);
            const hasPic = !!app.applicant_picture;
            const picUrl = hasPic ? '../' + app.applicant_picture : '';
            const avatarStyle = hasPic ? `style="background-image:url('${picUrl}');background-size:cover;background-position:center;"` : '';

            return `
                <a href="javascript:void(0)"
                    onclick="return openApplication(${app.id}, '${status}')"
                    class="flex items-start gap-3 p-4 no-underline text-inherit transition hover:bg-[var(--surface)]">
                    <div class="${AVATAR_CLS}" ${avatarStyle}>${hasPic ? '' : escapeHtml(avi)}</div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-[13.5px] font-semibold text-[var(--text1)]">${escapeHtml(app.applicant_name || '—')}</div>
                                <div class="truncate text-[11px] text-[var(--text3)]">${escapeHtml(app.applicant_email || '')}</div>
                            </div>
                            <span class="status-badge inline-flex flex-shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-[9.5px] font-bold uppercase tracking-wide ${badgeCls(sc)}" data-status="${status}">
                                <i class="fas ${sc.icon}"></i>${escapeHtml(statusText)}
                            </span>
                        </div>
                        <div class="mt-2 text-[12.5px] font-semibold text-[var(--text1)]">${escapeHtml(app.position_title || '—')}</div>
                        <div class="text-[11px] text-[var(--text3)]">${escapeHtml(app.employer_name || '—')} · ${escapeHtml(app.country || '—')}</div>
${deadlineBadge(app.deadline, status)}
                        <div class="mt-1.5 flex items-center justify-between text-[11px] text-[var(--text3)]">
                            <span class="font-bold text-[var(--blue)]">${escapeHtml(app.rsf_number || '—')}</span>
                            <span>${formatDate(app.updated_at)}</span>
                        </div>
                    </div>
                </a>`;
        }
        const CURRENT_MONTH = <?= json_encode($month_filter) ?>;
        const CURRENT_COUNTRY = <?= json_encode($country_filter) ?>;
        const CURRENT_PERIOD = <?= json_encode($period_filter) ?>;
        const searchInput = document.getElementById('app-search-input');
        const clearBtn = document.getElementById('app-search-clear');
        const countLine = document.getElementById('app-count-line');
        const emptyState = document.getElementById('empty-state');
        const emptyText = document.getElementById('empty-state-text');
        const resultsWrap = document.getElementById('results-wrapper');
        const tbody = document.getElementById('app-tbody');
        const cardsWrap = document.getElementById('app-cards');

        function renderResults(list, term, total = list.length, totalPages = 1, page = 1) {
            lastTotalCount = total;
            if (!list.length) {
                resultsWrap.style.display = 'none';
                emptyState.style.display = '';
                emptyText.textContent = term ?
                    `No results for "${term}"${CURRENT_STATUS ? ' in "' + CURRENT_STATUS.replace(/_/g, ' ') + '"' : ''}. Try a different name, email, position, employer, RSF #, or country.` :
                    'No applications found.';
            } else {
                emptyState.style.display = 'none';
                resultsWrap.style.display = '';
                tbody.innerHTML = list.map(renderRow).join('');
                cardsWrap.innerHTML = list.map(renderCard).join('');
            }

            countLine.textContent =
                `${total} application${total !== 1 ? 's' : ''} found` +
                (CURRENT_STATUS ? ` · filtered by "${CURRENT_STATUS.replace(/_/g, ' ')}"` : '') +
                (term ? ` · matching "${term}"` : '') +
                (SHOW_ALL ? ' · Showing all' : ` · Page ${page} of ${totalPages}`);

            renderPagination(totalPages, page);
        }

        function renderPagination(totalPages, page) {
            let wrap = document.getElementById('pagination-wrap');
            if (totalPages <= 1 && !SHOW_ALL) {
                if (wrap) wrap.remove();
                return;
            }

            let linksHtml = '';
            if (!SHOW_ALL) {
                linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${Math.max(1, page - 1)})" class="${PG_BASE} ${page <= 1 ? 'pointer-events-none opacity-40' : PG_IDLE}" aria-label="Previous page"><i class="fas fa-chevron-left text-[10px]"></i></a>`;
                for (let p = 1; p <= totalPages; p++) {
                    linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${p})" class="${PG_BASE} ${p === page ? 'bg-[var(--navy)] text-white border-transparent [[data-theme=dark]_&]:!bg-blue-600 [[data-theme=dark]_&]:!text-white' : PG_IDLE}">${p}</a>`;
                }
                linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${Math.min(totalPages, page + 1)})" class="${PG_BASE} ${page >= totalPages ? 'pointer-events-none opacity-40' : PG_IDLE}" aria-label="Next page"><i class="fas fa-chevron-right text-[10px]"></i></a>`;
            }
            linksHtml += `<button type="button" id="view-all-btn" onclick="toggleViewAll()" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[var(--border)] px-3 text-xs font-semibold transition ${PG_IDLE}"><i class="fas ${SHOW_ALL ? 'fa-table-list' : 'fa-list-ul'}"></i> ${SHOW_ALL ? 'Back to Pages' : 'View All Applications'}</button>`;

            const label = SHOW_ALL ?
                `Showing all ${lastTotalCount} result${lastTotalCount !== 1 ? 's' : ''}` :
                `Page ${page} of ${totalPages}`;

            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'pagination-wrap';
                wrap.className = 'flex flex-wrap items-center justify-between gap-2 border-t border-[var(--border)] px-4 sm:px-5 py-3.5';
                resultsWrap.insertAdjacentElement('afterend', wrap);
            }
            wrap.innerHTML = `<span class="text-xs font-medium text-[var(--text3)]" id="pagination-page-label">${label}</span><div class="flex flex-wrap items-center gap-1.5" id="pagination-links">${linksHtml}</div>`;
        }

        function goToPage(p) {
            if (p < 1) return;
            CURRENT_PAGE = p;
            runSearch(false);
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        function toggleViewAll() {
            SHOW_ALL = !SHOW_ALL;
            CURRENT_PAGE = 1;

            const params = new URLSearchParams(window.location.search);
            if (SHOW_ALL) {
                params.set('all', '1');
            } else {
                params.delete('all');
            }
            params.delete('page');
            const newUrl = 'applications.php' + (params.toString() ? '?' + params.toString() : '');
            window.history.pushState({}, '', newUrl);

            runSearch(false);
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        let searchDebounce = null;
        let searchAbort = null;

        function runSearch(resetPage = true) {
            const term = searchInput.value.trim();
            clearBtn.style.display = term ? '' : 'none';
            if (resetPage) CURRENT_PAGE = 1;

            if (searchAbort) searchAbort.abort();
            searchAbort = new AbortController();

            const params = new URLSearchParams();
            if (CURRENT_STATUS) params.set('status', CURRENT_STATUS);
            if (term) params.set('q', term);
            if (CURRENT_MONTH) params.set('month', CURRENT_MONTH);
            if (CURRENT_COUNTRY) params.set('country', CURRENT_COUNTRY);
            if (CURRENT_PERIOD) params.set('period', CURRENT_PERIOD);
            if (SHOW_ALL) params.set('all', '1');
            params.set('page', CURRENT_PAGE);

            fetch('applications_search.php?' + params.toString(), {
                    signal: searchAbort.signal
                })
                .then(r => r.json())
                .then(data => renderResults(data.applications || [], term, data.total ?? (data.applications || []).length, data.total_pages ?? 1, data.page ?? CURRENT_PAGE))
                .catch(err => {
                    if (err.name !== 'AbortError') console.error('Search failed:', err);
                });
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearBtn.style.display = searchInput.value ? '' : 'none';
                syncTileLinks();
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(runSearch, 300);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                syncTileLinks();
                clearTimeout(searchDebounce);
                runSearch();
            });
        }

        // Keep the status tabs' hrefs carrying the current search
        // term, so clicking one to change tabs doesn't lose what's typed.
        const tileLinks = document.querySelectorAll('.filter-tile');

        function syncTileLinks() {
            const term = searchInput ? searchInput.value.trim() : '';
            tileLinks.forEach(a => {
                const base = a.dataset.base;
                if (!term) {
                    a.href = base;
                    return;
                }
                a.href = base + (base.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(term);
            });
        }

        // Status label lookup for the page heading, mirrors PHP $page_title logic.
        function statusToTitle(status) {
            if (!status) return 'My Cases';
            return status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        // Label above the search box, mirrors PHP $card_heading logic.
        function statusToCardHeading(status) {
            return status ? statusStyle(status).label + ' Applications' : 'All Applications';
        }

        function filterByStatus(status, el) {
            CURRENT_STATUS = status;
            CURRENT_PAGE = 1;

            // Move the active marker to the clicked tab
            document.querySelectorAll('.filter-tile').forEach(a => a.dataset.active = 'false');
            el.dataset.active = 'true';

            // Update the page heading text
            const heading = document.getElementById('page-heading-text');
            if (heading) heading.textContent = statusToTitle(status);

            const cardHeading = document.getElementById('cardHeading');
            if (cardHeading) cardHeading.textContent = statusToCardHeading(status);

            // Keep the URL shareable/bookmarkable without reloading the page
            const params = new URLSearchParams();
            if (status) params.set('status', status);
            const term = searchInput ? searchInput.value.trim() : '';
            if (term) params.set('q', term);
            if (CURRENT_MONTH) params.set('month', CURRENT_MONTH);
            if (CURRENT_COUNTRY) params.set('country', CURRENT_COUNTRY);
            if (CURRENT_PERIOD) params.set('period', CURRENT_PERIOD);
            const newUrl = 'applications.php' + (params.toString() ? '?' + params.toString() : '');
            window.history.pushState({}, '', newUrl);

            syncTileLinks();
            runSearch(true);
            return false; // stop the <a> from navigating
        }

        function navigateWithSubbarFilters() {
            const params = new URLSearchParams();
            if (CURRENT_STATUS) params.set('status', CURRENT_STATUS);
            const term = searchInput ? searchInput.value.trim() : '';
            if (term) params.set('q', term);

            const month = document.getElementById('filter-month').value;
            const country = document.getElementById('filter-country').value;
            const period = document.getElementById('filter-period').value;
            if (month) params.set('month', month);
            if (country) params.set('country', country);
            if (period) params.set('period', period);
            if (SHOW_ALL) params.set('all', '1');

            window.location.href = 'applications.php' + (params.toString() ? '?' + params.toString() : '');
        }
        document.getElementById('filter-month')?.addEventListener('change', navigateWithSubbarFilters);
        document.getElementById('filter-country')?.addEventListener('change', navigateWithSubbarFilters);
        document.getElementById('filter-period')?.addEventListener('change', navigateWithSubbarFilters);

        /* PILL-STYLE DROPDOWNS for the subbar's Month
           & Country filters — same pattern as the one
           used on admin/report.php's filter selects.
           The native <select> stays in the DOM (hidden)
           so existing 'change' listeners above keep
           firing exactly as before; only the visible
           trigger + option list are custom-built. */
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
            panel.style.cssText = 'position:fixed;border-radius:8px;box-shadow:0 10px 30px rgba(11,31,75,.16);z-index:999;overflow:hidden;padding:4px 0;max-height:240px;overflow-y:auto;width:224px;';
            panel.classList.add('hidden');
            document.body.appendChild(panel);

            function isDark() {
                return document.documentElement.getAttribute('data-theme') === 'dark';
            }

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.style.cssText = `display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 12px;font-size:12px;color:${isDark() ? '#e2e8f0' : '#0f172a'};cursor:pointer;`;
                row.dataset.value = optionEl.value;
                row.addEventListener('mouseenter', () => row.style.background = isDark() ? '#1e293b' : '#f8fafc');
                row.addEventListener('mouseleave', () => row.style.background = row.dataset.selected === '1' ? (isDark() ? '#1e3a5f' : '#eff6ff') : '');
                const label = document.createElement('span');
                label.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
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
                const dark = isDark();
                panel.querySelectorAll('[role="option"]').forEach(row => {
                    const selected = row.dataset.value === select.value;
                    row.dataset.selected = selected ? '1' : '0';
                    row.style.background = selected ? (dark ? '#1e3a5f' : '#eff6ff') : '';
                    row.style.color = selected ? (dark ? '#93c5fd' : '#1d4ed8') : (dark ? '#e2e8f0' : '#0f172a');
                    row.style.fontWeight = selected ? '700' : '400';
                    let check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check';
                        icon.style.cssText = `font-size:10px;color:${dark ? '#60a5fa' : '#3b82f6'};flex-shrink:0;`;
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
                const dark = isDark();
                panel.style.background = dark ? '#1e293b' : '#fff';
                panel.style.border = `1.5px solid ${dark ? '#334155' : '#e2e8f0'}`;
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

        // Enhance every <select> inside the subbar's .filter-scroll —
        // currently #filter-month and #filter-country.
        document.querySelectorAll('.filter-scroll select').forEach(enhancePillSelect);
    </script>
    <script type="module" src="../js/main.js"></script>
    <script>
        window.DMW_USER_EMAIL = <?= json_encode($evaluator_email) ?>;
        window.DMW_NOTIF_API = '../api/poll_notifications.php';
        window.DMW_APP_VIEW_URL = 'view-application.php?id=';
    </script>
    <script src="../js/live-notifications.js"></script>
    <?php if ($flash_success): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.mixin({
                    toast: true,
                    position: "top-end",
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.onmouseenter = Swal.stopTimer;
                        toast.onmouseleave = Swal.resumeTimer;
                    }
                }).fire({
                    icon: "success",
                    title: <?= json_encode($flash_success) ?>
                });
            });
        </script>
    <?php endif; ?>

    <?php if ($flash_success): ?>
        <script>
            // Strip the flash params from the URL so refreshing/back-navigating
            // doesn't re-trigger the same alert.
            if (window.history.replaceState) {
                const url = new URL(window.location);
                ['evaluated', 'decision', 'locked', 'msg'].forEach(p => url.searchParams.delete(p));
                window.history.replaceState({}, '', url);
            }
        </script>
    <?php endif; ?>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
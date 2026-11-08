<?php
// evaluator/applications.php – List applications for evaluation
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

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

// Flash for the "locked" redirect that view-application.php already sends
// evaluators to when a case is unavailable — previously unhandled here.
$flash_locked = isset($_GET['locked'])
    ? ($_GET['msg'] ?? 'This application is not currently available for evaluation.')
    : null;
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

// Country filters by the RSF job order's worksite country.
$country_filter = trim($_GET['country'] ?? '');

/**
 * Builds a reusable " AND ..." WHERE fragment (+ matching bind types/params)
 * for the Month / Country subbar filters. Assumes applications is aliased
 * `a` and rsf_job_orders is aliased/joined as `r` in the calling query.
 */
function buildEvalFilters(string $month, string $country): array
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

    return [$where, $types, $params];
}

/** Builds the "base" URL (status + month + country, no search term) used
 *  for the stat/filter tile links — search term is appended client-side. */
function build_tile_base(?string $status, string $month, string $country): string
{
    $params = [];
    if ($status)         $params['status']  = $status;
    if ($month !== '')   $params['month']   = $month;
    if ($country !== '') $params['country'] = $country;
    return 'applications.php' . ($params ? '?' . http_build_query($params) : '');
}

[$filterWhere, $filterTypes, $filterParams] = buildEvalFilters($month_filter, $country_filter);

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

if ($status_filter === 'available') {
    // Shared queue, visible to all evaluators, oldest-waiting first —
    // first passed into the queue, first shown.
    $sql = "
        SELECT a.id, a.status, a.updated_at,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
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
        $sql .= " ORDER BY a.updated_at ASC";
    } else {
        $total_pages = max(1, (int) ceil($total_filtered / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql .= " ORDER BY a.updated_at ASC LIMIT ? OFFSET ?";
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
    // My cases: anything I currently own, plus anything on record as mine
    // even while Admin has it for compliance (current_evaluator_id keeps
    // it visible to me, read-only, until Admin reopens or reassigns it).
    $sql = "
        SELECT a.id, a.status, a.updated_at, a.handling_party,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
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
        $sql .= " ORDER BY a.updated_at DESC";
    } else {
        $total_pages = max(1, (int) ceil($total_filtered / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql .= " ORDER BY a.updated_at DESC LIMIT ? OFFSET ?";
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

$page_title = $status_filter
    ? ucwords(str_replace('_', ' ', $status_filter))
    : 'My Cases';

// Single source of truth for status colors/icons/labels — used for the
// stat/filter tiles AND the status pill on every row, so a status is only
// ever defined once.
$status_style = [
    'available' => ['bg' => '#d1fae5', 'fg' => '#065f46', 'icon' => 'fa-inbox', 'label' => 'Open for Evaluation'],
    'pending'          => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'assign'           => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'assigned'         => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'under_evaluation' => ['bg' => '#e0e7ff', 'fg' => '#3730a3', 'icon' => 'fa-magnifying-glass',     'label' => 'Under Evaluation'],
    'for_compliance'   => ['bg' => '#ffedd5', 'fg' => '#92400e', 'icon' => 'fa-triangle-exclamation', 'label' => 'For Compliance'],
    'approved'         => ['bg' => '#d1fae5', 'fg' => '#065f46', 'icon' => 'fa-circle-check',         'label' => 'Approved'],
    'disapproved'      => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'icon' => 'fa-circle-xmark',         'label' => 'Disapproved'],
    'resubmitted'      => ['bg' => '#fef3c7', 'fg' => '#92400e', 'icon' => 'fa-rotate-right',         'label' => 'Resubmitted'],
    'default'          => ['bg' => '#f1f5f9', 'fg' => '#475569', 'icon' => 'fa-circle',               'label' => 'Unknown'],
];

function status_color(string $key, array $map): array
{
    return $map[$key] ?? $map['default'];
}

// Statuses that are locked from the evaluator's perspective — the case is
// currently out of their hands (with Admin) or already finalized, so there
// is nothing left for them to open or act on.
//   - for_compliance : sitting with Admin for compliance review
//   - resubmitted     : same situation as for_compliance — needs Admin to
//                       open/reassign it back to an evaluator first
//   - approved        : terminal, decision already made
//   - disapproved     : terminal, decision already made
// NOT locked: pending (shared queue), assign/assigned (owned by this
// evaluator), under_evaluation (reopened by Admin back to the evaluator).
$locked_statuses = ['for_compliance', 'resubmitted', 'approved', 'disapproved'];

// Order + keys for the stat/filter tile row (mirrors the original 7 filters)
$filter_keys = ['available', 'assign', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$total_count = array_sum($stats) - $stats['available'];
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

    <!-- Tailwind, used for everything below the topbar -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'DM Sans', sans-serif;
        }

        .font-display {
            font-family: 'DM Serif Display', serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Fade-in-up entrance, staggered per row/tile via inline delay */
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
            opacity: 0;
            animation: fadeInUp .4s ease forwards;
        }

        /* Hide scrollbar on the mobile chip rail but keep it scrollable */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Locked row/card visual cue */
        .row-locked {
            opacity: .6;
            cursor: not-allowed !important;
        }

        /* Dark mode: strip the pill background/padding and switch each
           status to a lighter tint of its own color for readability. */
        [data-theme="dark"] .status-badge {
            background: transparent !important;
            padding: 0 !important;
            border-radius: 0 !important;
        }

        [data-theme="dark"] .status-badge[data-status="available"],
        [data-theme="dark"] .status-badge[data-status="approved"] {
            color: #6ee7b7 !important;
        }

        [data-theme="dark"] .status-badge[data-status="pending"],
        [data-theme="dark"] .status-badge[data-status="assign"],
        [data-theme="dark"] .status-badge[data-status="assigned"] {
            color: #93c5fd !important;
        }

        [data-theme="dark"] .status-badge[data-status="under_evaluation"] {
            color: #a5b4fc !important;
        }

        [data-theme="dark"] .status-badge[data-status="for_compliance"] {
            color: #fbbf24 !important;
        }

        [data-theme="dark"] .status-badge[data-status="disapproved"] {
            color: #fca5a5 !important;
        }

        [data-theme="dark"] .status-badge[data-status="resubmitted"] {
            color: #fcd34d !important;
        }

        [data-theme="dark"] .status-badge[data-status="unknown"],
        [data-theme="dark"] .status-badge[data-status="default"] {
            color: #cbd5e1 !important;
        }

        /* Dark mode: keep the Month/Country pill trigger text readable,
           both closed and while its dropdown panel is open. */
        [data-theme="dark"] .filter-pill,
        [data-theme="dark"] .filter-pill span,
        [data-theme="dark"] .filter-pill i,
        [data-theme="dark"] .filter-pill button {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .filter-pill button[aria-expanded="true"] {
            color: #ffffff !important;
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

        /* ══════════════════════════════════════════
           RESPONSIVE KPI / FILTER TILES
           Cards, text, icons, padding, and spacing all
           scale down smoothly as the viewport shrinks so
           nothing overlaps or overflows on small screens.
        ══════════════════════════════════════════ */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        @media (min-width: 480px) {
            .kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 9px;
            }
        }

        @media (min-width: 640px) {
            .kpi-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 10px;
            }
        }

        @media (min-width: 1024px) {
            .kpi-grid {
                grid-template-columns: repeat(8, minmax(0, 1fr));
                gap: 10px;
            }
        }

        .kpi-tile {
            min-width: 0;
            /* allow children to shrink instead of overflowing the grid cell */
            padding: 10px 11px;
            border-radius: 14px;
            gap: 6px;
        }

        @media (min-width: 480px) {
            .kpi-tile {
                padding: 11px 12px;
                border-radius: 15px;
            }
        }

        @media (min-width: 768px) {
            .kpi-tile {
                padding: 13px 14px;
                border-radius: 16px;
            }
        }

        .kpi-label {
            font-size: 8.5px;
            gap: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (min-width: 480px) {
            .kpi-label {
                font-size: 9px;
            }
        }

        @media (min-width: 768px) {
            .kpi-label {
                font-size: 10px;
                gap: 6px;
            }
        }

        .kpi-label i {
            font-size: 9px;
            flex-shrink: 0;
        }

        @media (min-width: 480px) {
            .kpi-label i {
                font-size: 10px;
            }
        }

        @media (min-width: 768px) {
            .kpi-label i {
                font-size: 11px;
            }
        }

        .kpi-value {
            font-size: 17px;
            line-height: 1.15;
        }

        @media (min-width: 480px) {
            .kpi-value {
                font-size: 19px;
            }
        }

        @media (min-width: 640px) {
            .kpi-value {
                font-size: 21px;
            }
        }

        @media (min-width: 1024px) {
            .kpi-value {
                font-size: 24px;
            }
        }

        /* Page header + search: scale text/spacing down on small screens */
        .page-heading {
            font-size: 17px;
        }

        @media (min-width: 480px) {
            .page-heading {
                font-size: 19px;
            }
        }

        @media (min-width: 768px) {
            .page-heading {
                font-size: 22px;
            }
        }

        .page-subline {
            font-size: 10.5px;
        }

        @media (min-width: 480px) {
            .page-subline {
                font-size: 11.5px;
            }
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body class="bg-[var(--bg,#f4f6fb)]">
    <!-- TOPBAR (unchanged) -->
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
            <!-- <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
            <?php include __DIR__ . '/avatar-menu.php'; ?>
        </div>
    </header>

    <!-- SUBBAR — Page title/count (left) + Month & Country filters (right) -->
    <div class="subbar flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-3">
        <div class="min-w-0">
            <h2 id="page-heading-text" class="font-display leading-tight text-[var(--navy)] truncate" style="font-size:18px;">
                <?= htmlspecialchars($page_title) ?>
            </h2>
            <p class="font-medium text-[var(--text3)] mt-0.5" id="app-count-line" style="font-size:12px;">
                <?= $total_filtered ?> application<?= $total_filtered !== 1 ? 's' : '' ?> found<?= $status_filter ? ' · filtered by "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?><?= $search_filter !== '' ? ' · matching "' . htmlspecialchars($search_filter) . '"' : '' ?> · <?= $show_all ? 'Showing all' : 'Page ' . $page . ' of ' . $total_pages ?>
            </p>
        </div>
        <div class="filter-scroll sm:justify-end">
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
    <!-- ══════════════════════════════════════════
         PAGE HEAD + STAT / FILTER TILES
    ══════════════════════════════════════════ -->
    <main class="max-w-[1400px] mx-auto px-3 sm:px-4 pt-4 sm:pt-5" style="padding-bottom: calc(var(--dock-h) + 28px + env(safe-area-inset-bottom));">

        <div class="flex items-center justify-between gap-3 flex-wrap mb-4 sm:mb-5 fade-in" style="animation-delay:.02s">
            <a href="index.php"
                class="inline-flex items-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 sm:px-4 sm:py-2 text-[11px] sm:text-xs font-semibold text-[var(--text2)] no-underline transition hover:-translate-y-0.5 hover:bg-[var(--surface)] flex-shrink-0">
                <i class="fas fa-arrow-left"></i> <span class="hidden xs:inline">Dashboard</span>
            </a>
        </div>

        <!-- Search (live — filters the list as you type, no page reload) -->
        <div class="fade-in mb-4 sm:mb-5" style="animation-delay:.03s">
            <div class="relative w-full md:w-2/3 lg:w-1/2">
                <i class="fas fa-magnifying-glass absolute left-3.5 sm:left-4 top-1/2 -translate-y-1/2 text-[12px] sm:text-[13px] text-[var(--text3)]"></i>
                <input
                    type="text"
                    id="app-search-input"
                    value="<?= htmlspecialchars($search_filter) ?>"
                    placeholder="Search by name, email, position, employer, RSF #, or country…"
                    autocomplete="off"
                    class="w-full rounded-2xl border border-[var(--border)] bg-[var(--card)] py-2.5 sm:py-3 pl-10 sm:pl-11 pr-10 sm:pr-11 text-[12px] sm:text-[13px] font-medium text-[var(--text1)] outline-none transition focus:border-[var(--navy)] focus:ring-1 focus:ring-[var(--navy)]">
                <button type="button" id="app-search-clear"
                    class="absolute right-3 sm:right-3.5 top-1/2 -translate-y-1/2 text-[11px] sm:text-[12px] text-[var(--text3)] hover:text-[var(--text1)]"
                    title="Clear search" style="<?= $search_filter === '' ? 'display:none' : '' ?>">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div class="kpi-grid mb-5 sm:mb-6">

            <?php
            $allBase = build_tile_base(null, $month_filter, $country_filter);
            $allHref = $allBase . ($search_filter !== '' ? (str_contains($allBase, '?') ? '&' : '?') . 'q=' . urlencode($search_filter) : '');
            ?>
            <a href="applications.php<?= $search_filter !== '' ? '?q=' . urlencode($search_filter) : '' ?>"
                onclick="return filterByStatus('', this)"
                class="kpi-tile filter-tile fade-in group relative overflow-hidden border <?= !$status_filter ? 'border-[var(--navy)] ring-1 ring-[var(--navy)]' : 'border-[var(--border)]' ?> bg-[var(--card)] flex flex-col justify-between no-underline transition hover:-translate-y-0.5 hover:shadow-lg"
                data-base="applications.php"
                style="animation-delay:.05s">
                <span class="kpi-label flex items-center font-bold uppercase tracking-wide text-[var(--text3)]">
                    <i class="fas fa-list"></i> <span class="truncate">All</span>
                </span>
                <span class="kpi-value font-extrabold text-[var(--text1)]"><?= $total_count ?></span>
            </a>

            <?php foreach ($filter_keys as $i => $key):
                $sc       = status_color($key, $status_style);
                $isActive = ($key === 'assign')
                    ? ($status_filter === 'assign' || $status_filter === 'assigned')
                    : ($status_filter === $key);
                $delay    = .05 + (($i + 1) * .03);
            ?>
                <?php
                $tileBase = build_tile_base($key, $month_filter, $country_filter);
                $tileHref = $tileBase . ($search_filter !== '' ? (str_contains($tileBase, '?') ? '&' : '?') . 'q=' . urlencode($search_filter) : '');
                ?>
                <a href="applications.php?status=<?= $key ?><?= $search_filter !== '' ? '&q=' . urlencode($search_filter) : '' ?>"
                    onclick="return filterByStatus('<?= $key ?>', this)"
                    class="kpi-tile filter-tile fade-in group relative overflow-hidden border <?= $isActive ? 'border-[var(--navy)] ring-1 ring-[var(--navy)]' : 'border-[var(--border)]' ?> bg-[var(--card)] flex flex-col justify-between no-underline transition hover:-translate-y-0.5 hover:shadow-lg"
                    data-base="applications.php?status=<?= $key ?>"
                    style="animation-delay:<?= $delay ?>s">
                    <span class="absolute inset-y-0 left-0 w-1" style="background:<?= $sc['fg'] ?>"></span>
                    <span class="kpi-label flex items-center font-bold uppercase tracking-wide text-[var(--text3)]">
                        <i class="fas <?= $sc['icon'] ?>" style="color:<?= $sc['fg'] ?>"></i>
                        <span class="truncate"><?= htmlspecialchars($sc['label']) ?></span>
                    </span>
                    <span class="kpi-value font-extrabold text-[var(--text1)]"><?= $stats[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════════════════════════════════════
             APPLICATION LIST
        ══════════════════════════════════════════ -->
        <div id="empty-state" class="fade-in rounded-2xl border border-[var(--border)] bg-[var(--card)] py-10 sm:py-14 px-4 sm:px-6 text-center" style="animation-delay:.1s;<?= empty($applications) ? '' : 'display:none' ?>">
            <i class="fas fa-inbox text-3xl sm:text-4xl text-[var(--border)] block mb-3"></i>
            <p class="text-[13px] sm:text-sm font-bold text-[var(--text2)]">No applications found</p>
            <span class="text-[11px] sm:text-xs text-[var(--text3)]" id="empty-state-text">
                <?php if ($search_filter !== ''): ?>
                    No results for "<?= htmlspecialchars($search_filter) ?>"<?= $status_filter ? ' in "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?>. Try a different name, email, position, employer, RSF #, or country.
                <?php elseif ($status_filter === 'available'): ?>
                    Nothing waiting to be claimed right now — check back later.
                <?php elseif ($status_filter): ?>
                    No cases of yours with status "<?= htmlspecialchars(str_replace('_', ' ', $status_filter)) ?>".
                <?php else: ?>
                    You have no cases yet — check the Available tile to claim one.
                <?php endif; ?>
            </span>
        </div>

        <div id="results-wrapper" style="<?= empty($applications) ? 'display:none' : '' ?>">

            <!-- Desktop / tablet: real table, one row per application -->
            <div class="hidden md:block rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden fade-in" style="animation-delay:.1s">
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full min-w-[1100px] border-collapse text-sm">
                        <thead>
                            <tr class="bg-[var(--surface)] text-[10px] font-bold uppercase tracking-wide text-[var(--text3)]">
                                <th class="text-left px-5 py-3">Applicant</th>
                                <th class="text-left px-5 py-3">Position &amp; Employer</th>
                                <th class="text-left px-5 py-3">RSF / Country</th>
                                <th class="text-left px-5 py-3">Status</th>
                                <th class="text-left px-5 py-3">Updated</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody id="app-tbody">
                            <?php foreach ($applications as $app):
                                $status     = strtolower($app['status'] ?? 'unknown');
                                $statusText = str_replace('_', ' ', ucwords($status));
                                $sc         = status_color($status, $status_style);
                                $isLocked   = in_array($status, $locked_statuses, true);

                                $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                                $avi = strtoupper(count($np) >= 2
                                    ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                                    : substr($app['applicant_name'] ?? 'NA', 0, 2));
                            ?>
                                <tr class="border-t border-[var(--border)] cursor-pointer transition hover:bg-[var(--surface)] <?= $isLocked ? 'row-locked' : '' ?>"
                                    onclick="openApplication(<?= (int)$app['id'] ?>, '<?= htmlspecialchars($status, ENT_QUOTES) ?>')">
                                    <?php
                                    $app_has_pic = !empty($app['applicant_picture']);
                                    $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                                    ?>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] font-extrabold text-[var(--indigo)]"
                                                <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                                                <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="truncate text-[13px] font-bold text-[var(--text1)]"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                                                <div class="truncate text-[11px] font-medium text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-[12.5px] font-semibold text-[var(--text1)]"><?= htmlspecialchars($app['position_title'] ?? '—') ?></div>
                                        <div class="text-[11px] text-[var(--text3)]"><?= htmlspecialchars($app['employer_name'] ?? '—') ?></div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-[12.5px] font-bold text-[var(--blue)]"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></div>
                                        <div class="text-[11px] text-[var(--text3)]"><?= htmlspecialchars($app['country'] ?? '—') ?></div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="status-badge inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9.5px] font-bold uppercase tracking-wide"
                                            data-status="<?= htmlspecialchars($status) ?>"
                                            style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;">
                                            <i class="fas <?= $sc['icon'] ?>"></i><?= htmlspecialchars($statusText) ?>
                                        </span>
                                        <?php if ($isLocked): ?>
                                            <i class="fas fa-lock text-[10px] text-[var(--text3)] ml-1.5" title="Locked"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-[11.5px] text-[var(--text3)]">
                                        <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                    </td>
                                    <td class="px-5 py-3 text-right text-[var(--text3)]">
                                        <i class="fas <?= $isLocked ? 'fa-lock' : 'fa-chevron-right' ?> text-xs"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Mobile: stacked cards -->
            <div class="md:hidden flex flex-col gap-2 sm:gap-2.5" id="app-cards">
                <?php foreach ($applications as $i => $app):
                    $status     = strtolower($app['status'] ?? 'unknown');
                    $statusText = str_replace('_', ' ', ucwords($status));
                    $sc         = status_color($status, $status_style);
                    $isLocked   = in_array($status, $locked_statuses, true);

                    $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                    $avi = strtoupper(count($np) >= 2
                        ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                        : substr($app['applicant_name'] ?? 'NA', 0, 2));

                    $delay = .1 + min($i, 6) * .03;
                ?>
                    <a href="javascript:void(0)"
                        onclick="return openApplication(<?= (int)$app['id'] ?>, '<?= htmlspecialchars($status, ENT_QUOTES) ?>')"
                        class="fade-in flex items-center gap-2.5 sm:gap-3 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3 sm:p-3.5 no-underline text-inherit transition hover:-translate-y-0.5 hover:shadow-md <?= $isLocked ? 'row-locked' : '' ?>"
                        style="animation-delay:<?= $delay ?>s">

                        <?php
                        $app_has_pic = !empty($app['applicant_picture']);
                        $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                        ?>
                        <div class="flex h-10 w-10 sm:h-11 sm:w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] sm:text-xs font-extrabold text-[var(--indigo)]"
                            <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                            <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[13px] sm:text-[13.5px] font-bold text-[var(--text1)]"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                            <div class="truncate text-[10.5px] sm:text-[11px] font-medium text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                            <div class="mt-1 sm:mt-1.5 flex flex-wrap items-center gap-x-2 sm:gap-x-3 gap-y-1 text-[9.5px] sm:text-[10.5px] font-medium text-[var(--text3)]">
                                <span class="font-bold text-[var(--blue)]"><i class="fas fa-hashtag text-[8px] sm:text-[9px] mr-1"></i><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></span>
                                <span><i class="fas fa-briefcase text-[8px] sm:text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['position_title'] ?? '—') ?></span>
                                <span class="hidden xs:inline"><i class="fas fa-building text-[8px] sm:text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['employer_name'] ?? '—') ?></span>
                                <span><i class="fas fa-earth-asia text-[8px] sm:text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['country'] ?? '—') ?></span>
                            </div>
                        </div>

                        <div class="flex flex-shrink-0 flex-col items-end gap-1 sm:gap-1.5">
                            <span class="status-badge inline-flex items-center gap-1 rounded-full px-2 sm:px-2.5 py-0.5 sm:py-1 text-[8px] sm:text-[9px] font-bold uppercase tracking-wide"
                                data-status="<?= htmlspecialchars($status) ?>"
                                style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;">
                                <i class="fas <?= $isLocked ? 'fa-lock' : $sc['icon'] ?>"></i><span class="hidden xs:inline"><?= htmlspecialchars($statusText) ?></span>
                            </span>
                            <span class="text-[9px] sm:text-[10px] font-medium text-[var(--text3)]">
                                <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

        </div>

        <?php if ($total_pages > 1 || $show_all): ?>
            <div id="pagination-wrap" class="flex items-center justify-between mt-4 sm:mt-5 px-1 flex-wrap gap-2">
                <span class="text-[10.5px] sm:text-[11.5px] font-medium text-[var(--text3)]" id="pagination-page-label">
                    <?= $show_all ? 'Showing all ' . $total_filtered . ' result' . ($total_filtered !== 1 ? 's' : '') : 'Page ' . $page . ' of ' . $total_pages ?>
                </span>
                <div class="flex items-center gap-1 sm:gap-1.5" id="pagination-links">
                    <?php if (!$show_all): ?>
                        <a href="javascript:void(0)" onclick="goToPage(<?= max(1, $page - 1) ?>)"
                            class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]' ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="javascript:void(0)" onclick="goToPage(<?= $p ?>)"
                                class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition <?= $p === $page ? 'bg-[var(--navy)] text-white [[data-theme=dark]_&]:!bg-blue-600 [[data-theme=dark]_&]:!text-white' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>
                        <a href="javascript:void(0)" onclick="goToPage(<?= min($total_pages, $page + 1) ?>)"
                            class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]' ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                    <button type="button" id="view-all-btn" onclick="toggleViewAll()"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] sm:text-xs font-semibold transition bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]">
                        <i class="fas <?= $show_all ? 'fa-table-list' : 'fa-list-ul' ?>"></i>
                        <?= $show_all ? 'Back to Pages' : 'View All Applications' ?>
                    </button>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- ══════════════════════════════════════════
         FLOATING DOCK (unchanged)
    ══════════════════════════════════════════ -->
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

        // Statuses that are locked from the evaluator's side — mirrors the
        // PHP $locked_statuses array above. Kept in sync manually since one
        // lives server-side (for query/render logic) and one client-side
        // (for the click gate).
        const LOCKED_STATUSES = ['for_compliance', 'resubmitted', 'approved', 'disapproved'];
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
            window.location = 'view-application.php?id=' + id;
            return false;
        }

        // ══════════════════════════════════════════
        // LIVE SEARCH — filters the list as you type,
        // no page reload. Mirrors the PHP status_style /
        // locked_statuses tables above so rendered rows
        // look identical to the server-rendered ones.
        // ══════════════════════════════════════════


        const STATUS_STYLE = {
            available: {
                bg: '#d1fae5',
                fg: '#065f46',
                icon: 'fa-inbox',
                label: 'Open for Evaluation'
            },
            pending: {
                bg: '#dbeafe',
                fg: '#1e40af',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            assign: {
                bg: '#dbeafe',
                fg: '#1e40af',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            assigned: {
                bg: '#dbeafe',
                fg: '#1e40af',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            under_evaluation: {
                bg: '#e0e7ff',
                fg: '#3730a3',
                icon: 'fa-magnifying-glass',
                label: 'Under Evaluation'
            },
            for_compliance: {
                bg: '#ffedd5',
                fg: '#92400e',
                icon: 'fa-triangle-exclamation',
                label: 'For Compliance'
            },
            approved: {
                bg: '#d1fae5',
                fg: '#065f46',
                icon: 'fa-circle-check',
                label: 'Approved'
            },
            disapproved: {
                bg: '#fee2e2',
                fg: '#991b1b',
                icon: 'fa-circle-xmark',
                label: 'Disapproved'
            },
            resubmitted: {
                bg: '#fef3c7',
                fg: '#92400e',
                icon: 'fa-rotate-right',
                label: 'Resubmitted'
            },
            default: {
                bg: '#f1f5f9',
                fg: '#475569',
                icon: 'fa-circle',
                label: 'Unknown'
            },
        };
        const statusStyle = (key) => STATUS_STYLE[key] || STATUS_STYLE.default;

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
            const isLocked = LOCKED_STATUSES.includes(status);
            const avi = avatarInitials(app.applicant_name);
            const hasPic = !!app.applicant_picture;
            const picUrl = hasPic ? '../' + app.applicant_picture : '';
            const avatarStyle = hasPic ? `style="background-image:url('${picUrl}');background-size:cover;background-position:center;"` : '';

            return `
                <tr class="border-t border-[var(--border)] cursor-pointer transition hover:bg-[var(--surface)] ${isLocked ? 'row-locked' : ''}"
                    onclick="openApplication(${app.id}, '${status}')">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] font-extrabold text-[var(--indigo)]" ${avatarStyle}>${hasPic ? '' : escapeHtml(avi)}</div>
                            <div class="min-w-0">
                                <div class="truncate text-[13px] font-bold text-[var(--text1)]">${escapeHtml(app.applicant_name || '—')}</div>
                                <div class="truncate text-[11px] font-medium text-[var(--text3)]">${escapeHtml(app.applicant_email || '')}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3">
                        <div class="text-[12.5px] font-semibold text-[var(--text1)]">${escapeHtml(app.position_title || '—')}</div>
                        <div class="text-[11px] text-[var(--text3)]">${escapeHtml(app.employer_name || '—')}</div>
                    </td>
                    <td class="px-5 py-3">
                        <div class="text-[12.5px] font-bold text-[var(--blue)]">${escapeHtml(app.rsf_number || '—')}</div>
                        <div class="text-[11px] text-[var(--text3)]">${escapeHtml(app.country || '—')}</div>
                    </td>
                    <td class="px-5 py-3">
                            <span class="status-badge inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9.5px] font-bold uppercase tracking-wide" data-status="${status}" style="background:${sc.bg};color:${sc.fg};">
                            <i class="fas ${sc.icon}"></i>${escapeHtml(statusText)}
                        </span>
                        ${isLocked ? '<i class="fas fa-lock text-[10px] text-[var(--text3)] ml-1.5" title="Locked"></i>' : ''}
                    </td>
                    <td class="px-5 py-3 text-[11.5px] text-[var(--text3)]">${formatDate(app.updated_at)}</td>
                    <td class="px-5 py-3 text-right text-[var(--text3)]">
                        <i class="fas ${isLocked ? 'fa-lock' : 'fa-chevron-right'} text-xs"></i>
                    </td>
                </tr>`;
        }

        function renderCard(app, i) {
            const status = (app.status || 'unknown').toLowerCase();
            const statusText = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const sc = statusStyle(status);
            const isLocked = LOCKED_STATUSES.includes(status);
            const avi = avatarInitials(app.applicant_name);
            const delay = (.1 + Math.min(i, 6) * .03).toFixed(2);
            const hasPic = !!app.applicant_picture;
            const picUrl = hasPic ? '../' + app.applicant_picture : '';
            const avatarStyle = hasPic ? `style="background-image:url('${picUrl}');background-size:cover;background-position:center;"` : '';

            return `
                <a href="javascript:void(0)"
                    onclick="return openApplication(${app.id}, '${status}')"
                    class="fade-in flex items-center gap-2.5 sm:gap-3 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3 sm:p-3.5 no-underline text-inherit transition hover:-translate-y-0.5 hover:shadow-md ${isLocked ? 'row-locked' : ''}"
                    style="animation-delay:${delay}s">
                    <div class="flex h-10 w-10 sm:h-11 sm:w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] sm:text-xs font-extrabold text-[var(--indigo)]" ${avatarStyle}>${hasPic ? '' : escapeHtml(avi)}</div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[13px] sm:text-[13.5px] font-bold text-[var(--text1)]">${escapeHtml(app.applicant_name || '—')}</div>
                        <div class="truncate text-[10.5px] sm:text-[11px] font-medium text-[var(--text3)]">${escapeHtml(app.applicant_email || '')}</div>
                        <div class="mt-1 sm:mt-1.5 flex flex-wrap items-center gap-x-2 sm:gap-x-3 gap-y-1 text-[9.5px] sm:text-[10.5px] font-medium text-[var(--text3)]">
                            <span class="font-bold text-[var(--blue)]"><i class="fas fa-hashtag text-[8px] sm:text-[9px] mr-1"></i>${escapeHtml(app.rsf_number || '—')}</span>
                            <span><i class="fas fa-briefcase text-[8px] sm:text-[9px] mr-1 opacity-70"></i>${escapeHtml(app.position_title || '—')}</span>
                            <span class="hidden xs:inline"><i class="fas fa-building text-[8px] sm:text-[9px] mr-1 opacity-70"></i>${escapeHtml(app.employer_name || '—')}</span>
                            <span><i class="fas fa-earth-asia text-[8px] sm:text-[9px] mr-1 opacity-70"></i>${escapeHtml(app.country || '—')}</span>
                        </div>
                    </div>
                    <div class="flex flex-shrink-0 flex-col items-end gap-1 sm:gap-1.5">
                        <span class="status-badge inline-flex items-center gap-1 sm:px-2.5 py-0.5 sm:py-1 text-[8px] sm:text-[9px] font-bold uppercase tracking-wide" data-status="${status}" style="background:${sc.bg};color:${sc.fg};">
                            <i class="fas ${isLocked ? 'fa-lock' : sc.icon}"></i><span class="hidden xs:inline">${escapeHtml(statusText)}</span>
                        </span>
                        <span class="text-[9px] sm:text-[10px] font-medium text-[var(--text3)]">${formatDate(app.updated_at)}</span>
                    </div>
                </a>`;
        }
        const CURRENT_MONTH = <?= json_encode($month_filter) ?>;
        const CURRENT_COUNTRY = <?= json_encode($country_filter) ?>;
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
                linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${Math.max(1, page - 1)})" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition ${page <= 1 ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]'}"><i class="fas fa-chevron-left"></i></a>`;
                for (let p = 1; p <= totalPages; p++) {
                    linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${p})" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition ${p === page ? 'bg-[var(--navy)] text-white [[data-theme=dark]_&]:!bg-blue-600 [[data-theme=dark]_&]:!text-white' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]'}">${p}</a>`;
                }
                linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${Math.min(totalPages, page + 1)})" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition ${page >= totalPages ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]'}"><i class="fas fa-chevron-right"></i></a>`;
            }
            linksHtml += `<button type="button" id="view-all-btn" onclick="toggleViewAll()" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] sm:text-xs font-semibold transition bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]"><i class="fas ${SHOW_ALL ? 'fa-table-list' : 'fa-list-ul'}"></i> ${SHOW_ALL ? 'Back to Pages' : 'View All Applications'}</button>`;

            const label = SHOW_ALL ?
                `Showing all ${lastTotalCount} result${lastTotalCount !== 1 ? 's' : ''}` :
                `Page ${page} of ${totalPages}`;

            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'pagination-wrap';
                wrap.className = 'flex items-center justify-between mt-4 sm:mt-5 px-1 flex-wrap gap-2';
                resultsWrap.insertAdjacentElement('afterend', wrap);
            }
            wrap.innerHTML = `<span class="text-[10.5px] sm:text-[11.5px] font-medium text-[var(--text3)]" id="pagination-page-label">${label}</span><div class="flex items-center gap-1 sm:gap-1.5" id="pagination-links">${linksHtml}</div>`;
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

        // Keep the status filter tiles' hrefs carrying the current search
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

        function filterByStatus(status, el) {
            CURRENT_STATUS = status;
            CURRENT_PAGE = 1;

            // Toggle the active/highlighted border on tiles
            document.querySelectorAll('.filter-tile').forEach(a => {
                a.classList.remove('border-[var(--navy)]', 'ring-1', 'ring-[var(--navy)]');
                a.classList.add('border-[var(--border)]');
            });
            el.classList.remove('border-[var(--border)]');
            el.classList.add('border-[var(--navy)]', 'ring-1', 'ring-[var(--navy)]');

            // Update the page heading text
            const heading = document.getElementById('page-heading-text');
            if (heading) heading.textContent = statusToTitle(status);

            // Keep the URL shareable/bookmarkable without reloading the page
            const params = new URLSearchParams();
            if (status) params.set('status', status);
            const term = searchInput ? searchInput.value.trim() : '';
            if (term) params.set('q', term);
            if (CURRENT_MONTH) params.set('month', CURRENT_MONTH);
            if (CURRENT_COUNTRY) params.set('country', CURRENT_COUNTRY);
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
            if (month) params.set('month', month);
            if (country) params.set('country', country);
            if (SHOW_ALL) params.set('all', '1');

            window.location.href = 'applications.php' + (params.toString() ? '?' + params.toString() : '');
        }
        document.getElementById('filter-month')?.addEventListener('change', navigateWithSubbarFilters);
        document.getElementById('filter-country')?.addEventListener('change', navigateWithSubbarFilters);

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

    <?php if ($flash_locked): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'Application Locked',
                    text: <?= json_encode($flash_locked) ?>,
                    confirmButtonColor: '#1e40af',
                    confirmButtonText: 'Understood'
                });
            });
        </script>
    <?php endif; ?>

    <?php if ($flash_success || $flash_locked): ?>
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
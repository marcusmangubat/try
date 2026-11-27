<?php

/**
 * reports.php  –  DMW RO IV-A Admin Reports
 * Printable + Excel-exportable report suite.
 * Opened from dashboard.php's "Print / Export PDF" button, carrying
 * across whatever month / province / job_type / country filters the
 * admin currently has selected on the dashboard subbar.
 *
 * UI refresh: Tailwind-based layout matching evaluator-reports.php
 * (responsive toolbar, tab grid, desktop tables + mobile cards).
 * All PHP data logic, queries, print/PDF/Excel JS are unchanged.
 */
session_start();
require_once '../db.php'; // gives us $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

// Admin name (who generated the report) — same lookup pattern as dashboard.php
$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

// Greeting + avatar initials — same logic as dashboard.php, needed by the shared header/subheader
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);

/*
   FILTERS — read straight from the querystring, same four filters as
   the dashboard subbar (month / province / job_type / country).
 */
$f_month    = trim($_GET['month'] ?? '');
$f_province = trim($_GET['province'] ?? '');
$f_jobtype  = trim($_GET['job_type'] ?? '');
$f_country  = trim($_GET['country'] ?? '');

function buildWhere(): array
{
    global $f_month, $f_province, $f_jobtype, $f_country;
    $where  = ["a.status <> 'draft'"]; // exclude draft applications from all reports
    $types  = '';
    $params = [];

    if ($f_month !== '' && preg_match('/^\d{4}-\d{2}$/', $f_month)) {
        $start = $f_month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $where[]  = 'a.submitted_at BETWEEN ? AND ?';
        $types   .= 'ss';
        $params[] = $start;
        $params[] = $end;
    }
    if ($f_province !== '') {
        $where[]  = 'c.province = ?';
        $types   .= 's';
        $params[] = $f_province;
    }
    if ($f_jobtype !== '') {
        $where[]  = 'j.position_title = ?';
        $types   .= 's';
        $params[] = $f_jobtype;
    }
    if ($f_country !== '') {
        $where[]  = 'j.worksite_country = ?';
        $types   .= 's';
        $params[] = $f_country;
    }
    return [$where, $types, $params];
}

function whereSql(array $where): string
{
    return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
}

function bindDynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') return;
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function q(mysqli $db, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error . ' — SQL: ' . $sql);
    }
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
}

function statusLabel(string $status): string
{
    $s = $status;
    if ($s === 'unassigned') $s = 'unassign';
    if ($s === 'assigned')   $s = 'assign';
    $labels = [
        'pending'          => 'Pending',
        'unassign'         => 'Unassigned',
        'assign'           => 'Assigned',
        'under_evaluation' => 'Under Evaluation',
        'for_compliance'   => 'For Compliance',
        'resubmitted'      => 'Resubmitted',
        'approved'         => 'Approved',
        'disapproved'      => 'Disapproved',
    ];
    return $labels[$s] ?? ucwords(str_replace('_', ' ', $s));
}

function fullName(array $row): string
{
    return trim(implode(' ', array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['suffix'] ?? '',
    ])));
}

// Option lists for the subheader's Job Type / Destination filter pills
// (reuses rsf_job_orders, the same source dashboard-api.php draws from)
$jobTypeOptions = [];
$res = $database->query("SELECT DISTINCT position_title FROM rsf_job_orders WHERE position_title IS NOT NULL AND position_title <> '' ORDER BY position_title");
while ($row = $res->fetch_assoc()) $jobTypeOptions[] = $row['position_title'];

$countryOptions = [];
$res = $database->query("SELECT DISTINCT worksite_country FROM rsf_job_orders WHERE worksite_country IS NOT NULL AND worksite_country <> '' ORDER BY worksite_country");
while ($row = $res->fetch_assoc()) $countryOptions[] = $row['worksite_country'];

// Dock "Apps" badge — same query as dashboard.php's floating dock
$badge_stmt = $database->prepare("
    SELECT COUNT(*) as total
    FROM applications
    WHERE status IN ('pending', 'for_compliance', 'resubmitted')
");
$badge_stmt->execute();
$active_apps = $badge_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$badge_stmt->close();

[$where, $types, $params] = buildWhere();
$whereSql = whereSql($where);
$baseFrom = "FROM applications a
             JOIN clients c ON c.cid = a.client_id
             JOIN rsf_job_orders j ON j.id = a.rsf_id";

/* REPORT 1 — Applicants by Gender */
$genderSummary = [];
$res = q($database, "
    SELECT COALESCE(NULLIF(TRIM(c.gender), ''), 'Not Specified') AS gender,
           COUNT(DISTINCT c.cid) AS applicants,
           COUNT(a.id) AS applications,
           SUM(a.status = 'approved') AS approved,
           SUM(a.status = 'disapproved') AS disapproved,
           SUM(a.status NOT IN ('approved','disapproved')) AS in_process
    $baseFrom
    $whereSql
    GROUP BY gender
    ORDER BY gender
", $types, $params);
while ($row = $res->fetch_assoc()) $genderSummary[] = $row;

$genderDetail = [];
$res = q($database, "
    SELECT a.id, c.first_name, c.middle_name, c.last_name, c.suffix,
           COALESCE(NULLIF(TRIM(c.gender), ''), 'Not Specified') AS gender,
           c.province, c.city_municipality,
           j.worksite_country AS country, j.position_title,
           a.status, a.submitted_at
    $baseFrom
    $whereSql
    ORDER BY gender ASC, c.last_name ASC
", $types, $params);
while ($row = $res->fetch_assoc()) $genderDetail[] = $row;

/* REPORT 2 — Approved Applicants by Country */
$whereApproved = whereSql(array_merge($where, ["a.status = 'approved'"]));

$countrySummary = [];
$res = q($database, "
    SELECT j.worksite_country AS country, COUNT(*) AS approved_count
    $baseFrom
    $whereApproved
    GROUP BY country
    ORDER BY approved_count DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $countrySummary[] = $row;

$countryDetail = [];
$res = q($database, "
    SELECT a.id, c.first_name, c.middle_name, c.last_name, c.suffix,
           COALESCE(NULLIF(TRIM(c.gender), ''), 'Not Specified') AS gender,
           c.province, c.city_municipality,
           j.worksite_country AS country, j.position_title, j.employer_name,
           a.updated_at AS approved_at
    $baseFrom
    $whereApproved
    ORDER BY country ASC, c.last_name ASC
", $types, $params);
while ($row = $res->fetch_assoc()) $countryDetail[] = $row;

/* REPORT 3 — Application Status Summary */
$statusCounts = [];
$res = q($database, "
    SELECT a.status, COUNT(*) AS cnt
    $baseFrom
    $whereSql
    GROUP BY a.status
", $types, $params);
while ($row = $res->fetch_assoc()) {
    $s = $row['status'];
    if ($s === 'unassigned') $s = 'unassign';
    if ($s === 'assigned')   $s = 'assign';
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + (int) $row['cnt'];
}
$statusOrder = ['pending', 'unassign', 'assign', 'under_evaluation', 'for_compliance', 'resubmitted', 'approved', 'disapproved'];
$statusTotal = array_sum($statusCounts) ?: 1;
$statusSummary = [];
foreach ($statusOrder as $s) {
    $c = $statusCounts[$s] ?? 0;
    $statusSummary[] = [
        'status' => statusLabel($s),
        'count'  => $c,
        'pct'    => round($c / $statusTotal * 100, 1),
    ];
}

/* REPORT 4 — Applicants by Province / City of Origin */
$provinceSummary = [];
$res = q($database, "
    SELECT c.province, c.city_municipality,
           COUNT(DISTINCT c.cid) AS applicants,
           COUNT(a.id) AS applications,
           SUM(a.status = 'approved') AS approved
    $baseFrom
    $whereSql
    GROUP BY c.province, c.city_municipality
    ORDER BY applications DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $provinceSummary[] = $row;

/* REPORT 5 — Applicants by Age Group */
$ageSummary = [];
$res = q($database, "
    SELECT
        CASE
            WHEN c.dob IS NULL THEN 'Not Indicated'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) < 18 THEN 'Under 18'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 18 AND 24 THEN '18–24'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 25 AND 34 THEN '25–34'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 35 AND 44 THEN '35–44'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 45 AND 54 THEN '45–54'
            ELSE '55 and above'
        END AS age_group,
        COUNT(DISTINCT c.cid) AS applicants,
        COUNT(a.id) AS applications
    $baseFrom
    $whereSql
    GROUP BY age_group
    ORDER BY FIELD(age_group, 'Under 18','18–24','25–34','35–44','45–54','55 and above','Not Indicated')
", $types, $params);
while ($row = $res->fetch_assoc()) $ageSummary[] = $row;

/* REPORT 6 — Applicants by Educational Attainment */
$eduSummary = [];
$res = q($database, "
    SELECT COALESCE(cp.educational_attainment, 'Not Indicated') AS education,
           COUNT(DISTINCT c.cid) AS applicants,
           COUNT(a.id) AS applications,
           SUM(a.status = 'approved') AS approved
    $baseFrom
    LEFT JOIN client_profiles cp ON cp.client_id = c.cid
    $whereSql
    GROUP BY education
    ORDER BY applications DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $eduSummary[] = $row;

/* REPORT 7 — Job Order Slot Utilization */
$jobWhere  = [];
$jobTypes  = '';
$jobParams = [];
if ($f_jobtype !== '') {
    $jobWhere[] = 'j.position_title = ?';
    $jobTypes .= 's';
    $jobParams[] = $f_jobtype;
}
if ($f_country !== '') {
    $jobWhere[] = 'j.worksite_country = ?';
    $jobTypes .= 's';
    $jobParams[] = $f_country;
}
$jobWhereSql = whereSql($jobWhere);

$jobSummary = [];
$res = q($database, "
    SELECT j.rsf_number, j.position_title, j.employer_name, j.worksite_country,
           j.slots_available, j.deadline,
           COUNT(a.id) AS applied,
           SUM(a.status = 'approved') AS approved,
           GREATEST(j.slots_available - COALESCE(SUM(a.status = 'approved'), 0), 0) AS remaining
        FROM rsf_job_orders j
    LEFT JOIN applications a ON a.rsf_id = j.id AND a.status <> 'draft'
    $jobWhereSql
    GROUP BY j.id
    ORDER BY j.deadline ASC
", $jobTypes, $jobParams);
while ($row = $res->fetch_assoc()) $jobSummary[] = $row;

/* REPORT 8 — Evaluator Turnaround / Workload */
$evalWhere  = array_merge($where, ["e.decision IS NOT NULL"]);
$evalWhereSql = whereSql($evalWhere);

$evalSummary = [];
$res = q($database, "
    SELECT ev.full_name, ev.email,
           COUNT(e.id) AS actions,
           SUM(e.decision = 'approved') AS approved,
           SUM(e.decision = 'disapproved') AS disapproved,
           SUM(e.decision = 'for_compliance') AS for_compliance,
           AVG(CASE WHEN e.decision IN ('approved','disapproved')
                    THEN DATEDIFF(e.evaluated_at, a.submitted_at) END) AS avg_days
    FROM evaluations e
    JOIN evaluators ev ON ev.eid = e.evaluator_id
    JOIN applications a ON a.id = e.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $evalWhereSql
    GROUP BY ev.eid
    ORDER BY actions DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $evalSummary[] = $row;

/* REPORT 9 — Document Compliance */
$docCompliance = [];
$res = q($database, "
    SELECT a.id, a.rsf_id,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.employer_name,
           (SELECT COUNT(*) FROM rsf_required_documents rd
              WHERE rd.rsf_id = a.rsf_id AND rd.is_required = 1) AS required_count,
           (SELECT COUNT(DISTINCT rd2.document_type)
              FROM rsf_required_documents rd2
              JOIN application_documents ad
                ON ad.application_id = a.id AND ad.document_type = rd2.document_type
              WHERE rd2.rsf_id = a.rsf_id AND rd2.is_required = 1) AS submitted_count
    $baseFrom
    $whereSql
    ORDER BY a.submitted_at DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $docCompliance[] = $row;

// Attach completion % + missing document names per application.
$missingStmt = $database->prepare("
    SELECT rd.document_name
    FROM rsf_required_documents rd
    WHERE rd.rsf_id = ? AND rd.is_required = 1
      AND rd.document_type NOT IN (
          SELECT ad.document_type FROM application_documents ad WHERE ad.application_id = ?
      )
");
foreach ($docCompliance as &$d) {
    $required  = (int) $d['required_count'];
    $submitted = (int) $d['submitted_count'];
    $d['completion_pct'] = $required > 0 ? round(($submitted / $required) * 100) : 100;

    $rsfId = (int) $d['rsf_id'];
    $appId = (int) $d['id'];
    $missingStmt->bind_param('ii', $rsfId, $appId);
    $missingStmt->execute();
    $missingRes = $missingStmt->get_result();
    $names = [];
    while ($m = $missingRes->fetch_assoc()) $names[] = $m['document_name'];
    $d['missing_documents'] = $names ? implode(', ', $names) : '—';
}
unset($d);
$missingStmt->close();

$docCompleteCount   = count(array_filter($docCompliance, fn($d) => $d['completion_pct'] == 100));
$docIncompleteCount = count($docCompliance) - $docCompleteCount;
$docAvgCompletion   = $docCompliance ? round(array_sum(array_column($docCompliance, 'completion_pct')) / count($docCompliance)) : 0;

// Human-readable filter recap for the letterhead
$filterBits = [];
if ($f_month !== '')    $filterBits[] = 'Month: ' . date('F Y', strtotime($f_month . '-01'));
if ($f_province !== '') $filterBits[] = 'Province: ' . $f_province;
if ($f_jobtype !== '')  $filterBits[] = 'Job Type: ' . $f_jobtype;
if ($f_country !== '')  $filterBits[] = 'Destination: ' . $f_country;
$filterRecap = $filterBits ? implode('  •  ', $filterBits) : 'All records (no filters applied)';
$generatedAt = date('F j, Y g:i A');

$totalApps = (int) array_sum(array_column($statusSummary, 'count'));
$approvedCount = 0;
$disapprovedCount = 0;
foreach ($statusSummary as $_s) {
    if ($_s['status'] === 'Approved') $approvedCount = (int) $_s['count'];
    if ($_s['status'] === 'Disapproved') $disapprovedCount = (int) $_s['count'];
}
$inProcessCount = max(0, $totalApps - $approvedCount - $disapprovedCount);
$countryApprovedTotal = (int) array_sum(array_column($countrySummary, 'approved_count'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Reports – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Dark mode follows the project's existing [data-theme="dark"] attribute (set by theme.js / theme-init)
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]'],
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"DM Sans"', 'system-ui', 'sans-serif']
                    },
                    colors: {
                        navy: {
                            DEFAULT: '#0b1f4b',
                            soft: '#13295e'
                        }
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <!-- Reusable Tailwind components for this page (screen layout only) -->
    <style type="text/tailwindcss">
        @layer components {

            /* ---------- Buttons ---------- */
            .btn {
                @apply inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-slate-800 no-underline transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700;
            }

            .btn-primary {
                @apply border-navy bg-navy text-white hover:bg-navy-soft dark:border-blue-600 dark:bg-blue-600 dark:hover:bg-blue-500;
            }

            .btn-green {
                @apply border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700 dark:border-emerald-500 dark:bg-emerald-600 dark:hover:bg-emerald-500;
            }

            .btn-red {
                @apply border-red-700 bg-red-700 text-white hover:bg-red-800 dark:border-red-600 dark:bg-red-600 dark:hover:bg-red-500;
            }

            /* ---------- Tabs (.active toggled by existing JS) ---------- */
            .tab-btn {
                @apply rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-center text-xs font-semibold leading-snug text-slate-600 transition-colors hover:bg-slate-50 sm:shrink-0 sm:rounded-full sm:px-3.5 sm:py-2 sm:leading-normal dark:border-slate-700 dark:bg-slate-700/60 dark:text-slate-300;
            }

            .tab-btn.active {
                @apply border-navy bg-navy text-white dark:border-blue-500 dark:bg-blue-500;
            }

            /* ---------- Report sections (.active toggled by existing JS) ---------- */
            .report-section {
                @apply hidden;
            }

            .report-section.active {
                @apply block;
            }

            /* ---------- Summary cards ---------- */
            .summary-panel {
                @apply mb-5 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-[repeat(auto-fit,minmax(150px,1fr))];
            }

            .summary-cell {
                @apply rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-3 max-sm:last:odd:col-span-2 dark:border-slate-700 dark:bg-slate-800/60;
            }

            .sc-num {
                @apply text-xl font-extrabold leading-tight text-navy sm:text-2xl dark:text-blue-400;
            }

            .sc-lbl {
                @apply mt-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400;
            }

            .sc-warn .sc-num {
                @apply text-red-700 dark:text-red-400;
            }

            /* ---------- Section headings ---------- */
            .section {
                @apply mb-6;
            }

            .section-title {
                @apply mb-2.5 flex items-center gap-2 rounded bg-navy px-3 py-2 text-xs font-bold uppercase tracking-wider text-white dark:bg-blue-900 dark:text-slate-50;
            }

            .section-hint {
                @apply mb-3 text-xs italic leading-relaxed text-slate-400;
            }

            /* ---------- Badges ---------- */
            .badge {
                @apply inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-bold;
            }

            .b-approved {
                @apply bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400;
            }

            .b-disapproved {
                @apply bg-red-100 text-red-700 dark:bg-red-600/20 dark:text-red-400;
            }

            .b-pending {
                @apply bg-amber-100 text-amber-700 dark:bg-amber-600/20 dark:text-amber-300;
            }

            .b-other {
                @apply bg-indigo-50 text-navy dark:bg-indigo-500/20 dark:text-indigo-300;
            }

            /* ---------- Tables (desktop / tablet) ---------- */
            .report-table {
                @apply w-full border-collapse border border-slate-200 text-xs dark:border-slate-700;
            }

            .report-table th {
                @apply border border-slate-200 bg-slate-50 px-2.5 py-2 text-left text-[10.5px] font-bold uppercase tracking-wide text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400;
            }

            .report-table td {
                @apply border border-slate-200 px-2.5 py-2 align-top text-slate-800 dark:border-slate-700 dark:text-slate-200;
            }

            .report-table tbody tr:nth-child(even) {
                @apply bg-slate-50/70 dark:bg-slate-800/50;
            }

            .empty-row td,
            .empty-note {
                @apply px-2.5 py-3.5 text-center text-xs italic text-slate-400;
            }

            /* ---------- Mobile cards (shown below md) ---------- */
            .m-card {
                @apply rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm dark:border-slate-700 dark:bg-slate-800;
            }

            .m-row {
                @apply flex items-start justify-between gap-3 border-t border-slate-100 py-1.5 text-xs dark:border-slate-700;
            }

            .m-key {
                @apply shrink-0 text-slate-500 dark:text-slate-400;
            }

            .m-val {
                @apply min-w-0 break-words text-right font-medium text-slate-800 dark:text-slate-200;
            }
        }
    </style>

    <!-- Print / PDF engine + shared theme tokens.
         Plain CSS on purpose: the JS print/PDF/Excel code and ../css/dashboard.css depend on these
         exact selectors and variables, so output stays identical. -->
    <style>
        :root {
            --navy: #0b1f4b;
            --navy-soft: #13295e;
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
            --ok: #059669;
            --amber: #b45309;
            --indigo: #6366f1;
            --gold: #eab308;
            --red: #b91c1c;
            --text1: #1f2937;
            --text2: #4b5563;
            --text3: #9ca3af;
            --bdr: #e5e7eb;
            --lbl-bg: #f8fafc;
            --surface: #ffffff;
            --surface2: #f8fafc;
            --selected-row: #eff6ff;
        }

        [data-theme="dark"] {
            --navy: #3b82f6;
            --navy-soft: #1e3a8a;
            --ok: #34d399;
            --amber: #fbbf24;
            --red: #f87171;
            --text1: #e2e8f0;
            --text2: #94a3b8;
            --text3: #64748b;
            --bdr: #2d3748;
            --lbl-bg: #1e293b;
            --surface: #1e293b;
            --surface2: #263449;
            --selected-row: rgba(59, 130, 246, .22);
        }

        #printReport {
            display: none;
            background: #fff;
            color: #1f2937;
            font-family: 'DM Sans', Arial, Helvetica, sans-serif;
            width: 100%;
            max-width: 190mm;
            margin: 0 auto;
            padding: 0;
        }

        #printReport .pr-page-header {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 3px solid #0b1f4b;
            padding-bottom: 10px;
            margin-bottom: 6px;
        }

        #printReport .pr-page-header img {
            height: 42px;
            width: auto;
        }

        #printReport .pr-republic {
            font-size: 8.5pt;
            color: #4b5563;
            margin: 0 0 1px;
        }

        #printReport .pr-agency {
            font-size: 12.5pt;
            font-weight: 800;
            color: #0b1f4b;
            letter-spacing: .01em;
        }

        #printReport .pr-office {
            font-size: 8.7pt;
            color: #4b5563;
            margin-top: 2px;
            line-height: 1.5;
        }

        #printReport .pr-report-title {
            text-align: center;
            margin: 14px 0 6px;
        }

        #printReport .pr-report-title h1 {
            font-family: 'DM Sans', Arial, sans-serif;
            font-weight: 800;
            font-size: 14pt;
            margin: 0;
            color: #0b1f4b;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        #printReport .pr-report-title p {
            font-size: 9.5pt;
            color: #6b7280;
            margin: 3px 0 0;
        }

        #printReport .pr-meta-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 8.5pt;
            color: #4b5563;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }

        #printReport .pr-intro {
            font-size: 9pt;
            line-height: 1.6;
            color: #374151;
            text-align: justify;
            margin: 0 0 12px;
        }

        #printReport .pr-summary-panel {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            table-layout: fixed;
        }

        #printReport .pr-summary-panel td {
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            text-align: center;
            padding: 8px 4px;
        }

        #printReport .pr-summary-panel .sc-num {
            font-size: 13pt;
            font-weight: 800;
            color: #0b1f4b;
            display: block;
        }

        #printReport .pr-summary-panel .sc-lbl {
            font-size: 6.7pt;
            font-weight: 700;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: .02em;
            display: block;
            margin-top: 2px;
        }

        #printReport .pr-section-hint {
            font-size: 7.8pt;
            color: #9ca3af;
            font-style: italic;
            margin: -5px 0 6px;
        }

        #printReport .pr-section {
            margin-bottom: 16px;
            page-break-inside: avoid;
        }

        #printReport .pr-section-title {
            font-size: 10.5pt;
            font-weight: 700;
            color: #fff;
            background: #0b1f4b;
            padding: 5px 9px;
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        #printReport table.pr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-top: 4px;
        }

        #printReport table.pr-table th {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            text-align: left;
            padding: 6px 8px;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: #374151;
        }

        #printReport table.pr-table td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
        }

        #printReport table.pr-table thead {
            display: table-header-group;
        }

        #printReport table.pr-table tr {
            page-break-inside: avoid;
        }

        #printReport table.pr-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        #printReport .pr-empty {
            font-size: 8.5pt;
            color: #9ca3af;
            font-style: italic;
            padding: 4px 0;
        }

        #printReport .pr-badge-ok {
            color: #047857;
            font-weight: 700;
        }

        #printReport .pr-badge-bad {
            color: #b91c1c;
            font-weight: 700;
        }

        #printReport .pr-signoff {
            margin-top: 46px;
            display: flex;
            justify-content: space-between;
            font-size: 8.5pt;
            page-break-inside: avoid;
        }

        #printReport .pr-signoff .pr-sig-line {
            width: 42%;
            text-align: center;
        }

        #printReport .pr-signoff .pr-sig-line .line {
            border-top: 1px solid #1f2937;
            margin-top: 34px;
            padding-top: 4px;
            color: #374151;
        }

        #printReport .pr-footer {
            margin-top: 22px;
            padding-top: 8px;
            border-top: 1px solid #9ca3af;
        }

        #printReport .pr-footer-columns {
            display: flex;
            align-items: stretch;
            gap: 16px;
        }

        #printReport .pr-footer-left {
            flex: 1;
        }

        #printReport .pr-footer-disclaimer {
            font-size: 7.5pt;
            color: #6b7280;
            line-height: 1.5;
            margin: 0;
        }

        #printReport .pr-footer-right {
            flex: 0 0 34%;
            border-left: 1px solid #d1d5db;
            padding-left: 14px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
            font-size: 7.3pt;
            color: #6b7280;
        }

        #printReport .pr-footer-right span:first-child {
            color: #374151;
        }

        @media print {

            .no-print,
            .webView {
                display: none !important;
            }

            #printReport {
                display: block !important;
            }

            body:not(.print-all) #printReport .pr-report-block {
                display: none;
            }

            body:not(.print-all) #printReport .pr-report-block.active {
                display: block;
            }

            body.print-all #printReport .pr-report-block {
                display: block;
                page-break-after: always;
            }

            body.print-all #printReport .pr-report-block:last-of-type {
                page-break-after: auto;
            }

            @page {
                size: A4;
                margin: 14mm 12mm;
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

<body class="bg-[#eef1f6] font-sans text-slate-800 antialiased print:bg-white dark:bg-slate-900 dark:text-slate-200">

    <header class="topbar no-print">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="!block !h-auto !w-[42px] !bg-transparent !filter-none">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
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

    <div class="subbar no-print">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($admin_name) ?></h2>
                <p>Filter the report below, then use the toolbar to print or export</p>
            </div>
        </div>
        <form class="filter-scroll" method="GET" action="reports.php">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select name="month" class="cursor-pointer border-none bg-transparent text-xs font-semibold text-inherit outline-none">
                    <option value="">All Months</option>
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                        $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
                        $sel = ($val === $f_month) ? 'selected' : '';
                        echo "<option value=\"$val\" $sel>$lbl</option>\n";
                    }
                    ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
                <select name="province" class="cursor-pointer border-none bg-transparent text-xs font-semibold text-inherit outline-none">
                    <option value="">CALABARZON</option>
                    <?php foreach (['Laguna', 'Batangas', 'Cavite', 'Rizal', 'Quezon'] as $prov): ?>
                        <option value="<?= htmlspecialchars($prov) ?>" <?= $prov === $f_province ? 'selected' : '' ?>><?= htmlspecialchars($prov) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select name="job_type" class="cursor-pointer border-none bg-transparent text-xs font-semibold text-inherit outline-none">
                    <option value="">All Job Types</option>
                    <?php foreach ($jobTypeOptions as $jt): ?>
                        <option value="<?= htmlspecialchars($jt) ?>" <?= $jt === $f_jobtype ? 'selected' : '' ?>><?= htmlspecialchars($jt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select name="country" class="cursor-pointer border-none bg-transparent text-xs font-semibold text-inherit outline-none">
                    <option value="">All Destinations</option>
                    <?php foreach ($countryOptions as $ctry): ?>
                        <option value="<?= htmlspecialchars($ctry) ?>" <?= $ctry === $f_country ? 'selected' : '' ?>><?= htmlspecialchars($ctry) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="filter-pill cursor-pointer border-none">
                <i class="fas fa-filter" style="color:var(--blue-lt)"></i>
                <span class="text-[11px] font-semibold">Apply Filters</span>
            </button>
            <?php if ($f_month !== '' || $f_province !== '' || $f_jobtype !== '' || $f_country !== ''): ?>
                <a href="reports.php" class="filter-pill no-underline">
                    <i class="fas fa-xmark" style="color:var(--red)"></i>
                    <span class="text-[11px] font-semibold">Clear</span>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="mx-auto my-4 max-w-[1000px] px-3 pb-28 sm:my-5 sm:px-4 print:m-0 print:max-w-full print:p-0">

        <!-- Toolbar: grouped 3-up grid on mobile, single wrapping row from sm up -->
        <div class="no-print mb-4 grid grid-cols-1 gap-3 sm:flex sm:flex-wrap sm:gap-2">
            <a href="dashboard.php" class="btn w-full sm:w-auto"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

            <div class="grid grid-cols-3 gap-2 sm:contents">
                <p class="col-span-3 -mb-1 text-[11px] font-bold uppercase tracking-wide text-slate-500 sm:hidden dark:text-slate-400">This report</p>
                <button class="btn max-sm:flex-col max-sm:gap-1 max-sm:text-[11px]" onclick="printCurrent()">
                    <i class="fas fa-print"></i>
                    <span class="sm:hidden">Print</span><span class="hidden sm:inline">Print This Report</span>
                </button>
                <button class="btn btn-green max-sm:flex-col max-sm:gap-1 max-sm:text-[11px]" onclick="exportCurrent()">
                    <i class="fas fa-file-excel"></i>
                    <span class="sm:hidden">Excel</span><span class="hidden sm:inline">Export to Excel</span>
                </button>
                <button class="btn btn-red max-sm:flex-col max-sm:gap-1 max-sm:text-[11px]" id="exportPdfBtn" onclick="downloadCurrentPDF(this)">
                    <i class="fas fa-file-pdf"></i>
                    <span class="sm:hidden">PDF</span><span class="hidden sm:inline">Download PDF</span>
                </button>
            </div>

            <div class="grid grid-cols-3 gap-2 sm:contents">
                <p class="col-span-3 -mb-1 text-[11px] font-bold uppercase tracking-wide text-slate-500 sm:hidden dark:text-slate-400">All reports</p>
                <button class="btn btn-primary max-sm:flex-col max-sm:gap-1 max-sm:text-[11px]" onclick="printAll()">
                    <i class="fas fa-print"></i>
                    <span class="sm:hidden">Print</span><span class="hidden sm:inline">Print All Reports</span>
                </button>
                <button class="btn btn-primary max-sm:flex-col max-sm:gap-1 max-sm:text-[11px]" onclick="exportAll()">
                    <i class="fas fa-file-excel"></i>
                    <span class="sm:hidden">Excel</span><span class="hidden sm:inline">Export All (Excel)</span>
                </button>
                <button class="btn btn-red max-sm:flex-col max-sm:gap-1 max-sm:text-[11px]" onclick="downloadAllPDF(this)">
                    <i class="fas fa-file-pdf"></i>
                    <span class="sm:hidden">PDF</span><span class="hidden sm:inline">Download All (PDF)</span>
                </button>
            </div>
        </div>

        <!-- Tabs: 2-col grid on mobile, pill row from sm up -->
        <div class="no-print mb-4 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" id="tabs">
            <button class="tab-btn active" data-target="rep-country">Approved by Country</button>
            <button class="tab-btn" data-target="rep-status">Status Summary</button>
            <button class="tab-btn" data-target="rep-province">Origin (Province/City)</button>
            <button class="tab-btn" data-target="rep-age">Age Group</button>
            <button class="tab-btn" data-target="rep-edu">Educational Attainment</button>
            <button class="tab-btn" data-target="rep-jobs">Job Order Utilization</button>
            <button class="tab-btn" data-target="rep-evaluators">Evaluator Turnaround</button>
            <button class="tab-btn" data-target="rep-doccompliance">Document Compliance</button>
        </div>

        <div class="webView">
            <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-8 dark:border-slate-700 dark:bg-slate-800">

                <div class="mb-1 flex items-center gap-3 border-b-[3px] border-navy pb-3 sm:gap-4 dark:border-blue-500">
                    <img src="../pictures/dmw_logo.png" alt="DMW logo" class="h-auto w-10 shrink-0 sm:w-12" onerror="this.style.visibility='hidden';">
                    <div class="min-w-0">
                        <p class="m-0 text-[10px] text-slate-600 sm:text-[10.5px] dark:text-slate-400">Republic of the Philippines</p>
                        <p class="my-0.5 text-sm font-extrabold leading-tight text-navy sm:text-base dark:text-blue-400">DEPARTMENT OF MIGRANT WORKERS</p>
                        <p class="m-0 text-[10px] leading-relaxed text-slate-600 sm:text-[10.5px] dark:text-slate-400">
                            Regional Office IV-A (CALABARZON)<br>
                            Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                            <span class="break-words">Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph</span>
                        </p>
                    </div>
                </div>

                <h2 class="mb-1 mt-5 text-center text-base font-extrabold uppercase tracking-wide text-navy sm:text-lg dark:text-blue-400">Migrant Worker Application Reports</h2>
                <p class="mb-4 text-center text-xs text-slate-600 dark:text-slate-400"><?= htmlspecialchars($filterRecap) ?></p>

                <table class="mb-5 w-full border-collapse text-xs">
                    <tr>
                        <td class="w-24 py-0.5 align-top font-bold sm:w-40">Generated:</td>
                        <td class="py-0.5"><?= htmlspecialchars($generatedAt) ?></td>
                    </tr>
                    <tr>
                        <td class="w-24 py-0.5 align-top font-bold sm:w-40">Prepared by:</td>
                        <td class="py-0.5"><?= htmlspecialchars($admin_name) ?></td>
                    </tr>
                    <tr>
                        <td class="w-24 py-0.5 align-top font-bold sm:w-40">Office:</td>
                        <td class="py-0.5">DMW Regional Office IV-A (CALABARZON)</td>
                    </tr>
                </table>

                <p class="mb-4 text-[12.5px] leading-relaxed text-slate-600 sm:text-justify dark:text-slate-400">
                    This report consolidates migrant worker application statistics processed through the DMW Application
                    Management System for Regional Office IV-A. Figures reflect the filters selected above and are intended
                    for management review, audit, outreach planning, and official case monitoring.
                </p>

                <div class="summary-panel">
                    <div class="summary-cell">
                        <div class="sc-num"><?= $totalApps ?></div>
                        <div class="sc-lbl">Applications</div>
                    </div>
                    <div class="summary-cell">
                        <div class="sc-num"><?= $approvedCount ?></div>
                        <div class="sc-lbl">Approved</div>
                    </div>
                    <div class="summary-cell <?= $disapprovedCount > 0 ? 'sc-warn' : '' ?>">
                        <div class="sc-num"><?= $disapprovedCount ?></div>
                        <div class="sc-lbl">Disapproved</div>
                    </div>
                    <div class="summary-cell">
                        <div class="sc-num"><?= $inProcessCount ?></div>
                        <div class="sc-lbl">In Process</div>
                    </div>
                    <div class="summary-cell <?= $docAvgCompletion < 100 ? 'sc-warn' : '' ?>">
                        <div class="sc-num"><?= $docAvgCompletion ?>%</div>
                        <div class="sc-lbl">Avg. Docs Complete</div>
                    </div>
                </div>

                <!-- ===================== I. APPROVED BY COUNTRY ===================== -->
                <section class="report-section active" id="rep-country">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-globe"></i> I. Approved Applicants by Country</div>
                        <p class="section-hint">Approved applications grouped by worksite/destination country. <?= $countryApprovedTotal ?> approved in the current filter set.</p>
                        <div class="no-print mx-auto mb-5 h-64 max-w-md sm:h-auto"><canvas id="chartCountry"></canvas></div>
                        <div class="overflow-x-auto">
                            <table class="report-table detail-table" id="tbl-country-summary">
                                <thead>
                                    <tr>
                                        <th>Destination Country</th>
                                        <th>Approved Applicants</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$countrySummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="2">No approved applications found for the selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($countrySummary as $c): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($c['country']) ?></td>
                                            <td><?= (int)$c['approved_count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-users"></i> Approved Applicant Detail</div>
                        <p class="section-hint">Individual approved applicants corresponding to the country summary above.</p>

                        <!-- Desktop / tablet table (also the Excel export source) -->
                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-country-detail">
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>Gender</th>
                                        <th>Origin</th>
                                        <th>Country</th>
                                        <th>Position</th>
                                        <th>Employer</th>
                                        <th>Approved On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$countryDetail): ?>
                                        <tr class="empty-row">
                                            <td colspan="7">No records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($countryDetail as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(fullName($r)) ?></td>
                                            <td><?= htmlspecialchars($r['gender']) ?></td>
                                            <td><?= htmlspecialchars($r['city_municipality'] . ', ' . $r['province']) ?></td>
                                            <td><?= htmlspecialchars($r['country']) ?></td>
                                            <td><?= htmlspecialchars($r['position_title']) ?></td>
                                            <td><?= htmlspecialchars($r['employer_name']) ?></td>
                                            <td><?= htmlspecialchars(date('M j, Y', strtotime($r['approved_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile cards -->
                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$countryDetail): ?>
                                <p class="empty-note">No records found.</p>
                            <?php endif; ?>
                            <?php foreach ($countryDetail as $r): ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-bold"><?= htmlspecialchars(fullName($r)) ?></p>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($r['gender']) ?></p>
                                        </div>
                                        <span class="badge b-approved">Approved</span>
                                    </div>
                                    <div class="m-row"><span class="m-key">Origin</span><span class="m-val"><?= htmlspecialchars($r['city_municipality'] . ', ' . $r['province']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Country</span><span class="m-val"><?= htmlspecialchars($r['country']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Position</span><span class="m-val"><?= htmlspecialchars($r['position_title']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Employer</span><span class="m-val"><?= htmlspecialchars($r['employer_name']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Approved On</span><span class="m-val"><?= htmlspecialchars(date('M j, Y', strtotime($r['approved_at']))) ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===================== II. STATUS SUMMARY ===================== -->
                <section class="report-section" id="rep-status">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-chart-pie"></i> II. Application Status Summary</div>
                        <p class="section-hint">Overall processing funnel — useful for management reporting and pipeline monitoring.</p>
                        <div class="overflow-x-auto">
                            <table class="report-table detail-table" id="tbl-status">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Count</th>
                                        <th>% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statusSummary as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($s['status']) ?></td>
                                            <td><?= (int)$s['count'] ?></td>
                                            <td><?= $s['pct'] ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- ===================== III. PROVINCE / CITY ===================== -->
                <section class="report-section" id="rep-province">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-map-location-dot"></i> III. Applicants by Province &amp; City of Origin</div>
                        <p class="section-hint">Where applicants are coming from within the region — useful for outreach and LGU coordination.</p>

                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-province">
                                <thead>
                                    <tr>
                                        <th>Province</th>
                                        <th>City / Municipality</th>
                                        <th>Applicants</th>
                                        <th>Applications</th>
                                        <th>Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$provinceSummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="5">No records found for the selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($provinceSummary as $p): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p['province']) ?></td>
                                            <td><?= htmlspecialchars($p['city_municipality']) ?></td>
                                            <td><?= (int)$p['applicants'] ?></td>
                                            <td><?= (int)$p['applications'] ?></td>
                                            <td><?= (int)$p['approved'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$provinceSummary): ?>
                                <p class="empty-note">No records found for the selected filters.</p>
                            <?php endif; ?>
                            <?php foreach ($provinceSummary as $p): ?>
                                <article class="m-card">
                                    <div class="mb-2">
                                        <p class="break-words text-sm font-bold"><?= htmlspecialchars($p['city_municipality']) ?></p>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($p['province']) ?></p>
                                    </div>
                                    <div class="m-row"><span class="m-key">Applicants</span><span class="m-val"><?= (int)$p['applicants'] ?></span></div>
                                    <div class="m-row"><span class="m-key">Applications</span><span class="m-val"><?= (int)$p['applications'] ?></span></div>
                                    <div class="m-row"><span class="m-key">Approved</span><span class="m-val"><?= (int)$p['approved'] ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===================== IV. AGE GROUP ===================== -->
                <section class="report-section" id="rep-age">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-cake-candles"></i> IV. Applicants by Age Group</div>
                        <p class="section-hint">Demographic breakdown — helps check compliance with job-order age qualifications.</p>
                        <div class="overflow-x-auto">
                            <table class="report-table detail-table" id="tbl-age">
                                <thead>
                                    <tr>
                                        <th>Age Group</th>
                                        <th>Applicants</th>
                                        <th>Applications</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$ageSummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="3">No records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($ageSummary as $a): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($a['age_group']) ?></td>
                                            <td><?= (int)$a['applicants'] ?></td>
                                            <td><?= (int)$a['applications'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- ===================== V. EDUCATION ===================== -->
                <section class="report-section" id="rep-edu">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-graduation-cap"></i> V. Applicants by Educational Attainment</div>
                        <p class="section-hint">Useful for matching applicant qualifications against job order requirements.</p>
                        <div class="overflow-x-auto">
                            <table class="report-table detail-table" id="tbl-edu">
                                <thead>
                                    <tr>
                                        <th>Educational Attainment</th>
                                        <th>Applicants</th>
                                        <th>Applications</th>
                                        <th>Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$eduSummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="4">No records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($eduSummary as $e): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($e['education']) ?></td>
                                            <td><?= (int)$e['applicants'] ?></td>
                                            <td><?= (int)$e['applications'] ?></td>
                                            <td><?= (int)$e['approved'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- ===================== VI. JOB ORDERS ===================== -->
                <section class="report-section" id="rep-jobs">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-briefcase"></i> VI. Job Order Slot Utilization</div>
                        <p class="section-hint">Tracks recruitment progress per RSF job order — applied vs. approved vs. remaining slots.</p>

                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-jobs">
                                <thead>
                                    <tr>
                                        <th>RSF #</th>
                                        <th>Position</th>
                                        <th>Employer</th>
                                        <th>Country</th>
                                        <th>Slots</th>
                                        <th>Applied</th>
                                        <th>Approved</th>
                                        <th>Remaining</th>
                                        <th>Deadline</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$jobSummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="9">No job orders found for the selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($jobSummary as $j): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($j['rsf_number']) ?></td>
                                            <td><?= htmlspecialchars($j['position_title']) ?></td>
                                            <td><?= htmlspecialchars($j['employer_name']) ?></td>
                                            <td><?= htmlspecialchars($j['worksite_country']) ?></td>
                                            <td><?= (int)$j['slots_available'] ?></td>
                                            <td><?= (int)$j['applied'] ?></td>
                                            <td><?= (int)$j['approved'] ?></td>
                                            <td><?= (int)$j['remaining'] ?></td>
                                            <td><?= htmlspecialchars(date('M j, Y', strtotime($j['deadline']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$jobSummary): ?>
                                <p class="empty-note">No job orders found for the selected filters.</p>
                            <?php endif; ?>
                            <?php foreach ($jobSummary as $j):
                                $jSlots = (int)$j['slots_available'];
                                $jPct   = $jSlots > 0 ? min(100, (int) round(((int)$j['approved']) / $jSlots * 100)) : 0;
                            ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-bold"><?= htmlspecialchars($j['position_title']) ?></p>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($j['rsf_number']) ?></p>
                                        </div>
                                        <span class="badge <?= (int)$j['remaining'] === 0 ? 'b-approved' : 'b-other' ?>"><?= (int)$j['remaining'] ?> left</span>
                                    </div>
                                    <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" aria-label="Slots filled" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $jPct ?>">
                                        <div class="h-full rounded-full bg-emerald-500" style="width:<?= $jPct ?>%"></div>
                                    </div>
                                    <div class="m-row"><span class="m-key">Employer</span><span class="m-val"><?= htmlspecialchars($j['employer_name']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Country</span><span class="m-val"><?= htmlspecialchars($j['worksite_country']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Slots</span><span class="m-val"><?= $jSlots ?></span></div>
                                    <div class="m-row"><span class="m-key">Applied</span><span class="m-val"><?= (int)$j['applied'] ?></span></div>
                                    <div class="m-row"><span class="m-key">Approved</span><span class="m-val"><?= (int)$j['approved'] ?></span></div>
                                    <div class="m-row"><span class="m-key">Deadline</span><span class="m-val"><?= htmlspecialchars(date('M j, Y', strtotime($j['deadline']))) ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===================== VII. EVALUATORS ===================== -->
                <section class="report-section" id="rep-evaluators">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-user-clock"></i> VII. Evaluator Turnaround &amp; Workload</div>
                        <p class="section-hint">Average number of days from submission to final decision, per evaluator — useful for performance monitoring.</p>

                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-evaluators">
                                <thead>
                                    <tr>
                                        <th>Evaluator</th>
                                        <th>Email</th>
                                        <th>Total Actions</th>
                                        <th>Approved</th>
                                        <th>Disapproved</th>
                                        <th>For Compliance</th>
                                        <th>Avg. Turnaround (days)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$evalSummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="7">No evaluation records found for the selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($evalSummary as $e): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($e['full_name'] ?? $e['email']) ?></td>
                                            <td><?= htmlspecialchars($e['email']) ?></td>
                                            <td><?= (int)$e['actions'] ?></td>
                                            <td><?= (int)$e['approved'] ?></td>
                                            <td><?= (int)$e['disapproved'] ?></td>
                                            <td><?= (int)$e['for_compliance'] ?></td>
                                            <td><?= $e['avg_days'] !== null ? round((float)$e['avg_days'], 1) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$evalSummary): ?>
                                <p class="empty-note">No evaluation records found for the selected filters.</p>
                            <?php endif; ?>
                            <?php foreach ($evalSummary as $e): ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-bold"><?= htmlspecialchars($e['full_name'] ?? $e['email']) ?></p>
                                            <p class="break-all text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($e['email']) ?></p>
                                        </div>
                                        <span class="badge b-other"><?= (int)$e['actions'] ?> actions</span>
                                    </div>
                                    <div class="m-row"><span class="m-key">Approved</span><span class="m-val"><?= (int)$e['approved'] ?></span></div>
                                    <div class="m-row"><span class="m-key">Disapproved</span><span class="m-val"><?= (int)$e['disapproved'] ?></span></div>
                                    <div class="m-row"><span class="m-key">For Compliance</span><span class="m-val"><?= (int)$e['for_compliance'] ?></span></div>
                                    <div class="m-row"><span class="m-key">Avg. Turnaround</span><span class="m-val"><?= $e['avg_days'] !== null ? round((float)$e['avg_days'], 1) . ' day(s)' : '—' ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===================== VIII. DOCUMENT COMPLIANCE ===================== -->
                <section class="report-section" id="rep-doccompliance">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-file-circle-check"></i> VIII. Document Compliance Report</div>
                        <p class="section-hint">Required vs. submitted documents per application, based on each job order's document requirements.</p>
                        <div class="summary-panel">
                            <div class="summary-cell">
                                <div class="sc-num"><?= count($docCompliance) ?></div>
                                <div class="sc-lbl">Applications Checked</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $docCompleteCount ?></div>
                                <div class="sc-lbl">Fully Compliant</div>
                            </div>
                            <div class="summary-cell <?= $docIncompleteCount > 0 ? 'sc-warn' : '' ?>">
                                <div class="sc-num"><?= $docIncompleteCount ?></div>
                                <div class="sc-lbl">Incomplete</div>
                            </div>
                            <div class="summary-cell <?= $docAvgCompletion < 100 ? 'sc-warn' : '' ?>">
                                <div class="sc-num"><?= $docAvgCompletion ?>%</div>
                                <div class="sc-lbl">Avg. Completion</div>
                            </div>
                        </div>

                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-doccompliance">
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>RSF #</th>
                                        <th>Employer</th>
                                        <th>Required</th>
                                        <th>Submitted</th>
                                        <th>Completion</th>
                                        <th>Missing Documents</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$docCompliance): ?>
                                        <tr class="empty-row">
                                            <td colspan="7">No applications found for the selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($docCompliance as $d): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(fullName($d)) ?></td>
                                            <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                            <td><?= htmlspecialchars($d['employer_name']) ?></td>
                                            <td><?= (int)$d['required_count'] ?></td>
                                            <td><?= (int)$d['submitted_count'] ?></td>
                                            <td><span class="badge <?= $d['completion_pct'] == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= (int)$d['completion_pct'] ?>%</span></td>
                                            <td><?= htmlspecialchars($d['missing_documents']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$docCompliance): ?>
                                <p class="empty-note">No applications found for the selected filters.</p>
                            <?php endif; ?>
                            <?php foreach ($docCompliance as $d): ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-bold"><?= htmlspecialchars(fullName($d)) ?></p>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($d['rsf_number']) ?></p>
                                        </div>
                                        <span class="badge <?= $d['completion_pct'] == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= (int)$d['completion_pct'] ?>%</span>
                                    </div>
                                    <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" aria-label="Document completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$d['completion_pct'] ?>">
                                        <div class="h-full rounded-full <?= $d['completion_pct'] == 100 ? 'bg-emerald-500' : 'bg-red-500' ?>" style="width:<?= (int)$d['completion_pct'] ?>%"></div>
                                    </div>
                                    <div class="m-row"><span class="m-key">Employer</span><span class="m-val"><?= htmlspecialchars($d['employer_name']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Submitted</span><span class="m-val"><?= (int)$d['submitted_count'] ?> of <?= (int)$d['required_count'] ?> required</span></div>
                                    <div class="m-row"><span class="m-key">Missing</span><span class="m-val"><?= htmlspecialchars($d['missing_documents']) ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <p class="mt-6 border-t border-slate-200 pt-2.5 text-center text-[10px] leading-relaxed text-slate-400 dark:border-slate-700">Department of Migrant Workers — Application Management System &nbsp;|&nbsp; Regional Office IV-A (CALABARZON) &nbsp;|&nbsp; For official use only</p>

            </div>
        </div>

        <!-- ===================== PRINT / PDF LAYOUT (unchanged) ===================== -->
        <div id="printReport">
            <div class="pr-page-header">
                <img id="printLogo" src="../pictures/dmw_logo.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
                <div>
                    <div class="pr-republic">Republic of the Philippines</div>
                    <div class="pr-agency">DEPARTMENT OF MIGRANT WORKERS</div>
                    <div class="pr-office">
                        Regional Office IV-A (CALABARZON)<br>
                        Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                        Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                    </div>
                </div>
            </div>

            <div class="pr-report-title">
                <h1>Migrant Worker Application Reports</h1>
                <p><?= htmlspecialchars($filterRecap) ?></p>
            </div>

            <div class="pr-meta-bar">
                <span>Generated: <?= htmlspecialchars($generatedAt) ?></span>
                <span>Prepared by: <?= htmlspecialchars($admin_name) ?></span>
                <span>DMW RO IV-A</span>
            </div>

            <p class="pr-intro">
                This report consolidates migrant worker application statistics processed through the DMW Application
                Management System for Regional Office IV-A. Figures reflect the filters selected at generation time and are
                intended for management review, audit, outreach planning, and official case monitoring.
            </p>

            <table class="pr-summary-panel">
                <tr>
                    <td>
                        <span class="sc-num"><?= $totalApps ?></span>
                        <span class="sc-lbl">Applications</span>
                    </td>
                    <td>
                        <span class="sc-num"><?= $approvedCount ?></span>
                        <span class="sc-lbl">Approved</span>
                    </td>
                    <td>
                        <span class="sc-num"><?= $disapprovedCount ?></span>
                        <span class="sc-lbl">Disapproved</span>
                    </td>
                    <td>
                        <span class="sc-num"><?= $inProcessCount ?></span>
                        <span class="sc-lbl">In Process</span>
                    </td>
                    <td>
                        <span class="sc-num"><?= $docAvgCompletion ?>%</span>
                        <span class="sc-lbl">Avg. Docs Complete</span>
                    </td>
                </tr>
            </table>

            <div class="pr-report-block active" data-target="rep-country">
                <div class="pr-section">
                    <div class="pr-section-title">I. Approved Applicants by Country</div>
                    <p class="pr-section-hint">Approved applications grouped by worksite/destination country.</p>
                    <?php if (!$countrySummary): ?>
                        <p class="pr-empty">No approved applications found for the selected filters.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Destination Country</th>
                                    <th>Approved Applicants</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($countrySummary as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['country']) ?></td>
                                        <td><?= (int)$c['approved_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="pr-section">
                    <div class="pr-section-title">Approved Applicant Detail</div>
                    <?php if (!$countryDetail): ?>
                        <p class="pr-empty">No records found.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Gender</th>
                                    <th>Origin</th>
                                    <th>Country</th>
                                    <th>Position</th>
                                    <th>Employer</th>
                                    <th>Approved On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($countryDetail as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(fullName($r)) ?></td>
                                        <td><?= htmlspecialchars($r['gender']) ?></td>
                                        <td><?= htmlspecialchars($r['city_municipality'] . ', ' . $r['province']) ?></td>
                                        <td><?= htmlspecialchars($r['country']) ?></td>
                                        <td><?= htmlspecialchars($r['position_title']) ?></td>
                                        <td><?= htmlspecialchars($r['employer_name']) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($r['approved_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-status">
                <div class="pr-section">
                    <div class="pr-section-title">II. Application Status Summary</div>
                    <p class="pr-section-hint">Overall processing funnel for the selected filters.</p>
                    <table class="pr-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statusSummary as $s): ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['status']) ?></td>
                                    <td><?= (int)$s['count'] ?></td>
                                    <td><?= $s['pct'] ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-province">
                <div class="pr-section">
                    <div class="pr-section-title">III. Applicants by Province &amp; City of Origin</div>
                    <?php if (!$provinceSummary): ?>
                        <p class="pr-empty">No records found for the selected filters.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Province</th>
                                    <th>City / Municipality</th>
                                    <th>Applicants</th>
                                    <th>Applications</th>
                                    <th>Approved</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($provinceSummary as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['province']) ?></td>
                                        <td><?= htmlspecialchars($p['city_municipality']) ?></td>
                                        <td><?= (int)$p['applicants'] ?></td>
                                        <td><?= (int)$p['applications'] ?></td>
                                        <td><?= (int)$p['approved'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-age">
                <div class="pr-section">
                    <div class="pr-section-title">IV. Applicants by Age Group</div>
                    <?php if (!$ageSummary): ?>
                        <p class="pr-empty">No records found.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Age Group</th>
                                    <th>Applicants</th>
                                    <th>Applications</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ageSummary as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['age_group']) ?></td>
                                        <td><?= (int)$a['applicants'] ?></td>
                                        <td><?= (int)$a['applications'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-edu">
                <div class="pr-section">
                    <div class="pr-section-title">V. Applicants by Educational Attainment</div>
                    <?php if (!$eduSummary): ?>
                        <p class="pr-empty">No records found.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Educational Attainment</th>
                                    <th>Applicants</th>
                                    <th>Applications</th>
                                    <th>Approved</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eduSummary as $e): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($e['education']) ?></td>
                                        <td><?= (int)$e['applicants'] ?></td>
                                        <td><?= (int)$e['applications'] ?></td>
                                        <td><?= (int)$e['approved'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-jobs">
                <div class="pr-section">
                    <div class="pr-section-title">VI. Job Order Slot Utilization</div>
                    <?php if (!$jobSummary): ?>
                        <p class="pr-empty">No job orders found for the selected filters.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>RSF #</th>
                                    <th>Position</th>
                                    <th>Employer</th>
                                    <th>Country</th>
                                    <th>Slots</th>
                                    <th>Applied</th>
                                    <th>Approved</th>
                                    <th>Remaining</th>
                                    <th>Deadline</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobSummary as $j): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($j['rsf_number']) ?></td>
                                        <td><?= htmlspecialchars($j['position_title']) ?></td>
                                        <td><?= htmlspecialchars($j['employer_name']) ?></td>
                                        <td><?= htmlspecialchars($j['worksite_country']) ?></td>
                                        <td><?= (int)$j['slots_available'] ?></td>
                                        <td><?= (int)$j['applied'] ?></td>
                                        <td><?= (int)$j['approved'] ?></td>
                                        <td><?= (int)$j['remaining'] ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($j['deadline']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-evaluators">
                <div class="pr-section">
                    <div class="pr-section-title">VII. Evaluator Turnaround &amp; Workload</div>
                    <?php if (!$evalSummary): ?>
                        <p class="pr-empty">No evaluation records found for the selected filters.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Evaluator</th>
                                    <th>Email</th>
                                    <th>Total Actions</th>
                                    <th>Approved</th>
                                    <th>Disapproved</th>
                                    <th>For Compliance</th>
                                    <th>Avg. Turnaround (days)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evalSummary as $e): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($e['full_name'] ?? $e['email']) ?></td>
                                        <td><?= htmlspecialchars($e['email']) ?></td>
                                        <td><?= (int)$e['actions'] ?></td>
                                        <td><?= (int)$e['approved'] ?></td>
                                        <td><?= (int)$e['disapproved'] ?></td>
                                        <td><?= (int)$e['for_compliance'] ?></td>
                                        <td><?= $e['avg_days'] !== null ? round((float)$e['avg_days'], 1) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-doccompliance">
                <div class="pr-section">
                    <div class="pr-section-title">VIII. Document Compliance Report</div>
                    <p class="pr-section-hint">
                        Applications checked: <b><?= count($docCompliance) ?></b>
                        — Fully compliant: <span class="pr-badge-ok"><?= $docCompleteCount ?></span>
                        — Incomplete: <span class="pr-badge-bad"><?= $docIncompleteCount ?></span>
                        — Avg. completion: <b><?= $docAvgCompletion ?>%</b>
                    </p>
                    <?php if (!$docCompliance): ?>
                        <p class="pr-empty">No applications found for the selected filters.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF #</th>
                                    <th>Employer</th>
                                    <th>Required</th>
                                    <th>Submitted</th>
                                    <th>Completion</th>
                                    <th>Missing Documents</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($docCompliance as $d): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(fullName($d)) ?></td>
                                        <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                        <td><?= htmlspecialchars($d['employer_name']) ?></td>
                                        <td><?= (int)$d['required_count'] ?></td>
                                        <td><?= (int)$d['submitted_count'] ?></td>
                                        <td><span class="<?= $d['completion_pct'] == 100 ? 'pr-badge-ok' : 'pr-badge-bad' ?>"><?= (int)$d['completion_pct'] ?>%</span></td>
                                        <td><?= htmlspecialchars($d['missing_documents']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-signoff">
                <div class="pr-sig-line">
                    <div class="line">Prepared by / Reviewing Officer</div>
                </div>
                <div class="pr-sig-line">
                    <div class="line">Authorized Signatory, DMW RO IV-A</div>
                </div>
            </div>

            <div class="pr-footer">
                <div class="pr-footer-columns">
                    <div class="pr-footer-left">
                        <p class="pr-footer-disclaimer">
                            This is an official system-generated report from the DMW Application Management System —
                            Regional Office IV-A (CALABARZON). Information is confidential and intended solely for internal
                            evaluation, compliance review, and official case processing.
                        </p>
                    </div>
                    <div class="pr-footer-right">
                        <span>Department of Migrant Workers - RO IV-A (CALABARZON)</span>
                        <span>Migrant Worker Application Reports</span>
                        <span>System-Generated Document • For Official Use Only</span>
                        <span><?= htmlspecialchars($generatedAt) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const REPORT_TITLES = {
            'rep-gender': 'Applicants by Gender',
            'rep-country': 'Approved Applicants by Country',
            'rep-status': 'Application Status Summary',
            'rep-province': 'Applicants by Province-City',
            'rep-age': 'Applicants by Age Group',
            'rep-edu': 'Applicants by Educational Attainment',
            'rep-jobs': 'Job Order Slot Utilization',
            'rep-evaluators': 'Evaluator Turnaround',
            'rep-doccompliance': 'Document Compliance Report'
        };

        const REPORT_META = <?= json_encode([
                                'filters'     => $filterRecap,
                                'generatedAt' => $generatedAt,
                                'preparedBy'  => $admin_name,
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.report-section').forEach(s => s.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.target).classList.add('active');
                syncPrintTarget();
            });
        });

        function activeSectionId() {
            return document.querySelector('.report-section.active').id;
        }

        function syncPrintTarget() {
            const id = activeSectionId();
            document.querySelectorAll('.pr-report-block').forEach(b => {
                b.classList.toggle('active', b.dataset.target === id);
            });
        }
        syncPrintTarget();

        function printCurrent() {
            document.body.classList.remove('print-all');
            syncPrintTarget();
            window.print();
        }

        function printAll() {
            document.body.classList.add('print-all');
            window.print();
        }

        window.addEventListener('afterprint', () => {
            document.body.classList.remove('print-all');
        });

        function tablesInSection(sectionId) {
            return Array.from(document.querySelectorAll('#' + sectionId + ' table.report-table'));
        }

        function safeSheetName(name) {
            return name.replace(/[\\\/\?\*\[\]:]/g, ' ').substring(0, 31);
        }

        /* ───────── Styled Excel export (ExcelJS) ───────── */
        const XL = {
            navy: 'FF0B1F4B',
            navySoft: 'FF13295E',
            white: 'FFFFFFFF',
            zebra: 'FFF8FAFC',
            border: 'FFD1D5DB',
            grey: 'FF6B7280',
            okBg: 'FFD1FAE5',
            okFg: 'FF047857',
            badBg: 'FFFEE2E2',
            badFg: 'FFB91C1C',
            totalBg: 'FFE9EDF7'
        };
        const XL_TOTAL_TABLES = ['tbl-country-summary', 'tbl-status']; // tables that get a TOTAL row
        const thin = c => ({
            style: 'thin',
            color: {
                argb: c || XL.border
            }
        });
        const allBorders = () => ({
            top: thin(),
            left: thin(),
            bottom: thin(),
            right: thin()
        });

        function xlParse(txt, header) {
            if (/rsf|email|phone/i.test(header)) return {
                v: txt
            };
            if (/^-?\d+$/.test(txt)) return {
                v: Number(txt),
                fmt: '0',
                num: true
            };
            if (/^-?\d+\.\d+$/.test(txt)) return {
                v: Number(txt),
                fmt: '0.0',
                num: true
            };
            const p = txt.match(/^(\d+(?:\.(\d+))?)%$/);
            if (p) return {
                v: Number(p[1]) / 100,
                fmt: p[2] ? '0.0%' : '0%',
                num: true,
                pct: true
            };
            return {
                v: txt
            };
        }

        async function xlLoadLogo(wb) {
            try {
                const data = await toDataURL('../pictures/dmw_logo.png');
                const img = await loadImage(data);
                return {
                    id: wb.addImage({
                        base64: data.split(',')[1],
                        extension: 'png'
                    }),
                    ratio: img.naturalWidth / img.naturalHeight
                };
            } catch (e) {
                console.warn('Logo not added to Excel:', e);
                return null;
            }
        }

        function xlBuildSheet(wb, section, logo) {
            const id = section.id;
            const ws = wb.addWorksheet(safeSheetName(REPORT_TITLES[id] || id), {
                properties: {
                    tabColor: {
                        argb: XL.navy
                    }
                },
                views: [{
                    showGridLines: false
                }],
                pageSetup: {
                    orientation: 'landscape',
                    paperSize: 9,
                    fitToPage: true,
                    fitToWidth: 1,
                    fitToHeight: 0,
                    margins: {
                        left: .4,
                        right: .4,
                        top: .5,
                        bottom: .6,
                        header: .3,
                        footer: .3
                    }
                }
            });
            ws.headerFooter.oddFooter = '&L&8DMW RO IV-A - For Official Use Only&R&8Page &P of &N';

            // Collect tables
            const blocks = [];
            section.querySelectorAll('.section').forEach(sec => {
                const tbl = sec.querySelector('table.report-table');
                if (!tbl) return;
                const title = (sec.querySelector('.section-title')?.textContent || '').replace(/\s+/g, ' ').trim();
                const headers = Array.from(tbl.querySelectorAll('thead th')).map(th => th.textContent.trim());
                const rows = Array.from(tbl.querySelectorAll('tbody tr:not(.empty-row)'))
                    .map(tr => Array.from(tr.children).map(td => td.textContent.replace(/\s+/g, ' ').trim()));
                blocks.push({
                    title,
                    headers,
                    rows,
                    tableId: tbl.id
                });
            });

            const N = Math.max(5, ...blocks.map(b => b.headers.length));

            // Column widths from content
            const widths = Array(N).fill(12);
            blocks.forEach(b => {
                [b.headers, ...b.rows].forEach(r => r.forEach((t, i) => {
                    widths[i] = Math.min(45, Math.max(widths[i], String(t).length + 3));
                }));
            });
            widths.forEach((w, i) => ws.getColumn(i + 1).width = w);

            // Letterhead
            const head = [
                ['Republic of the Philippines', 9, false, XL.grey, 14],
                ['DEPARTMENT OF MIGRANT WORKERS', 15, true, XL.navy, 24],
                ['Regional Office IV-A (CALABARZON)', 9.5, false, XL.grey, 14],
                ['Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027  |  calamba@dmw.gov.ph  |  dmw.gov.ph', 8, false, XL.grey, 14]
            ];
            head.forEach(([text, size, bold, color, h], i) => {
                const r = ws.getRow(i + 1);
                r.height = h;
                const c = r.getCell(1);
                c.value = text;
                c.font = {
                    name: 'Calibri',
                    size,
                    bold,
                    color: {
                        argb: color
                    }
                };
                c.alignment = {
                    vertical: 'middle',
                    horizontal: 'left',
                    indent: 9
                };
            });
            for (let c = 1; c <= N; c++) {
                ws.getRow(4).getCell(c).border = {
                    bottom: {
                        style: 'thick',
                        color: {
                            argb: XL.navy
                        }
                    }
                };
            }
            if (logo) {
                ws.addImage(logo.id, {
                    tl: {
                        col: 0.15,
                        row: 0.25
                    },
                    ext: {
                        width: 66 * logo.ratio,
                        height: 66
                    }
                });
            }

            // Report title + meta
            const meta = (rowNo, text, font, h) => {
                ws.mergeCells(rowNo, 1, rowNo, N);
                const c = ws.getCell(rowNo, 1);
                c.value = text;
                c.font = font;
                c.alignment = {
                    horizontal: 'center',
                    vertical: 'middle',
                    wrapText: true
                };
                ws.getRow(rowNo).height = h;
            };
            meta(6, (REPORT_TITLES[id] || id).toUpperCase(), {
                name: 'Calibri',
                size: 15,
                bold: true,
                color: {
                    argb: XL.navy
                }
            }, 26);
            meta(7, REPORT_META.filters, {
                name: 'Calibri',
                size: 10,
                italic: true,
                color: {
                    argb: XL.grey
                }
            }, 16);
            meta(8, `Generated: ${REPORT_META.generatedAt}   |   Prepared by: ${REPORT_META.preparedBy}`, {
                name: 'Calibri',
                size: 9,
                color: {
                    argb: XL.grey
                }
            }, 16);

            // Tables
            let row = 10;
            let firstHeaderRow = null,
                lastDataRow = null;

            blocks.forEach(b => {
                // Section banner
                ws.mergeCells(row, 1, row, N);
                const t = ws.getCell(row, 1);
                t.value = b.title.toUpperCase();
                t.font = {
                    name: 'Calibri',
                    size: 11,
                    bold: true,
                    color: {
                        argb: XL.white
                    }
                };
                t.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: XL.navy
                    }
                };
                t.alignment = {
                    vertical: 'middle',
                    indent: 1
                };
                ws.getRow(row).height = 22;
                row++;

                // Header row
                const hr = ws.getRow(row);
                hr.height = 26;
                b.headers.forEach((h, i) => {
                    const c = hr.getCell(i + 1);
                    c.value = h;
                    c.font = {
                        name: 'Calibri',
                        size: 10,
                        bold: true,
                        color: {
                            argb: XL.white
                        }
                    };
                    c.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: {
                            argb: XL.navySoft
                        }
                    };
                    c.alignment = {
                        horizontal: 'center',
                        vertical: 'middle',
                        wrapText: true
                    };
                    c.border = allBorders();
                });
                if (firstHeaderRow === null) firstHeaderRow = row;
                const headerRowNo = row;
                row++;

                // Data rows
                if (!b.rows.length) {
                    ws.mergeCells(row, 1, row, b.headers.length);
                    const c = ws.getCell(row, 1);
                    c.value = 'No records found for the selected filters.';
                    c.font = {
                        name: 'Calibri',
                        size: 10,
                        italic: true,
                        color: {
                            argb: XL.grey
                        }
                    };
                    c.alignment = {
                        horizontal: 'center',
                        vertical: 'middle'
                    };
                    c.border = allBorders();
                    row++;
                }
                const dataStart = row;
                b.rows.forEach((cells, rIdx) => {
                    const r = ws.getRow(row);
                    r.height = 20;
                    cells.forEach((txt, i) => {
                        const p = xlParse(txt, b.headers[i] || '');
                        const c = r.getCell(i + 1);
                        c.value = p.v;
                        if (p.fmt) c.numFmt = p.fmt;
                        c.font = {
                            name: 'Calibri',
                            size: 10,
                            color: {
                                argb: 'FF1F2937'
                            }
                        };
                        c.alignment = {
                            vertical: 'middle',
                            horizontal: p.num ? 'center' : 'left',
                            wrapText: !p.num
                        };
                        c.border = allBorders();
                        if (rIdx % 2 === 1) c.fill = {
                            type: 'pattern',
                            pattern: 'solid',
                            fgColor: {
                                argb: XL.zebra
                            }
                        };

                        // Completion % → green when 100%, red otherwise
                        if (/completion/i.test(b.headers[i] || '') && p.pct) {
                            const full = p.v >= 1;
                            c.font = {
                                name: 'Calibri',
                                size: 10,
                                bold: true,
                                color: {
                                    argb: full ? XL.okFg : XL.badFg
                                }
                            };
                            c.fill = {
                                type: 'pattern',
                                pattern: 'solid',
                                fgColor: {
                                    argb: full ? XL.okBg : XL.badBg
                                }
                            };
                        }
                    });
                    row++;
                });
                const dataEnd = row - 1;
                if (b.rows.length) lastDataRow = dataEnd;

                // TOTAL row (live SUM formulas) for summary tables
                if (b.rows.length && XL_TOTAL_TABLES.includes(b.tableId)) {
                    const r = ws.getRow(row);
                    r.height = 22;
                    b.headers.forEach((h, i) => {
                        const c = r.getCell(i + 1);
                        const col = ws.getColumn(i + 1).letter;
                        const sample = xlParse(b.rows[0][i], h);
                        if (i === 0) c.value = 'TOTAL';
                        else if (sample.num) {
                            c.value = {
                                formula: `SUM(${col}${dataStart}:${col}${dataEnd})`
                            };
                            c.numFmt = sample.fmt;
                        }
                        c.font = {
                            name: 'Calibri',
                            size: 10,
                            bold: true,
                            color: {
                                argb: XL.navy
                            }
                        };
                        c.fill = {
                            type: 'pattern',
                            pattern: 'solid',
                            fgColor: {
                                argb: XL.totalBg
                            }
                        };
                        c.alignment = {
                            vertical: 'middle',
                            horizontal: i === 0 ? 'left' : 'center'
                        };
                        c.border = {
                            top: {
                                style: 'medium',
                                color: {
                                    argb: XL.navy
                                }
                            },
                            bottom: thin(),
                            left: thin(),
                            right: thin()
                        };
                    });
                    row++;
                }
                row += 2; // gap before next table
            });

            // Filter dropdowns only when the sheet has a single table
            if (blocks.length === 1 && blocks[0].rows.length) {
                ws.autoFilter = {
                    from: {
                        row: firstHeaderRow,
                        column: 1
                    },
                    to: {
                        row: lastDataRow,
                        column: blocks[0].headers.length
                    }
                };
            }

            // Footer
            ws.mergeCells(row, 1, row, N);
            const f = ws.getCell(row, 1);
            f.value = 'System-generated report  •  DMW Application Management System  •  Regional Office IV-A (CALABARZON)  •  For Official Use Only';
            f.font = {
                name: 'Calibri',
                size: 8,
                italic: true,
                color: {
                    argb: XL.grey
                }
            };
            f.alignment = {
                horizontal: 'center'
            };
            f.border = {
                top: thin()
            };
            ws.pageSetup.printArea = `A1:${ws.getColumn(N).letter}${row}`;
        }

        async function xlSave(wb, filename) {
            const buf = await wb.xlsx.writeBuffer();
            const blob = new Blob([buf], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        }

        async function exportWorkbook(sections, filename) {
            const wb = new ExcelJS.Workbook();
            wb.creator = REPORT_META.preparedBy;
            wb.company = 'DMW Regional Office IV-A';
            wb.created = new Date();
            const logo = await xlLoadLogo(wb);
            sections.forEach(s => xlBuildSheet(wb, s, logo));
            await xlSave(wb, filename);
        }

        async function exportCurrent() {
            const section = document.querySelector('.report-section.active');
            if (!section || !tablesInSection(section.id).length) return;
            await exportWorkbook([section], `${REPORT_TITLES[section.id].replace(/\s+/g, '_')}.xlsx`);
        }

        async function exportAll() {
            const sections = Array.from(document.querySelectorAll('.report-section'))
                .filter(s => tablesInSection(s.id).length);
            await exportWorkbook(sections, 'DMW_All_Reports.xlsx');
        }

        async function toDataURL(url) {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Logo fetch failed: ' + response.status);
            const blob = await response.blob();
            return await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onloadend = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });
        }

        function loadImage(src) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = reject;
                img.src = src;
            });
        }

        async function generateOfficialPdf(filename, allReports) {
            const webView = document.querySelector('.webView');
            const printEl = document.getElementById('printReport');
            const printLogo = document.getElementById('printLogo');
            const prHeader = printEl.querySelector('.pr-page-header');
            const originalLogoSrc = printLogo ? printLogo.getAttribute('src') : null;
            const id = activeSectionId();

            printEl.querySelectorAll('.pr-report-block').forEach(b => {
                b.style.display = (allReports || b.dataset.target === id) ? 'block' : 'none';
            });

            // Load the logo once so it can be stamped on every page
            let logoData = null;
            let logoW = 0,
                logoH = 15; // mm
            if (originalLogoSrc) {
                try {
                    logoData = await toDataURL(originalLogoSrc);
                    printLogo.src = logoData;
                    const img = await loadImage(logoData);
                    logoW = logoH * (img.naturalWidth / img.naturalHeight);

                    // Downscale: 15 mm tall needs only ~180 px, not the full-size original
                    const targetH = 180;
                    const scale = Math.min(1, targetH / img.naturalHeight);
                    const c = document.createElement('canvas');
                    c.width = Math.round(img.naturalWidth * scale);
                    c.height = Math.round(img.naturalHeight * scale);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    logoData = c.toDataURL('image/png');
                } catch (err) {
                    console.warn('DMW logo could not be inlined for PDF export:', err);
                    logoData = null;
                }
            }

            if (webView) webView.style.display = 'none';
            if (printEl) printEl.style.display = 'block';
            if (prHeader) prHeader.style.display = '';
            document.querySelectorAll('.no-print').forEach(el => el.style.display = 'none');

            const restore = () => {
                if (webView) webView.style.display = '';
                if (printEl) printEl.style.display = 'none';
                if (prHeader) prHeader.style.display = '';
                if (printLogo && originalLogoSrc) printLogo.src = originalLogoSrc;
                document.querySelectorAll('.no-print').forEach(el => el.style.display = '');
                printEl.querySelectorAll('.pr-report-block').forEach(b => {
                    b.style.display = '';
                });
                syncPrintTarget();
            };

            // Snapshot the on-screen letterhead so every PDF page uses the same header as page 1
            const SIDE_MARGIN = 8;
            let headerImg = null;
            let headerWmm = 210 - SIDE_MARGIN * 2;
            let headerHmm = 22;
            if (prHeader && typeof html2canvas === 'function') {
                try {
                    const headerCanvas = await html2canvas(prHeader, {
                        scale: 2,
                        backgroundColor: '#ffffff',
                        useCORS: true
                    });
                    headerImg = headerCanvas.toDataURL('image/png');
                    headerHmm = headerWmm * (headerCanvas.height / headerCanvas.width);
                } catch (err) {
                    console.warn('Could not capture report header for PDF:', err);
                    headerImg = null;
                }
            }
            // Letterhead is stamped onto every page below, so hide the in-flow copy
            if (prHeader) prHeader.style.display = 'none';

            const HEADER_TOP_MARGIN = Math.max(34, Math.ceil(headerHmm + 10));

            const opt = {
                margin: [HEADER_TOP_MARGIN, SIDE_MARGIN, 14, SIDE_MARGIN],
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                },
                pagebreak: {
                    mode: ['css', 'legacy']
                }
            };

            try {
                const pdf = await html2pdf()
                    .set(opt)
                    .from(printEl)
                    .toPdf()
                    .get('pdf');

                const totalPages = pdf.internal.getNumberOfPages();
                const pageW = pdf.internal.pageSize.getWidth();
                const pageH = pdf.internal.pageSize.getHeight();
                const left = SIDE_MARGIN;

                const drawFallbackLetterhead = function() {
                    let textX = left;
                    if (logoData) {
                        pdf.addImage(logoData, 'PNG', left, 7, logoW, logoH);
                        textX = left + logoW + 4;
                    }
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(7.5);
                    pdf.setTextColor(75, 85, 99);
                    pdf.text('Republic of the Philippines', textX, 9.5);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(11);
                    pdf.setTextColor(11, 31, 75);
                    pdf.text('DEPARTMENT OF MIGRANT WORKERS', textX, 14.5);
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(7);
                    pdf.setTextColor(75, 85, 99);
                    pdf.text('Regional Office IV-A (CALABARZON)', textX, 18.5);
                    pdf.text('Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027', textX, 22);
                    pdf.text('Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph', textX, 25.5);
                    pdf.setDrawColor(11, 31, 75);
                    pdf.setLineWidth(0.8);
                    pdf.line(left, 27.5, pageW - left, 27.5);
                };

                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    if (headerImg) {
                        pdf.addImage(headerImg, 'PNG', left, 6, headerWmm, headerHmm);
                    } else {
                        drawFallbackLetterhead();
                    }
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(7.3);
                    pdf.setTextColor(150, 150, 150);
                    pdf.text(
                        `DMW RO IV-A Reports | Page ${i} of ${totalPages}`,
                        pageW / 2,
                        pageH - 6, {
                            align: 'center'
                        }
                    );
                }

                pdf.save(filename);
                restore();
            } catch (err) {
                restore();
                throw err;
            }
        }

        async function downloadCurrentPDF(btn) {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            try {
                const id = activeSectionId();
                await generateOfficialPdf(`${REPORT_TITLES[id].replace(/\s+/g, '_')}.pdf`, false);
            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        async function downloadAllPDF(btn) {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            try {
                await generateOfficialPdf('DMW_All_Reports.pdf', true);
            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        const genderLabels = <?= json_encode(array_column($genderSummary, 'gender')) ?>;
        const genderData = <?= json_encode(array_map('intval', array_column($genderSummary, 'applicants'))) ?>;
        const genderColorMap = {
            'Male': '#3b82f6',
            'Female': '#ec4899',
            'Not Specified': '#94a3b8'
        };
        const genderColors = genderLabels.map(g => genderColorMap[g] || '#f59e0b');
        const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
        const chartText = isDarkTheme ? '#cbd5e1' : '#475569';
        const chartGrid = isDarkTheme ? '#2d3748' : '#f1f5f9';
        const chartBorder = isDarkTheme ? '#0f172a' : '#ffffff';

        const chartGenderEl = document.getElementById('chartGender');
        if (chartGenderEl) {
            new Chart(chartGenderEl, {
                type: 'doughnut',
                data: {
                    labels: genderLabels,
                    datasets: [{
                        data: genderData,
                        backgroundColor: genderColors,
                        borderColor: chartBorder,
                        borderWidth: 2
                    }]
                },
                options: {
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 11
                                },
                                color: chartText
                            }
                        }
                    }
                }
            });
        }

        const countryLabels = <?= json_encode(array_column($countrySummary, 'country')) ?>;
        const countryData = <?= json_encode(array_map('intval', array_column($countrySummary, 'approved_count'))) ?>;
        const chartCountryEl = document.getElementById('chartCountry');
        if (chartCountryEl) {
            new Chart(chartCountryEl, {
                type: 'bar',
                data: {
                    labels: countryLabels,
                    datasets: [{
                        label: 'Approved Applicants',
                        data: countryData,
                        backgroundColor: '#0b1f4b',
                        borderRadius: 6
                    }]
                },
                options: {
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: chartText
                            },
                            grid: {
                                color: chartGrid
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                color: chartText
                            },
                            grid: {
                                color: chartGrid
                            }
                        }
                    }
                }
            });
        }

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
            panel.style.cssText = 'position:fixed;background:var(--surface);border:1.5px solid var(--bdr);border-radius:8px;box-shadow:0 10px 30px rgba(11,31,75,.16);z-index:999;overflow:hidden;padding:4px 0;max-height:240px;overflow-y:auto;width:224px;';
            panel.classList.add('hidden');
            document.body.appendChild(panel);

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 12px;font-size:12px;color:var(--text1);cursor:pointer;';
                row.dataset.value = optionEl.value;
                row.addEventListener('mouseenter', () => row.style.background = 'var(--surface2)');
                row.addEventListener('mouseleave', () => row.style.background = row.dataset.selected === '1' ? 'var(--selected-row)' : '');
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
                panel.querySelectorAll('[role="option"]').forEach(row => {
                    const selected = row.dataset.value === select.value;
                    row.dataset.selected = selected ? '1' : '0';
                    row.style.background = selected ? 'var(--selected-row)' : '';
                    row.style.color = selected ? 'var(--navy)' : 'var(--text1)';
                    row.style.fontWeight = selected ? '700' : '400';
                    let check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check';
                        icon.style.cssText = 'font-size:10px;color:var(--blue-lt);flex-shrink:0;';
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

        document.querySelectorAll('.filter-scroll select').forEach(enhancePillSelect);

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

    <div class="dock-wrap no-print">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <span class="dk-badge"><?= (int) $active_apps ?></span>
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
            <a href="job-orders.php" class="dk" data-tip="Job Orders">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">RSF</span>
            </a>
            <a href="reports.php" class="dk active" data-tip="Reports">
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

    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
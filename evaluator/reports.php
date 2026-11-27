<?php

/**
 * evaluator-reports.php  –  DMW RO IV-A Evaluator Self-Service Reports
 * Printable + Excel-exportable report suite, scoped to the logged-in
 * evaluator only. Mirrors the structure/style of the admin's reportss.php.
 *
 * Reports:
 *   1. My Workload Summary       — everything ever assigned to me (assignments table)
 *   2. My Decision History       — every evaluation I've ever recorded
 *   3. My Approval Rate & Turnaround — approve/disapprove ratio + avg. days to decide
 *   4. My Document Compliance    — required vs. submitted documents
 */
session_start();
require_once '../db.php'; // gives us $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

// Resolve the logged-in evaluator's eid + name — everything below is scoped to this eid only
$stmt = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $_SESSION['user_email']);
$stmt->execute();
$evaluatorRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$evaluatorRow) {
    http_response_code(403);
    die('Evaluator profile not found for this account.');
}
$evaluator_id    = (int) $evaluatorRow['eid'];
$evaluator_name  = $evaluatorRow['full_name'] ?: $_SESSION['user_email'];
$evaluator_email = $_SESSION['user_email'];

// Greeting + avatar initials — same pattern used across the project's headers
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts    = explode(' ', trim($evaluator_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);

/* FILTER — a single optional month filter, same UX pattern as reportss.php */
$f_month = trim($_GET['month'] ?? '');

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

function fullName(array $row): string
{
    return trim(implode(' ', array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['suffix'] ?? '',
    ])));
}

function decisionBadgeClass(?string $decision): string
{
    return match ($decision) {
        'approved'      => 'b-approved',
        'disapproved'   => 'b-disapproved',
        'for_compliance' => 'b-pending',
        default          => 'b-other',
    };
}

// Base "scoped to me" filter, reused by every query below
$where  = ['asg.evaluator_id = ?'];
$types  = 'i';
$params = [$evaluator_id];
if ($f_month !== '' && preg_match('/^\d{4}-\d{2}$/', $f_month)) {
    $start = $f_month . '-01 00:00:00';
    $end   = date('Y-m-t 23:59:59', strtotime($start));
    $where[]  = 'asg.assigned_at BETWEEN ? AND ?';
    $types   .= 'ss';
    $params[] = $start;
    $params[] = $end;
}
$whereSql = whereSql($where);

// Month options for the filter — full calendar months for every year that
// appears in this evaluator's assignment history (falls back to the current
// year if they have no assignments yet), so all months are selectable even
// if a given month currently has zero records.
$yearOptions = [];
$res = q($database, "
    SELECT DISTINCT YEAR(asg.assigned_at) AS yr
    FROM assignments asg
    WHERE asg.evaluator_id = ?
    ORDER BY yr DESC
", 'i', [$evaluator_id]);
while ($row = $res->fetch_assoc()) $yearOptions[] = (int) $row['yr'];
if (!$yearOptions) $yearOptions[] = (int) date('Y');

$monthOptions = [];
foreach ($yearOptions as $yr) {
    for ($m = 1; $m <= 12; $m++) {
        $monthOptions[] = $yr . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    }
}

/* REPORT 1 — My Workload Summary  (source: assignments, "ever assigned") */

// 1a. Assignment status breakdown (active / completed / reassigned)
$assignmentStatusSummary = [];
$res = q($database, "
    SELECT asg.status, COUNT(*) AS cnt
    FROM assignments asg
    $whereSql
    GROUP BY asg.status
", $types, $params);
while ($row = $res->fetch_assoc()) $assignmentStatusSummary[$row['status']] = (int) $row['cnt'];

$totalEverAssigned = array_sum($assignmentStatusSummary);
$distinctApplications = 0;
$res = q($database, "
    SELECT COUNT(DISTINCT asg.application_id) AS cnt
    FROM assignments asg
    $whereSql
", $types, $params);
$distinctApplications = (int) ($res->fetch_assoc()['cnt'] ?? 0);

// 1b. Current status of every application ever assigned to me (distinct applications)
$currentStatusSummary = [];
$res = q($database, "
    SELECT a.status, COUNT(DISTINCT a.id) AS cnt
    FROM assignments asg
    JOIN applications a ON a.id = asg.application_id
    $whereSql
    GROUP BY a.status
    ORDER BY cnt DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $currentStatusSummary[] = $row;

// 1c. Monthly assigned volume (all history, grouped by month)
$monthlyAssigned = [];
$res = q($database, "
    SELECT DATE_FORMAT(asg.assigned_at, '%Y-%m') AS ym, COUNT(*) AS cnt
    FROM assignments asg
    $whereSql
    GROUP BY ym
    ORDER BY ym ASC
", $types, $params);
while ($row = $res->fetch_assoc()) $monthlyAssigned[] = $row;

// 1d. Detail table — every assignment ever given to me

$assignmentDetail = [];
$res = q($database, "
    SELECT asg.id AS assignment_id, asg.status AS assignment_status, asg.assigned_at,
           a.id AS application_id, a.status AS application_status, a.submitted_at,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.position_title, j.worksite_country
    FROM assignments asg
    JOIN applications a ON a.id = asg.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $whereSql
    ORDER BY asg.assigned_at DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $assignmentDetail[] = $row;

/*REPORT 2 — My Decision History  (source: evaluations, every record I made)*/

$evalWhere  = ['e.evaluator_id = ?'];
$evalTypes  = 'i';
$evalParams = [$evaluator_id];
if ($f_month !== '' && preg_match('/^\d{4}-\d{2}$/', $f_month)) {
    $start = $f_month . '-01 00:00:00';
    $end   = date('Y-m-t 23:59:59', strtotime($start));
    $evalWhere[]  = 'e.evaluated_at BETWEEN ? AND ?';
    $evalTypes   .= 'ss';
    $evalParams[] = $start;
    $evalParams[] = $end;
}
$evalWhereSql = whereSql($evalWhere);

$decisionHistory = [];
$res = q($database, "
    SELECT e.id, e.application_id, e.evaluation_type, e.decision, e.comments, e.evaluated_at,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.position_title, j.worksite_country
    FROM evaluations e
    JOIN applications a ON a.id = e.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $evalWhereSql
    ORDER BY e.evaluated_at DESC
", $evalTypes, $evalParams);
while ($row = $res->fetch_assoc()) $decisionHistory[] = $row;

$decisionTypeSummary = [];
$res = q($database, "
    SELECT e.decision, COUNT(*) AS cnt
    FROM evaluations e
    $evalWhereSql
    GROUP BY e.decision
", $evalTypes, $evalParams);
while ($row = $res->fetch_assoc()) $decisionTypeSummary[] = $row;

/* REPORT 3 — My Approval Rate & Turnaround */

$finalWhere  = array_merge($evalWhere, ["e.decision IN ('approved','disapproved')"]);
$finalWhereSql = whereSql($finalWhere);

$res = q($database, "
    SELECT COUNT(*) AS total,
           SUM(e.decision = 'approved') AS approved,
           SUM(e.decision = 'disapproved') AS disapproved,
           AVG(DATEDIFF(e.evaluated_at, a.submitted_at)) AS avg_days,
           MIN(DATEDIFF(e.evaluated_at, a.submitted_at)) AS min_days,
           MAX(DATEDIFF(e.evaluated_at, a.submitted_at)) AS max_days
    FROM evaluations e
    JOIN applications a ON a.id = e.application_id
    $finalWhereSql
", $evalTypes, $evalParams);
$turnaroundStats = $res->fetch_assoc();
$finalTotal      = (int) ($turnaroundStats['total'] ?? 0);
$approvedCount   = (int) ($turnaroundStats['approved'] ?? 0);
$disapprovedCount = (int) ($turnaroundStats['disapproved'] ?? 0);
$approvalRate    = $finalTotal ? round($approvedCount / $finalTotal * 100, 1) : null;
$avgTurnaround   = $turnaroundStats['avg_days'] !== null ? round((float) $turnaroundStats['avg_days'], 1) : null;

// Bounce-back rate — how many times I sent an application back for compliance
$complianceWhere = array_merge($evalWhere, ["e.decision = 'for_compliance'"]);
$complianceWhereSql = whereSql($complianceWhere);
$res = q($database, "
    SELECT COUNT(*) AS cnt
    FROM evaluations e
    $complianceWhereSql
", $evalTypes, $evalParams);
$complianceCount = (int) ($res->fetch_assoc()['cnt'] ?? 0);

// Monthly approved vs disapproved trend
$monthlyDecisions = [];
$res = q($database, "
    SELECT DATE_FORMAT(e.evaluated_at, '%Y-%m') AS ym,
           SUM(e.decision = 'approved') AS approved,
           SUM(e.decision = 'disapproved') AS disapproved
    FROM evaluations e
    $finalWhereSql
    GROUP BY ym
    ORDER BY ym ASC
", $evalTypes, $evalParams);
while ($row = $res->fetch_assoc()) $monthlyDecisions[] = $row;

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

/*REPORT 4 — My Document Compliance
   Required vs. submitted documents, limited to applications ever
   assigned to me (via assignments table), deduplicated per application. */
$docCompliance = [];
$res = q($database, "
    SELECT a.id, a.rsf_id, a.submitted_at,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.employer_name,
           (SELECT COUNT(*) FROM rsf_required_documents rd
              WHERE rd.rsf_id = a.rsf_id AND rd.is_required = 1) AS required_count,
           (SELECT COUNT(DISTINCT rd2.document_type)
              FROM rsf_required_documents rd2
              JOIN application_documents ad
                ON ad.application_id = a.id AND ad.document_type = rd2.document_type
              WHERE rd2.rsf_id = a.rsf_id AND rd2.is_required = 1) AS submitted_count
    FROM assignments asg
    JOIN applications a ON a.id = asg.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $whereSql
    GROUP BY a.id
    ORDER BY a.submitted_at DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $docCompliance[] = $row;

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

// Dock "Apps" badge — my currently active assignments only
$badge_stmt = $database->prepare("SELECT COUNT(*) AS total FROM assignments WHERE evaluator_id = ? AND status = 'active'");
$badge_stmt->bind_param('i', $evaluator_id);
$badge_stmt->execute();
$active_apps = (int) ($badge_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$badge_stmt->close();

$filterRecap = $f_month !== '' ? 'Month: ' . date('F Y', strtotime($f_month . '-01')) : 'All records (no filter applied)';
$generatedAt = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Reports – DMW RO IV-A</title>
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

            /* ---------- Summary cards (class names are read by the Excel export) ---------- */
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
         Kept as plain CSS on purpose: the JS print/PDF/Excel code and ../css/dashboard.css depend on these
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
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; EVALUATOR PORTAL</p>
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
                <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars(explode(' ', trim($evaluator_name))[0]) ?></h2>
                <p>Filter your report below, then use the toolbar to print or export</p>
            </div>
        </div>
        <form class="filter-scroll" method="GET" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" id="reportFilterForm">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select name="month" id="filter-month" class="cursor-pointer border-none bg-transparent text-xs font-semibold text-inherit outline-none">
                    <option value="">All Time</option>
                    <?php foreach ($monthOptions as $ym): ?>
                        <option value="<?= htmlspecialchars($ym) ?>" <?= $ym === $f_month ? 'selected' : '' ?>>
                            <?= htmlspecialchars(date('M Y', strtotime($ym . '-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="filter-pill cursor-pointer border-none">
                <i class="fas fa-filter" style="color:var(--blue-lt)"></i>
                <span class="text-[11px] font-semibold">Apply Filter</span>
            </button>
            <?php if ($f_month !== ''): ?>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="filter-pill no-underline">
                    <i class="fas fa-xmark" style="color:var(--red)"></i>
                    <span class="text-[11px] font-semibold">Clear</span>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="mx-auto my-4 max-w-[1000px] px-3 pb-28 sm:my-5 sm:px-4 print:m-0 print:max-w-full print:p-0">

        <!-- Toolbar: grouped 3-up grid on mobile, single wrapping row from sm up -->
        <div class="no-print mb-4 grid grid-cols-1 gap-3 sm:flex sm:flex-wrap sm:gap-2">
            <a href="index.php" class="btn w-full sm:w-auto"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

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

        <!-- Tabs: 2x2 grid on mobile, pill row from sm up -->
        <div class="no-print mb-4 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" id="tabs">
            <button class="tab-btn active" data-target="rep-workload">My Workload Summary</button>
            <button class="tab-btn" data-target="rep-decisions">My Decision History</button>
            <button class="tab-btn" data-target="rep-rate">My Approval Rate &amp; Turnaround</button>
            <button class="tab-btn" data-target="rep-doccompliance">My Document Compliance</button>
        </div>

        <div class="webView">
            <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-8 dark:border-slate-700 dark:bg-slate-800">

                <div class="mb-1 flex items-center gap-3 border-b-[3px] border-navy pb-3 sm:gap-4 dark:border-blue-500">
                    <img src="../pictures/dmw_logo.png" alt="DMW logo" class="h-auto w-10 shrink-0 sm:w-12">
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

                <h2 class="mb-1 mt-5 text-center text-base font-extrabold uppercase tracking-wide text-navy sm:text-lg dark:text-blue-400">My Application Reports</h2>
                <p class="mb-4 text-center text-xs text-slate-600 dark:text-slate-400"><?= htmlspecialchars($filterRecap) ?></p>

                <table class="mb-5 w-full border-collapse text-xs">
                    <tr>
                        <td class="w-24 py-0.5 align-top font-bold sm:w-40">Generated:</td>
                        <td class="py-0.5"><?= htmlspecialchars($generatedAt) ?></td>
                    </tr>
                    <tr>
                        <td class="w-24 py-0.5 align-top font-bold sm:w-40">Evaluator:</td>
                        <td class="py-0.5"><?= htmlspecialchars($evaluator_name) ?></td>
                    </tr>
                    <tr>
                        <td class="w-24 py-0.5 align-top font-bold sm:w-40">Office:</td>
                        <td class="py-0.5">DMW Regional Office IV-A (CALABARZON)</td>
                    </tr>
                </table>

                <p class="mb-4 text-[12.5px] leading-relaxed text-slate-600 sm:text-justify dark:text-slate-400">
                    This report consolidates your personal evaluation workload and performance statistics processed
                    through the DMW Application Management System for Regional Office IV-A. Figures reflect the filter
                    selected above and are intended for self-monitoring and official case review.
                </p>

                <div class="summary-panel">
                    <div class="summary-cell">
                        <div class="sc-num"><?= $totalEverAssigned ?></div>
                        <div class="sc-lbl">Total Assignments</div>
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
                        <div class="sc-num"><?= $complianceCount ?></div>
                        <div class="sc-lbl">Sent For Compliance</div>
                    </div>
                    <div class="summary-cell <?= $docAvgCompletion < 100 ? 'sc-warn' : '' ?>">
                        <div class="sc-num"><?= $docAvgCompletion ?>%</div>
                        <div class="sc-lbl">Avg. Docs Complete</div>
                    </div>
                </div>

                <!-- ===================== I. WORKLOAD ===================== -->
                <section class="report-section active" id="rep-workload">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-briefcase"></i> I. My Workload Summary</div>
                        <p class="section-hint">Every application ever assigned to me, whether still active, completed, or reassigned to someone else.</p>
                        <div class="summary-panel">
                            <div class="summary-cell">
                                <div class="sc-num"><?= $totalEverAssigned ?></div>
                                <div class="sc-lbl">Total Assignments</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $distinctApplications ?></div>
                                <div class="sc-lbl">Distinct Applications</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $assignmentStatusSummary['active'] ?? 0 ?></div>
                                <div class="sc-lbl">Currently Active</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $assignmentStatusSummary['completed'] ?? 0 ?></div>
                                <div class="sc-lbl">Completed</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $assignmentStatusSummary['reassigned'] ?? 0 ?></div>
                                <div class="sc-lbl">Reassigned Away</div>
                            </div>
                        </div>
                        <div class="no-print mx-auto mb-5 h-64 max-w-md sm:h-auto"><canvas id="chartMonthlyAssigned"></canvas></div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-layer-group"></i> Current Status of My Applications</div>
                        <div class="overflow-x-auto">
                            <table class="report-table detail-table" id="tbl-current-status">
                                <thead>
                                    <tr>
                                        <th>Application Status</th>
                                        <th>Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$currentStatusSummary): ?>
                                        <tr class="empty-row">
                                            <td colspan="2">No applications have been assigned to you yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($currentStatusSummary as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s['status']))) ?></td>
                                            <td><?= (int) $s['cnt'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-list-check"></i> Assignment Detail</div>
                        <p class="section-hint">Every assignment record ever given to me, most recent first.</p>

                        <!-- Desktop / tablet table (also the Excel export source) -->
                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-assignment-detail">
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>RSF #</th>
                                        <th>Position</th>
                                        <th>Country</th>
                                        <th>Assignment Status</th>
                                        <th>Application Status</th>
                                        <th>Assigned At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$assignmentDetail): ?>
                                        <tr class="empty-row">
                                            <td colspan="7">No assignments found for the selected filter.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($assignmentDetail as $d): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(fullName($d)) ?></td>
                                            <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                            <td><?= htmlspecialchars($d['position_title']) ?></td>
                                            <td><?= htmlspecialchars($d['worksite_country']) ?></td>
                                            <td><span class="badge <?= $d['assignment_status'] === 'active' ? 'b-pending' : ($d['assignment_status'] === 'completed' ? 'b-approved' : 'b-other') ?>"><?= htmlspecialchars(ucfirst($d['assignment_status'])) ?></span></td>
                                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['application_status']))) ?></td>
                                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['assigned_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile cards -->
                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$assignmentDetail): ?>
                                <p class="empty-note">No assignments found for the selected filter.</p>
                            <?php endif; ?>
                            <?php foreach ($assignmentDetail as $d): ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-bold"><?= htmlspecialchars(fullName($d)) ?></p>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($d['rsf_number']) ?></p>
                                        </div>
                                        <span class="badge <?= $d['assignment_status'] === 'active' ? 'b-pending' : ($d['assignment_status'] === 'completed' ? 'b-approved' : 'b-other') ?>"><?= htmlspecialchars(ucfirst($d['assignment_status'])) ?></span>
                                    </div>
                                    <div class="m-row"><span class="m-key">Position</span><span class="m-val"><?= htmlspecialchars($d['position_title']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Country</span><span class="m-val"><?= htmlspecialchars($d['worksite_country']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Application</span><span class="m-val"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['application_status']))) ?></span></div>
                                    <div class="m-row"><span class="m-key">Assigned</span><span class="m-val"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['assigned_at']))) ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===================== II. DECISIONS ===================== -->
                <section class="report-section" id="rep-decisions">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-clipboard-check"></i> II. My Decision History</div>
                        <p class="section-hint">Every evaluation record I have ever submitted — initial decisions and compliance reviews alike.</p>
                        <div class="summary-panel">
                            <?php if (!$decisionTypeSummary): ?>
                                <div class="summary-cell">
                                    <div class="sc-num">0</div>
                                    <div class="sc-lbl">No Records</div>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($decisionTypeSummary as $d): ?>
                                <div class="summary-cell">
                                    <div class="sc-num"><?= (int) $d['cnt'] ?></div>
                                    <div class="sc-lbl"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? 'Unspecified'))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="hidden overflow-x-auto md:block">
                            <table class="report-table detail-table" id="tbl-decision-history">
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>Position / Country</th>
                                        <th>Type</th>
                                        <th>Decision</th>
                                        <th>Comments</th>
                                        <th>Evaluated At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$decisionHistory): ?>
                                        <tr class="empty-row">
                                            <td colspan="6">No evaluation records found for the selected filter.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($decisionHistory as $d): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(fullName($d)) ?></td>
                                            <td><?= htmlspecialchars($d['position_title']) ?> — <?= htmlspecialchars($d['worksite_country']) ?></td>
                                            <td><?= htmlspecialchars(ucfirst($d['evaluation_type'] ?? '')) ?></td>
                                            <td><span class="badge <?= decisionBadgeClass($d['decision']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? '—'))) ?></span></td>
                                            <td><?= htmlspecialchars($d['comments'] ?: '—') ?></td>
                                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['evaluated_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$decisionHistory): ?>
                                <p class="empty-note">No evaluation records found for the selected filter.</p>
                            <?php endif; ?>
                            <?php foreach ($decisionHistory as $d): ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <p class="min-w-0 break-words text-sm font-bold"><?= htmlspecialchars(fullName($d)) ?></p>
                                        <span class="badge <?= decisionBadgeClass($d['decision']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? '—'))) ?></span>
                                    </div>
                                    <div class="m-row"><span class="m-key">Position</span><span class="m-val"><?= htmlspecialchars($d['position_title']) ?> — <?= htmlspecialchars($d['worksite_country']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Type</span><span class="m-val"><?= htmlspecialchars(ucfirst($d['evaluation_type'] ?? '')) ?></span></div>
                                    <div class="m-row"><span class="m-key">Comments</span><span class="m-val"><?= htmlspecialchars($d['comments'] ?: '—') ?></span></div>
                                    <div class="m-row"><span class="m-key">Evaluated</span><span class="m-val"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['evaluated_at']))) ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===================== III. APPROVAL RATE ===================== -->
                <section class="report-section" id="rep-rate">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-gauge-high"></i> III. My Approval Rate &amp; Turnaround</div>
                        <p class="section-hint">Based on final decisions only (approved / disapproved). Turnaround = days between an application's submission and my final decision.</p>
                        <div class="summary-panel">
                            <div class="summary-cell">
                                <div class="sc-num"><?= $finalTotal ?></div>
                                <div class="sc-lbl">Final Decisions Made</div>
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
                                <div class="sc-num"><?= $approvalRate !== null ? $approvalRate . '%' : '—' ?></div>
                                <div class="sc-lbl">Approval Rate</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $avgTurnaround !== null ? $avgTurnaround : '—' ?></div>
                                <div class="sc-lbl">Avg. Turnaround (days)</div>
                            </div>
                            <div class="summary-cell">
                                <div class="sc-num"><?= $complianceCount ?></div>
                                <div class="sc-lbl">Sent Back For Compliance</div>
                            </div>
                        </div>
                        <div class="no-print mx-auto mb-5 max-w-[300px] sm:max-w-sm"><canvas id="chartApprovalRate"></canvas></div>
                    </div>

                    <div class="section">
                        <div class="section-title"><i class="fas fa-chart-line"></i> Monthly Decisions</div>
                        <div class="overflow-x-auto">
                            <table class="report-table detail-table" id="tbl-monthly-decisions">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Approved</th>
                                        <th>Disapproved</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$monthlyDecisions): ?>
                                        <tr class="empty-row">
                                            <td colspan="4">No final decisions found for the selected filter.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($monthlyDecisions as $m): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('M Y', strtotime($m['ym'] . '-01'))) ?></td>
                                            <td><?= (int) $m['approved'] ?></td>
                                            <td><?= (int) $m['disapproved'] ?></td>
                                            <td><?= (int) $m['approved'] + (int) $m['disapproved'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="section-hint mt-2.5">
                            Fastest decision: <b><?= $turnaroundStats['min_days'] ?? '—' ?> day(s)</b> &nbsp;•&nbsp;
                            Slowest decision: <b><?= $turnaroundStats['max_days'] ?? '—' ?> day(s)</b>
                        </p>
                    </div>
                </section>

                <!-- ===================== IV. DOCUMENT COMPLIANCE ===================== -->
                <section class="report-section" id="rep-doccompliance">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-file-circle-check"></i> IV. My Document Compliance</div>
                        <p class="section-hint">Required vs. submitted documents for applications that have been assigned to me.</p>
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
                                            <td colspan="7">No applications have been assigned to you yet for the selected filter.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($docCompliance as $d): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(fullName($d)) ?></td>
                                            <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                            <td><?= htmlspecialchars($d['employer_name']) ?></td>
                                            <td><?= (int) $d['required_count'] ?></td>
                                            <td><?= (int) $d['submitted_count'] ?></td>
                                            <td><span class="badge <?= $d['completion_pct'] == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= (int) $d['completion_pct'] ?>%</span></td>
                                            <td><?= htmlspecialchars($d['missing_documents']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="space-y-2.5 md:hidden">
                            <?php if (!$docCompliance): ?>
                                <p class="empty-note">No applications have been assigned to you yet for the selected filter.</p>
                            <?php endif; ?>
                            <?php foreach ($docCompliance as $d): ?>
                                <article class="m-card">
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-bold"><?= htmlspecialchars(fullName($d)) ?></p>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($d['rsf_number']) ?></p>
                                        </div>
                                        <span class="badge <?= $d['completion_pct'] == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= (int) $d['completion_pct'] ?>%</span>
                                    </div>
                                    <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $d['completion_pct'] ?>">
                                        <div class="h-full rounded-full <?= $d['completion_pct'] == 100 ? 'bg-emerald-500' : 'bg-red-500' ?>" style="width:<?= (int) $d['completion_pct'] ?>%"></div>
                                    </div>
                                    <div class="m-row"><span class="m-key">Employer</span><span class="m-val"><?= htmlspecialchars($d['employer_name']) ?></span></div>
                                    <div class="m-row"><span class="m-key">Submitted</span><span class="m-val"><?= (int) $d['submitted_count'] ?> of <?= (int) $d['required_count'] ?> required</span></div>
                                    <div class="m-row"><span class="m-key">Missing</span><span class="m-val"><?= htmlspecialchars($d['missing_documents']) ?></span></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <p class="mt-6 border-t border-slate-200 pt-2.5 text-center text-[10px] leading-relaxed text-slate-400 dark:border-slate-700">Department of Migrant Workers — Application Management System &nbsp;|&nbsp; Regional Office IV-A (CALABARZON) &nbsp;|&nbsp; For official use only</p>

            </div>
        </div>

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
                <h1>My Application Reports</h1>
                <p><?= htmlspecialchars($filterRecap) ?></p>
            </div>

            <div class="pr-meta-bar">
                <span>Generated: <?= htmlspecialchars($generatedAt) ?></span>
                <span>Evaluator: <?= htmlspecialchars($evaluator_name) ?></span>
                <span>DMW RO IV-A</span>
            </div>

            <p class="pr-intro">
                This report consolidates your personal evaluation workload and performance statistics processed
                through the DMW Application Management System for Regional Office IV-A. Figures reflect the filter
                selected at generation time and are intended for self-monitoring and official case review.
            </p>

            <table class="pr-summary-panel">
                <tr>
                    <td>
                        <span class="sc-num"><?= $totalEverAssigned ?></span>
                        <span class="sc-lbl">Total Assignments</span>
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
                        <span class="sc-num"><?= $complianceCount ?></span>
                        <span class="sc-lbl">Sent For Compliance</span>
                    </td>
                    <td>
                        <span class="sc-num"><?= $docAvgCompletion ?>%</span>
                        <span class="sc-lbl">Avg. Docs Complete</span>
                    </td>
                </tr>
            </table>

            <div class="pr-report-block active" data-target="rep-workload">
                <div class="pr-section">
                    <div class="pr-section-title">I. My Workload Summary</div>
                    <p class="pr-section-hint">
                        Total assignments: <b><?= $totalEverAssigned ?></b>
                        — Distinct applications: <b><?= $distinctApplications ?></b>
                        — Active: <b><?= $assignmentStatusSummary['active'] ?? 0 ?></b>
                        — Completed: <b><?= $assignmentStatusSummary['completed'] ?? 0 ?></b>
                        — Reassigned away: <b><?= $assignmentStatusSummary['reassigned'] ?? 0 ?></b>
                    </p>
                    <?php if (!$currentStatusSummary): ?>
                        <p class="pr-empty">No applications have been assigned to you yet.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Application Status</th>
                                    <th>Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentStatusSummary as $s): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s['status']))) ?></td>
                                        <td><?= (int) $s['cnt'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="pr-section">
                    <div class="pr-section-title">Assignment Detail</div>
                    <?php if (!$assignmentDetail): ?>
                        <p class="pr-empty">No assignments found for the selected filter.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF #</th>
                                    <th>Position</th>
                                    <th>Country</th>
                                    <th>Assignment Status</th>
                                    <th>Application Status</th>
                                    <th>Assigned At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignmentDetail as $d): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(fullName($d)) ?></td>
                                        <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                        <td><?= htmlspecialchars($d['position_title']) ?></td>
                                        <td><?= htmlspecialchars($d['worksite_country']) ?></td>
                                        <td><span class="<?= $d['assignment_status'] === 'completed' ? 'pr-badge-ok' : ($d['assignment_status'] === 'reassigned' ? 'pr-badge-bad' : '') ?>"><?= htmlspecialchars(ucfirst($d['assignment_status'])) ?></span></td>
                                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['application_status']))) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['assigned_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-decisions">
                <div class="pr-section">
                    <div class="pr-section-title">II. My Decision History</div>
                    <p class="pr-section-hint">
                        <?php if (!$decisionTypeSummary): ?>
                            No evaluation records found for the selected filter.
                        <?php else: ?>
                            <?php foreach ($decisionTypeSummary as $i => $d): ?><?= $i > 0 ? ' — ' : '' ?><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? 'Unspecified'))) ?>: <b><?= (int) $d['cnt'] ?></b><?php endforeach; ?>
                    <?php endif; ?>
                    </p>
                    <?php if (!$decisionHistory): ?>
                        <p class="pr-empty">No evaluation records found for the selected filter.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Position / Country</th>
                                    <th>Type</th>
                                    <th>Decision</th>
                                    <th>Comments</th>
                                    <th>Evaluated At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decisionHistory as $d): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(fullName($d)) ?></td>
                                        <td><?= htmlspecialchars($d['position_title']) ?> — <?= htmlspecialchars($d['worksite_country']) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($d['evaluation_type'] ?? '')) ?></td>
                                        <td><span class="<?= $d['decision'] === 'approved' ? 'pr-badge-ok' : ($d['decision'] === 'disapproved' ? 'pr-badge-bad' : '') ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? '—'))) ?></span></td>
                                        <td><?= htmlspecialchars($d['comments'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['evaluated_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-rate">
                <div class="pr-section">
                    <div class="pr-section-title">III. My Approval Rate &amp; Turnaround</div>
                    <p class="pr-section-hint">
                        Final decisions: <b><?= $finalTotal ?></b>
                        — Approved: <span class="pr-badge-ok"><?= $approvedCount ?></span>
                        — Disapproved: <span class="pr-badge-bad"><?= $disapprovedCount ?></span>
                        — Approval rate: <b><?= $approvalRate !== null ? $approvalRate . '%' : '—' ?></b>
                        — Avg. turnaround: <b><?= $avgTurnaround !== null ? $avgTurnaround . ' day(s)' : '—' ?></b>
                        — Sent back for compliance: <b><?= $complianceCount ?></b>
                    </p>
                    <?php if (!$monthlyDecisions): ?>
                        <p class="pr-empty">No final decisions found for the selected filter.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Approved</th>
                                    <th>Disapproved</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlyDecisions as $m): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('M Y', strtotime($m['ym'] . '-01'))) ?></td>
                                        <td><?= (int) $m['approved'] ?></td>
                                        <td><?= (int) $m['disapproved'] ?></td>
                                        <td><?= (int) $m['approved'] + (int) $m['disapproved'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="pr-section-hint" style="margin-top:6px">
                        Fastest decision: <b><?= $turnaroundStats['min_days'] ?? '—' ?> day(s)</b>
                        — Slowest decision: <b><?= $turnaroundStats['max_days'] ?? '—' ?> day(s)</b>
                    </p>
                </div>
            </div>

            <div class="pr-report-block" data-target="rep-doccompliance">
                <div class="pr-section">
                    <div class="pr-section-title">IV. My Document Compliance</div>
                    <p class="pr-section-hint">
                        Applications checked: <b><?= count($docCompliance) ?></b>
                        — Fully compliant: <span class="pr-badge-ok"><?= $docCompleteCount ?></span>
                        — Incomplete: <span class="pr-badge-bad"><?= $docIncompleteCount ?></span>
                        — Avg. completion: <b><?= $docAvgCompletion ?>%</b>
                    </p>
                    <?php if (!$docCompliance): ?>
                        <p class="pr-empty">No applications have been assigned to you yet for the selected filter.</p>
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
                                        <td><?= (int) $d['required_count'] ?></td>
                                        <td><?= (int) $d['submitted_count'] ?></td>
                                        <td><span class="<?= $d['completion_pct'] == 100 ? 'pr-badge-ok' : 'pr-badge-bad' ?>"><?= (int) $d['completion_pct'] ?>%</span></td>
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
                    <div class="line">Prepared by / Evaluator</div>
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
                        <span>My Application Reports</span>
                        <span>System-Generated Document • For Official Use Only</span>
                        <span><?= htmlspecialchars($generatedAt) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const REPORT_TITLES = {
            'rep-workload': 'My Workload Summary',
            'rep-decisions': 'My Decision History',
            'rep-rate': 'My Approval Rate and Turnaround',
            'rep-doccompliance': 'My Document Compliance'
        };

        /*  Filename helper — evaluator name, filesystem-safe  */
        const EVALUATOR_FILE_SLUG = <?= json_encode($evaluator_name) ?>
            .replace(/[\\\/:*?"<>|]/g, '')
            .trim()
            .replace(/\s+/g, '_');

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
        const REPORT_META = <?= json_encode([
                                'filters'     => $filterRecap,
                                'generatedAt' => $generatedAt,
                                'preparedBy'  => $evaluator_name,
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        const XL_LOGO_URL = '../pictures/dmw_logo.png';

        const XL = {
            navy: 'FF0B1F4B',
            white: 'FFFFFFFF',
            zebra: 'FFF8FAFC',
            border: 'FFD1D5DB',
            grey: 'FF6B7280',
            text: 'FF1F2937',
            totalBg: 'FFE9EDF7'
        };
        // [background, text] colors, keyed by lower-cased cell text
        const XL_GOOD = ['FFD1FAE5', 'FF047857'];
        const XL_BAD = ['FFFEE2E2', 'FFB91C1C'];
        const XL_WARN = ['FFFEF3C7', 'FFB45309'];
        const XL_INFO = ['FFE0E7FF', 'FF3730A3'];
        const XL_BADGE_TEXT = {
            'approved': XL_GOOD,
            'completed': XL_GOOD,
            'disapproved': XL_BAD,
            'pending': XL_WARN,
            'active': XL_WARN,
            'under evaluation': XL_INFO,
            'reassigned': XL_INFO,
            'for compliance': ['FFFFEDD5', 'FFC2410C'],
            'resubmitted': ['FFFCE7F3', 'FF9D174D']
        };
        // Columns that get badge colors / are numeric / are percentages (matched by header text)
        const XL_BADGE_HEADERS = ['assignment status', 'application status', 'decision'];
        const XL_NUMERIC_HEADERS = ['count', 'required', 'submitted', 'approved', 'disapproved', 'total'];
        const XL_PERCENT_HEADERS = ['completion'];

        const XL_TABLE_TITLES = {
            'tbl-current-status': 'Current Status',
            'tbl-assignment-detail': 'Assignment Detail',
            'tbl-decision-history': 'Decision History',
            'tbl-monthly-decisions': 'Monthly Decisions',
            'tbl-doccompliance': 'Document Compliance'
        };
        const XL_SECTION_SHORT = {
            'rep-workload': 'Workload',
            'rep-decisions': 'Decisions',
            'rep-rate': 'Rate',
            'rep-doccompliance': 'Doc Compliance'
        };

        const xlThin = () => ({
            style: 'thin',
            color: {
                argb: XL.border
            }
        });
        const xlBorders = () => ({
            top: xlThin(),
            left: xlThin(),
            bottom: xlThin(),
            right: xlThin()
        });
        const xlFill = argb => ({
            type: 'pattern',
            pattern: 'solid',
            fgColor: {
                argb
            }
        });
        const xlClean = s => String(s || '').replace(/\s+/g, ' ').trim();

        function xlUniqueName(wb, base) {
            let name = safeSheetName(base) || 'Sheet';
            let n = 2;
            while (wb.getWorksheet(name)) {
                const suffix = ' ' + n++;
                name = safeSheetName(base).substring(0, 31 - suffix.length) + suffix;
            }
            return name;
        }

        function xlLetterhead(ws, logo, N) {
            const lines = [
                ['Republic of the Philippines', 9, false, XL.grey, 14],
                ['DEPARTMENT OF MIGRANT WORKERS', 15, true, XL.navy, 24],
                ['Regional Office IV-A (CALABARZON)', 9.5, false, XL.grey, 14],
                ['Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027  |  calamba@dmw.gov.ph  |  dmw.gov.ph', 8, false, XL.grey, 14]
            ];
            lines.forEach(([text, size, bold, color, h], i) => {
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
            if (logo) ws.addImage(logo.id, {
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

        function xlMergedLine(ws, rowNo, lastCol, text, font, h) {
            ws.mergeCells(rowNo, 1, rowNo, lastCol);
            const c = ws.getCell(rowNo, 1);
            c.value = text;
            c.font = font;
            c.alignment = {
                horizontal: 'center',
                vertical: 'middle',
                wrapText: true
            };
            ws.getRow(rowNo).height = h;
        }

        function xlPageSetup(ws, landscape) {
            ws.pageSetup = {
                orientation: landscape ? 'landscape' : 'portrait',
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
            };
            ws.headerFooter.oddFooter = '&L&8DMW RO IV-A - For Official Use Only&R&8Page &P of &N';
        }

        async function xlLoadLogo(wb) {
            try {
                const dataUrl = await toDataURL(XL_LOGO_URL); // existing helper in this file
                const img = await loadImage(dataUrl); // existing helper in this file
                return {
                    id: wb.addImage({
                        base64: dataUrl.split(',')[1],
                        extension: 'png'
                    }),
                    ratio: img.naturalWidth / img.naturalHeight
                };
            } catch (err) {
                console.warn('Logo could not be embedded in the Excel file.', err);
                return null;
            }
        }

        /* Reads a rendered <table> into { headers, rows, empty } */
        function xlReadTable(tbl) {
            const headers = Array.from(tbl.querySelectorAll('thead th')).map(th => xlClean(th.textContent));
            const rows = [];
            let empty = false;
            tbl.querySelectorAll('tbody tr').forEach(tr => {
                if (tr.classList.contains('empty-row')) {
                    empty = true;
                    return;
                }
                rows.push(Array.from(tr.children).map(td => xlClean(td.textContent)));
            });
            return {
                headers,
                rows,
                empty: empty && !rows.length
            };
        }

        function xlAddTableSheet(wb, logo, tbl, sheetName, reportTitle) {
            const {
                headers,
                rows,
                empty
            } = xlReadTable(tbl);
            const N = headers.length;
            const M = Math.min(N, 8);
            const narrow = M < 4;

            const ws = wb.addWorksheet(xlUniqueName(wb, sheetName), {
                properties: {
                    tabColor: {
                        argb: XL.navy
                    }
                },
                views: [{
                    showGridLines: false
                }]
            });
            xlPageSetup(ws, N > 3);
            xlLetterhead(ws, logo, N);
            xlMergedLine(ws, 5, M, reportTitle.toUpperCase(), {
                name: 'Calibri',
                size: 15,
                bold: true,
                color: {
                    argb: XL.navy
                }
            }, 26);
            xlMergedLine(ws, 6, M,
                `Filters: ${REPORT_META.filters}\nGenerated: ${REPORT_META.generatedAt}   |   Prepared by: ${REPORT_META.preparedBy}   |   Total records: ${rows.length}`, {
                    name: 'Calibri',
                    size: 9,
                    italic: true,
                    color: {
                        argb: XL.grey
                    }
                }, narrow ? 54 : 30);

            const HR = 8;
            const hr = ws.getRow(HR);
            hr.height = 30;
            headers.forEach((h, i) => {
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
                c.fill = xlFill(XL.navy);
                c.alignment = {
                    horizontal: 'center',
                    vertical: 'middle',
                    wrapText: true
                };
                c.border = xlBorders();
            });

            const widths = headers.map(h => h.length + 4);
            rows.forEach((cells, idx) => {
                const row = ws.getRow(HR + 1 + idx);
                row.height = 20;
                cells.forEach((txtRaw, i) => {
                    const hKey = (headers[i] || '').toLowerCase();
                    const c = row.getCell(i + 1);
                    const txt = txtRaw === '' ? '—' : txtRaw;
                    let isNum = false;

                    if (XL_PERCENT_HEADERS.includes(hKey) && /^\d+(\.\d+)?%$/.test(txt)) {
                        c.value = parseFloat(txt) / 100;
                        c.numFmt = '0%';
                        isNum = true;
                    } else if (XL_NUMERIC_HEADERS.includes(hKey) && /^\d+$/.test(txt)) {
                        c.value = parseInt(txt, 10);
                        c.numFmt = '0';
                        isNum = true;
                    } else {
                        c.value = txt; // plain string → can never run as a formula
                        widths[i] = Math.max(widths[i], Math.min(40, txt.length + 3));
                    }

                    c.font = {
                        name: 'Calibri',
                        size: 10,
                        color: {
                            argb: XL.text
                        }
                    };
                    c.alignment = {
                        vertical: 'middle',
                        horizontal: isNum ? 'center' : 'left',
                        wrapText: false
                    };
                    c.border = xlBorders();
                    if (idx % 2 === 1) c.fill = xlFill(XL.zebra);

                    // Status / decision badge colors
                    if (XL_BADGE_HEADERS.includes(hKey) && XL_BADGE_TEXT[txt.toLowerCase()]) {
                        const [bg, fg] = XL_BADGE_TEXT[txt.toLowerCase()];
                        c.fill = xlFill(bg);
                        c.font = {
                            name: 'Calibri',
                            size: 10,
                            bold: true,
                            color: {
                                argb: fg
                            }
                        };
                        c.alignment = {
                            vertical: 'middle',
                            horizontal: 'center'
                        };
                    }
                    // Completion: green ≥ 80%, amber ≥ 50%, red below
                    if (XL_PERCENT_HEADERS.includes(hKey) && isNum) {
                        const v = c.value;
                        const [bg, fg] = v >= 0.8 ? XL_GOOD : (v >= 0.5 ? XL_WARN : XL_BAD);
                        c.fill = xlFill(bg);
                        c.font = {
                            name: 'Calibri',
                            size: 10,
                            bold: true,
                            color: {
                                argb: fg
                            }
                        };
                    }
                });
            });
            widths.forEach((w, i) => ws.getColumn(i + 1).width = Math.max(11, w));
            ws.getColumn(1).width = Math.max(ws.getColumn(1).width, 26);

            if (!rows.length) {
                ws.mergeCells(HR + 1, 1, HR + 1, M);
                const c = ws.getCell(HR + 1, 1);
                c.value = empty ? 'No records found for the selected filter.' : 'No data.';
                c.font = {
                    name: 'Calibri',
                    size: 10,
                    italic: true,
                    color: {
                        argb: XL.grey
                    }
                };
                c.alignment = {
                    horizontal: 'center'
                };
            } else {
                ws.autoFilter = {
                    from: {
                        row: HR,
                        column: 1
                    },
                    to: {
                        row: HR + rows.length,
                        column: N
                    }
                };
            }
            ws.views = [{
                state: 'frozen',
                xSplit: 1,
                ySplit: HR,
                showGridLines: false
            }];

            const lastRow = HR + Math.max(rows.length, 1) + 2;
            ws.mergeCells(lastRow, 1, lastRow, M);
            const f = ws.getCell(lastRow, 1);
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
                horizontal: 'center',
                vertical: 'middle',
                wrapText: true
            };
            ws.getRow(lastRow).height = narrow ? 36 : 16;
            ws.pageSetup.printArea = `A1:${ws.getColumn(N).letter}${lastRow}`;
            ws.pageSetup.printTitlesRow = `${HR}:${HR}`;
        }

        /* "Summary" sheet built from the section's on-screen summary cards */
        function xlAddSummarySheet(wb, logo, sectionId) {
            const panel = document.querySelector(`#${sectionId} .summary-panel`);
            if (!panel) return;
            const items = Array.from(panel.querySelectorAll('.summary-cell')).map(cell => ({
                label: xlClean(cell.querySelector('.sc-lbl')?.textContent),
                value: xlClean(cell.querySelector('.sc-num')?.textContent)
            })).filter(i => i.label);
            if (!items.length) return;

            const ss = wb.addWorksheet(xlUniqueName(wb, 'Summary - ' + XL_SECTION_SHORT[sectionId]), {
                properties: {
                    tabColor: {
                        argb: 'FF3B82F6'
                    }
                },
                views: [{
                    showGridLines: false
                }]
            });
            xlPageSetup(ss, false);
            ss.getColumn(1).width = 36;
            ss.getColumn(2).width = 18;
            xlLetterhead(ss, logo, 2);
            xlMergedLine(ss, 5, 2, (REPORT_TITLES[sectionId] + ' — SUMMARY').toUpperCase(), {
                name: 'Calibri',
                size: 14,
                bold: true,
                color: {
                    argb: XL.navy
                }
            }, 26);
            xlMergedLine(ss, 6, 2, `Filters: ${REPORT_META.filters}\nGenerated: ${REPORT_META.generatedAt}`, {
                name: 'Calibri',
                size: 9,
                italic: true,
                color: {
                    argb: XL.grey
                }
            }, 40);

            const sh = ss.getRow(8);
            sh.height = 24;
            ['Metric', 'Value'].forEach((h, i) => {
                const c = sh.getCell(i + 1);
                c.value = h;
                c.font = {
                    name: 'Calibri',
                    size: 10,
                    bold: true,
                    color: {
                        argb: XL.white
                    }
                };
                c.fill = xlFill(XL.navy);
                c.alignment = {
                    horizontal: 'center',
                    vertical: 'middle'
                };
                c.border = xlBorders();
            });
            items.forEach((it, i) => {
                const r = ss.getRow(9 + i);
                r.height = 20;
                const a = r.getCell(1),
                    b = r.getCell(2);
                a.value = it.label;
                const isPct = /^\d+(\.\d+)?%$/.test(it.value);
                if (isPct) {
                    b.value = parseFloat(it.value) / 100;
                    b.numFmt = '0.0%';
                } else if (/^\d+(\.\d+)?$/.test(it.value)) b.value = parseFloat(it.value);
                else b.value = it.value || '—';
                [a, b].forEach((c, j) => {
                    c.font = {
                        name: 'Calibri',
                        size: 10,
                        bold: j === 1,
                        color: {
                            argb: XL.text
                        }
                    };
                    c.alignment = {
                        vertical: 'middle',
                        horizontal: j === 0 ? 'left' : 'center',
                        indent: j === 0 ? 1 : 0
                    };
                    c.border = xlBorders();
                    c.fill = xlFill(i % 2 === 1 ? XL.zebra : XL.white);
                });
            });
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

        async function xlBuildWorkbook(sectionIds) {
            const wb = new ExcelJS.Workbook();
            wb.creator = REPORT_META.preparedBy;
            wb.company = 'DMW Regional Office IV-A';
            wb.created = new Date();
            const logo = await xlLoadLogo(wb);

            sectionIds.forEach(id => {
                xlAddSummarySheet(wb, logo, id);
                tablesInSection(id).forEach(tbl => {
                    const title = XL_TABLE_TITLES[tbl.id] || REPORT_TITLES[id];
                    xlAddTableSheet(wb, logo, tbl, title, `${REPORT_TITLES[id]} — ${title}`);
                });
            });
            return wb;
        }

        async function exportCurrent() {
            const id = activeSectionId();
            if (!tablesInSection(id).length) return;
            const wb = await xlBuildWorkbook([id]);
            await xlSave(wb, `${EVALUATOR_FILE_SLUG}_${REPORT_TITLES[id].replace(/\s+/g, '_')}.xlsx`);
        }

        async function exportAll() {
            const ids = Array.from(document.querySelectorAll('.report-section')).map(s => s.id);
            const wb = await xlBuildWorkbook(ids);
            await xlSave(wb, `${EVALUATOR_FILE_SLUG}_My_Reports.xlsx`);
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

            // Load + downscale the logo once so it can be stamped on every page cheaply
            let logoData = null;
            let logoW = 0,
                logoH = 15; // mm
            if (originalLogoSrc) {
                try {
                    const raw = await toDataURL(originalLogoSrc);
                    printLogo.src = raw;
                    const img = await loadImage(raw);
                    logoW = logoH * (img.naturalWidth / img.naturalHeight);

                    const targetH = 180; // 15 mm needs only ~180 px
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
                        `DMW RO IV-A My Reports | Page ${i} of ${totalPages}`,
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
                await generateOfficialPdf(`${EVALUATOR_FILE_SLUG}_${REPORT_TITLES[id].replace(/\s+/g, '_')}.pdf`, false);
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
                await generateOfficialPdf(`${EVALUATOR_FILE_SLUG}_My_Reports.pdf`, true);
            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        /*  Charts  */
        const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
        const chartText = isDarkTheme ? '#cbd5e1' : '#475569';
        const chartGrid = isDarkTheme ? '#2d3748' : '#f1f5f9';
        const chartBorder = isDarkTheme ? '#0f172a' : '#ffffff';

        const monthlyLabels = <?= json_encode(array_map(fn($r) => date('M Y', strtotime($r['ym'] . '-01')), $monthlyAssigned)) ?>;
        const monthlyData = <?= json_encode(array_map('intval', array_column($monthlyAssigned, 'cnt'))) ?>;
        const chartMonthlyEl = document.getElementById('chartMonthlyAssigned');
        if (chartMonthlyEl) {
            new Chart(chartMonthlyEl, {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Assignments Received',
                        data: monthlyData,
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

        const chartApprovalEl = document.getElementById('chartApprovalRate');
        if (chartApprovalEl) {
            new Chart(chartApprovalEl, {
                type: 'doughnut',
                data: {
                    labels: ['Approved', 'Disapproved'],
                    datasets: [{
                        data: [<?= $approvedCount ?>, <?= $disapprovedCount ?>],
                        backgroundColor: ['#059669', '#b91c1c'],
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

        document.getElementById('filter-month')?.addEventListener('change', () => {
            document.getElementById('reportFilterForm').submit();
        });

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

    <!-- FLOATING DOCK  (exact dashboard.php structure) -->
    <div class="dock-wrap no-print">
        <nav class="dock">

            <a href="index.php" class="dk">
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

            <a href="reports.php" class="dk active">
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

    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
    <?php include '../includes/login_toast.php'; ?>
</body>

</html>
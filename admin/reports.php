<?php

/**
 * reportss.php  –  DMW RO IV-A Admin Reports
 * Printable + Excel-exportable report suite.
 * Opened from dashboard.php's "Print / Export PDF" button, carrying
 * across whatever month / province / job_type / country filters the
 * admin currently has selected on the dashboard subbar.
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
    $where  = [];
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

/* 
   REPORT 1 — Applicants by Gender
 */
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

/* 
   REPORT 2 — Approved Applicants by Country
 */
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

/* 
   REPORT 3 — Application Status Summary
 */
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

/* 
   REPORT 4 — Applicants by Province / City of Origin
 */
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

/* 
   REPORT 5 — Applicants by Age Group
 */
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

/* 
   REPORT 6 — Applicants by Educational Attainment
 */
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

/* 
   REPORT 7 — Job Order Slot Utilization
 */
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
    LEFT JOIN applications a ON a.rsf_id = j.id
    $jobWhereSql
    GROUP BY j.id
    ORDER BY j.deadline ASC
", $jobTypes, $jobParams);
while ($row = $res->fetch_assoc()) $jobSummary[] = $row;

/* 
   REPORT 8 — Evaluator Turnaround / Workload
 */
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

/* 
   REPORT 9 — Document Compliance
   Required vs. submitted documents per application, based on each
   job order's rsf_required_documents list (is_required = 1) matched
   against application_documents.document_type.
 */
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
// Reuses one prepared statement across the loop instead of re-preparing each time.
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }
    </style>
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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #eef1f6;
            color: var(--text1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid var(--bdr);
            background: var(--surface);
            color: var(--text1);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover {
            background: var(--surface2);
        }

        .btn-primary {
            background: var(--navy);
            border-color: var(--navy);
            color: #fff;
        }

        .btn-green {
            background: var(--ok);
            border-color: var(--ok);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--navy-soft);
            border-color: var(--navy-soft);
            color: #fff;
        }

        .btn-green:hover {
            background: var(--ok);
            border-color: var(--ok);
            color: #fff;
            filter: brightness(1.08);
        }

        .btn-red:hover {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
            filter: brightness(1.08);
        }

        .btn-red {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
        }

        .btn-red:hover {
            filter: brightness(1.08);
        }

        .btn-red:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .sheet {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 16px 110px;
        }

        .toolbar-actions {
            display: flex;
            flex-wrap: nowrap;
            gap: 8px;
            margin-bottom: 16px;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 6px;
            /* keeps the scrollbar from touching the buttons */
        }

        .toolbar-actions .btn {
            flex-shrink: 0;
            /* buttons keep their size instead of squishing */
            white-space: nowrap;
            /* labels never break onto two lines */
        }

        .tabs {
            display: flex;
            gap: 6px;
            padding: 0 0 14px;
            overflow-x: auto;
        }

        .tab-btn {
            flex-shrink: 0;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--bdr);
            background: var(--surface);
            color: var(--text2);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .tab-btn.active {
            background: var(--navy);
            border-color: var(--navy);
            color: #fff;
        }

        .doc-sheet {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 30px 34px 36px;
        }

        .doc-header-table {
            width: 100%;
            border-bottom: 3px solid var(--navy);
            padding-bottom: 10px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .doc-header-table img {
            width: 48px;
            height: auto;
            flex-shrink: 0;
        }

        .republic-line {
            font-size: 10.5px;
            color: var(--text2);
            margin: 0;
        }

        .agency-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--navy);
            margin: 1px 0 2px;
        }

        .agency-sub {
            font-size: 10.5px;
            color: var(--text2);
            line-height: 1.5;
            margin: 0;
        }

        .doc-title {
            text-align: center;
            margin: 18px 0 3px;
            font-size: 17px;
            font-weight: 800;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .doc-subtitle {
            text-align: center;
            font-size: 12px;
            color: var(--text2);
            margin-bottom: 16px;
        }

        table.ref-table {
            width: 100%;
            margin-bottom: 20px;
            font-size: 12px;
            border-collapse: collapse;
        }

        table.ref-table td {
            padding: 3px 0;
            vertical-align: middle;
        }

        table.ref-table td.ref-label {
            font-weight: 700;
            width: 160px;
            color: var(--text1);
        }

        .intro-note {
            font-size: 12.5px;
            line-height: 1.65;
            color: var(--text2);
            text-align: justify;
            margin: 0 0 18px;
        }

        .summary-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0;
            border: 1px solid var(--bdr);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 18px;
        }

        .summary-cell {
            padding: 12px 14px;
            border-right: 1px solid var(--bdr);
            border-bottom: 1px solid var(--bdr);
            background: var(--surface2);
        }

        .summary-cell:last-child {
            border-right: none;
        }

        .summary-cell .sc-num {
            font-size: 20px;
            font-weight: 800;
            color: var(--navy);
            line-height: 1.1;
        }

        .summary-cell .sc-lbl {
            font-size: 10px;
            font-weight: 600;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: .03em;
            margin-top: 3px;
        }

        .summary-cell.sc-warn .sc-num {
            color: var(--red);
        }

        .section-hint {
            font-size: 11.5px;
            color: var(--text3);
            font-style: italic;
            margin: -4px 0 10px;
        }

        .section {
            margin-bottom: 22px;
        }

        .section-title {
            background: var(--navy);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 12px;
            margin: 0 0 10px;
            text-transform: uppercase;
            letter-spacing: .04em;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 3px;
        }

        .report-section {
            display: none;
        }

        .report-section.active {
            display: block;
        }

        .chart-wrap {
            max-width: 360px;
            margin: 0 auto 18px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .b-approved {
            background: #d1fae5;
            color: #047857;
        }

        .b-disapproved {
            background: #fee2e2;
            color: #b91c1c;
        }

        .b-pending {
            background: #fef3c7;
            color: #b45309;
        }

        .b-other {
            background: #e9edf7;
            color: var(--navy);
        }

        table.report-table,
        table.detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            border: 1px solid var(--bdr);
        }

        table.report-table th,
        table.detail-table th {
            background: var(--lbl-bg);
            text-align: left;
            padding: 8px 10px;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: var(--text2);
            border: 1px solid var(--bdr);
        }

        table.report-table td,
        table.detail-table td {
            padding: 8px 10px;
            border: 1px solid var(--bdr);
            vertical-align: top;
        }

        table.report-table tbody tr:nth-child(even),
        table.detail-table tbody tr:nth-child(even) {
            background: #fbfcfe;
        }

        .empty-row td,
        .empty-note {
            font-size: 12px;
            color: var(--text3);
            font-style: italic;
            text-align: center;
            padding: 14px 10px;
        }

        .footer-note {
            font-size: 10px;
            color: var(--text3);
            text-align: center;
            border-top: 1px solid var(--bdr);
            padding-top: 10px;
            margin-top: 24px;
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

            body {
                background: #fff;
            }

            .sheet {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            @page {
                size: A4;
                margin: 14mm 12mm;
            }
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

        [data-theme="dark"] body {
            background: #0f172a;
        }

        [data-theme="dark"] .btn {
            background: var(--surface);
            color: var(--text1);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .btn:hover {
            background: var(--surface2);
        }

        [data-theme="dark"] .tab-btn {
            background: var(--surface2);
            color: var(--text2);
            border-color: var(--bdr);
        }

        html body[data-theme="dark"] .tabs .tab-btn.active,
        html[data-theme="dark"] body .tabs .tab-btn.active {
            background-color: #3b82f6 !important;
            background-image: none !important;
            color: #ffffff !important;
            border-color: #3b82f6 !important;
        }

        html body[data-theme="dark"] .tabs .tab-btn.active i,
        html[data-theme="dark"] body .tabs .tab-btn.active i {
            color: #ffffff !important;
        }

        [data-theme="dark"] .doc-sheet {
            background: var(--surface);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .agency-name,
        [data-theme="dark"] .doc-title {
            color: var(--navy);
        }

        [data-theme="dark"] .section-title {
            background: var(--navy-soft);
            color: #f8fafc;
        }

        [data-theme="dark"] .summary-cell {
            background: var(--surface2);
            border-color: var(--bdr);
        }

        [data-theme="dark"] table.report-table,
        [data-theme="dark"] table.detail-table {
            border-color: var(--bdr);
        }

        [data-theme="dark"] table.report-table th,
        [data-theme="dark"] table.detail-table th,
        [data-theme="dark"] table.report-table td,
        [data-theme="dark"] table.detail-table td {
            border-color: var(--bdr);
            color: var(--text1);
        }

        [data-theme="dark"] table.report-table th,
        [data-theme="dark"] table.detail-table th {
            background: var(--lbl-bg);
            color: var(--text2);
        }

        [data-theme="dark"] table.report-table tbody tr:nth-child(even),
        [data-theme="dark"] table.detail-table tbody tr:nth-child(even) {
            background: #202f45;
        }

        [data-theme="dark"] .badge.b-approved {
            background: rgba(16, 185, 129, .18);
            color: #34d399;
        }

        [data-theme="dark"] .badge.b-disapproved {
            background: rgba(220, 38, 38, .20);
            color: #f87171;
        }

        [data-theme="dark"] .badge.b-pending {
            background: rgba(217, 119, 6, .20);
            color: #fbbf24;
        }

        [data-theme="dark"] .badge.b-other {
            background: rgba(99, 102, 241, .22);
            color: #a5b4fc;
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

    <header class="topbar no-print">
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
                <select name="month" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
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
                <select name="province" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">CALABARZON</option>
                    <?php foreach (['Laguna', 'Batangas', 'Cavite', 'Rizal', 'Quezon'] as $prov): ?>
                        <option value="<?= htmlspecialchars($prov) ?>" <?= $prov === $f_province ? 'selected' : '' ?>><?= htmlspecialchars($prov) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select name="job_type" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">All Job Types</option>
                    <?php foreach ($jobTypeOptions as $jt): ?>
                        <option value="<?= htmlspecialchars($jt) ?>" <?= $jt === $f_jobtype ? 'selected' : '' ?>><?= htmlspecialchars($jt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select name="country" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">All Destinations</option>
                    <?php foreach ($countryOptions as $ctry): ?>
                        <option value="<?= htmlspecialchars($ctry) ?>" <?= $ctry === $f_country ? 'selected' : '' ?>><?= htmlspecialchars($ctry) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="filter-pill" style="cursor:pointer;border:none">
                <i class="fas fa-filter" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Apply Filters</span>
            </button>
        </form>
    </div>

    <div class="sheet">

        <div class="toolbar-actions no-print">
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <button class="btn" onclick="printCurrent()"><i class="fas fa-print"></i> Print This Report</button>
            <button class="btn btn-green" onclick="exportCurrent()"><i class="fas fa-file-excel"></i> Export to Excel</button>
            <button class="btn btn-red" id="exportPdfBtn" onclick="downloadCurrentPDF(this)"><i class="fas fa-file-pdf"></i> Download PDF</button>
            <button class="btn btn-primary" onclick="printAll()"><i class="fas fa-print"></i> Print All Reports</button>
            <button class="btn btn-primary" onclick="exportAll()"><i class="fas fa-file-excel"></i> Export All (Excel)</button>
            <button class="btn btn-red" onclick="downloadAllPDF(this)"><i class="fas fa-file-pdf"></i> Download All (PDF)</button>
        </div>

        <div class="tabs no-print" id="tabs">
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
            <div class="doc-sheet">

                <div class="doc-header-table">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW logo">
                    <div>
                        <p class="republic-line">Republic of the Philippines</p>
                        <p class="agency-name">DEPARTMENT OF MIGRANT WORKERS</p>
                        <p class="agency-sub">
                            Regional Office IV-A (CALABARZON)<br>
                            Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                            Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                        </p>
                    </div>
                </div>

                <div class="doc-title">Migrant Worker Application Reports</div>
                <div class="doc-subtitle"><?= htmlspecialchars($filterRecap) ?></div>

                <table class="ref-table">
                    <tr>
                        <td class="ref-label">Generated:</td>
                        <td><?= htmlspecialchars($generatedAt) ?></td>
                    </tr>
                    <tr>
                        <td class="ref-label">Prepared by:</td>
                        <td><?= htmlspecialchars($admin_name) ?></td>
                    </tr>
                    <tr>
                        <td class="ref-label">Office:</td>
                        <td>DMW Regional Office IV-A (CALABARZON)</td>
                    </tr>
                </table>

                <p class="intro-note">
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

                <section class="report-section active" id="rep-country">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-globe"></i> I. Approved Applicants by Country</div>
                        <p class="section-hint">Approved applications grouped by worksite/destination country. <?= $countryApprovedTotal ?> approved in the current filter set.</p>
                        <div class="chart-wrap no-print"><canvas id="chartCountry"></canvas></div>
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
                    <div class="section">
                        <div class="section-title"><i class="fas fa-users"></i> Approved Applicant Detail</div>
                        <p class="section-hint">Individual approved applicants corresponding to the country summary above.</p>
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
                </section>

                <section class="report-section" id="rep-status">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-chart-pie"></i> II. Application Status Summary</div>
                        <p class="section-hint">Overall processing funnel — useful for management reporting and pipeline monitoring.</p>
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
                </section>

                <section class="report-section" id="rep-province">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-map-location-dot"></i> III. Applicants by Province &amp; City of Origin</div>
                        <p class="section-hint">Where applicants are coming from within the region — useful for outreach and LGU coordination.</p>
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
                </section>

                <section class="report-section" id="rep-age">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-cake-candles"></i> IV. Applicants by Age Group</div>
                        <p class="section-hint">Demographic breakdown — helps check compliance with job-order age qualifications.</p>
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
                </section>

                <section class="report-section" id="rep-edu">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-graduation-cap"></i> V. Applicants by Educational Attainment</div>
                        <p class="section-hint">Useful for matching applicant qualifications against job order requirements.</p>
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
                </section>

                <section class="report-section" id="rep-jobs">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-briefcase"></i> VI. Job Order Slot Utilization</div>
                        <p class="section-hint">Tracks recruitment progress per RSF job order — applied vs. approved vs. remaining slots.</p>
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
                </section>

                <section class="report-section" id="rep-evaluators">
                    <div class="section">
                        <div class="section-title"><i class="fas fa-user-clock"></i> VII. Evaluator Turnaround &amp; Workload</div>
                        <p class="section-hint">Average number of days from submission to final decision, per evaluator — useful for performance monitoring.</p>
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
                </section>

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
                </section>

                <p class="footer-note">Department of Migrant Workers — Application Management System &nbsp;|&nbsp; Regional Office IV-A (CALABARZON) &nbsp;|&nbsp; For official use only</p>

            </div>
        </div>

        <div id="printReport">
            <div class="pr-page-header">
                <img id="printLogo" src="../pictures/dmw_tahanan_ng_ofw.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
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

        function exportCurrent() {
            const id = activeSectionId();
            const tables = tablesInSection(id);
            if (!tables.length) return;

            const wb = XLSX.utils.book_new();
            tables.forEach((tbl, i) => {
                const ws = XLSX.utils.table_to_sheet(tbl);
                const name = safeSheetName(tables.length > 1 ? `${REPORT_TITLES[id]} ${i + 1}` : REPORT_TITLES[id]);
                XLSX.utils.book_append_sheet(wb, ws, name || `Sheet${i + 1}`);
            });
            XLSX.writeFile(wb, `${REPORT_TITLES[id].replace(/\s+/g, '_')}.xlsx`);
        }

        function exportAll() {
            const wb = XLSX.utils.book_new();
            document.querySelectorAll('.report-section').forEach(section => {
                const tables = tablesInSection(section.id);
                tables.forEach((tbl, i) => {
                    const ws = XLSX.utils.table_to_sheet(tbl);
                    const baseName = REPORT_TITLES[section.id] || section.id;
                    const name = safeSheetName(tables.length > 1 ? `${baseName} ${i + 1}` : baseName);
                    XLSX.utils.book_append_sheet(wb, ws, name || section.id);
                });
            });
            XLSX.writeFile(wb, 'DMW_All_Reports.xlsx');
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
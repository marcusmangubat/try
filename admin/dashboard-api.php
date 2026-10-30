<?php

/**
 * dashboard-api.php
 * DMW RO IV-A – Admin Dashboard Backend
 * Drop this file next to dashboard.php (inside your admin/ folder).
 * dashboard.php calls it via fetch() with ?action=<name>
 * Actions: stats | monthly | status | job_orders | destinations | report | notifications
 */

session_start();
require_once '../db.php'; // gives us $database (mysqli)

// Auth guard — same pattern as applications.php
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

/* FILTER HELPER
   Reads ?month=YYYY-MM & ?province= & ?job_type= & ?country= from the
   querystring and turns them into reusable JOIN + WHERE + bind-param
   fragments. Every report query below threads through this so the
   subbar filter pills actually narrow the data.
   ─ month     → applications.submitted_at within that month
   ─ province  → clients.province        (needs JOIN clients)
   ─ job_type  → rsf_job_orders.position_title  (needs JOIN rsf_job_orders)
   ─ country   → rsf_job_orders.worksite_country (needs JOIN rsf_job_orders)*/
function buildFilters(string $appAlias = 'a', bool $includeMonth = true): array
{
    $month    = trim($_GET['month'] ?? '');
    $province = trim($_GET['province'] ?? '');
    $jobType  = trim($_GET['job_type'] ?? '');
    $country  = trim($_GET['country'] ?? '');

    $joins  = [];
    // Drafts are unsubmitted applications-in-progress; they should never
    // appear in admin-facing counts, charts, or reports.
    $where  = ["{$appAlias}.status != 'draft'"];
    $types  = '';
    $params = [];

    $needsClient = $province !== '';
    $needsJob    = $jobType !== '' || $country !== '';

    if ($needsClient) {
        $joins[] = "JOIN clients fc ON fc.cid = {$appAlias}.client_id";
    }
    if ($needsJob) {
        $joins[] = "JOIN rsf_job_orders fj ON fj.id = {$appAlias}.rsf_id";
    }

    if ($includeMonth && $month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $start = $month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $where[]  = "{$appAlias}.submitted_at BETWEEN ? AND ?";
        $types   .= 'ss';
        $params[] = $start;
        $params[] = $end;
    }
    if ($province !== '') {
        $where[]  = "fc.province = ?";
        $types   .= 's';
        $params[] = $province;
    }
    if ($jobType !== '') {
        $where[]  = "fj.position_title = ?";
        $types   .= 's';
        $params[] = $jobType;
    }
    if ($country !== '') {
        $where[]  = "fj.worksite_country = ?";
        $types   .= 's';
        $params[] = $country;
    }

    return [
        'join'   => $joins ? (' ' . implode(' ', $joins) . ' ') : '',
        'where'  => ' AND ' . implode(' AND ', $where), // always non-empty now (draft exclusion)
        'types'  => $types,
        'params' => $params,
        'month'  => $month,
    ];
}
/* Bind a dynamic list of params onto a prepared statement */
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

try {
    switch ($action) {
        case 'stats':
            echo json_encode(getStats($database, buildFilters()));
            break;
        case 'monthly':
            // Skip the month date-range so the chart keeps Jan–Dec for MoM/averages.
            // Province, job type, and destination still apply. Selected month is the focus month.
            echo json_encode(getMonthly($database, buildFilters('a', false)));
            break;
        case 'status':
            echo json_encode(getStatusBreakdown($database, buildFilters()));
            break;
        case 'job_orders':
            echo json_encode(getJobOrders($database, buildFilters('a')));
            break;
        case 'destinations':
            echo json_encode(getDestinations($database, buildFilters()));
            break;
        case 'evaluator_performance':
            echo json_encode(getEvaluatorPerformance($database, buildFilters()));
            break;
        case 'document_completion':
            echo json_encode(getDocumentCompletion($database, buildFilters()));
            break;
        case 'compliance_cycles':
            echo json_encode(getComplianceCycles($database, buildFilters()));
            break;
        case 'login_security':
            echo json_encode(getLoginSecurity($database));
            break;
        case 'recent_activity':
            echo json_encode(getRecentActivity($database));
            break;
        case 'report':
            echo json_encode(getReportRows($database, buildFilters()));
            break;
        case 'job_types':
            echo json_encode(getJobTypes($database));
            break;
        case 'notifications':
            echo json_encode(getNotifications($database, $_SESSION['user_email']));
            break;
        case 'mark_read':
            markAllRead($database, $_SESSION['user_email']);
            echo json_encode(['ok' => true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
/* 
   1. KPI STATS
   ─ Total apps (all time)
   ─ Pending / Under Evaluation / Approved counts
   ─ Month-over-month % change
   ─ Average evaluation days */
/* Small helper: run a prepared query and return the first column of the first row */
function scalarQuery(mysqli $db, string $sql, string $types = '', array $params = [])
{
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? $row[0] : null;
}

function getStats(mysqli $db, array $f): array
{
    // Total applications (filtered)
    $total = (int) scalarQuery(
        $db,
        "SELECT COUNT(*) FROM applications a {$f['join']} WHERE 1=1 {$f['where']}",
        $f['types'],
        $f['params']
    );

    // Counts grouped by status (filtered)
    $sql  = "SELECT a.status, COUNT(*) AS cnt FROM applications a {$f['join']} WHERE 1=1 {$f['where']} GROUP BY a.status";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();
    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $s = $row['status'];
        $c = (int) $row['cnt'];
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + $c;
    }
    $stmt->close();

    $pending     = $counts['pending']          ?? 0;
    $underEval   = $counts['under_evaluation'] ?? 0;
    $approved    = $counts['approved']         ?? 0;
    $disapproved = $counts['disapproved']      ?? 0;
    $compliance  = $counts['for_compliance']   ?? 0;
    $resubmitted = $counts['resubmitted']      ?? 0;
    $unassign    = $counts['unassign']         ?? 0;

    // Month-over-month (only when a specific month is selected)
    $isAllTime = ($f['month'] === '');
    $changePct = null;
    $approvedChangePct = null;

    if (!$isAllTime) {
        $thisStart = $f['month'] . '-01 00:00:00';
        $thisEnd   = date('Y-m-t 23:59:59', strtotime($thisStart));
        $prevStart = date('Y-m-01 00:00:00', strtotime($thisStart . ' -1 month'));
        $prevEnd   = date('Y-m-t 23:59:59',  strtotime($thisStart . ' -1 month'));

        $sql = "SELECT COUNT(*) FROM applications a {$f['join']} WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}";
        $thisMo = (int) scalarQuery($db, $sql, 'ss' . $f['types'], array_merge([$thisStart, $thisEnd], $f['params']));
        $prevMo = (int) scalarQuery($db, $sql, 'ss' . $f['types'], array_merge([$prevStart, $prevEnd], $f['params']));
        $changePct = $prevMo > 0 ? round((($thisMo - $prevMo) / $prevMo) * 100, 1) : null;

        $sql = "SELECT COUNT(*) FROM applications a {$f['join']} WHERE a.status = 'approved' AND a.updated_at BETWEEN ? AND ? {$f['where']}";
        $thisApproved = (int) scalarQuery($db, $sql, 'ss' . $f['types'], array_merge([$thisStart, $thisEnd], $f['params']));
        $prevApproved = (int) scalarQuery($db, $sql, 'ss' . $f['types'], array_merge([$prevStart, $prevEnd], $f['params']));
        $approvedChangePct = $prevApproved > 0 ? round((($thisApproved - $prevApproved) / $prevApproved) * 100, 1) : null;
    }

    // Average evaluation days (kept for backward compatibility)
    $avg = scalarQuery(
        $db,
        "SELECT AVG(DATEDIFF(e.evaluated_at, a.submitted_at))
         FROM evaluations e
         JOIN applications a ON a.id = e.application_id
         {$f['join']}
         WHERE e.decision IS NOT NULL {$f['where']}",
        $f['types'],
        $f['params']
    );
    $avgDays = $avg !== null ? round((float) $avg, 1) : null;

    // Pending applications waiting more than 3 days
    $pendingAging = (int) scalarQuery(
        $db,
        "SELECT COUNT(*) FROM applications a {$f['join']}
         WHERE a.status = 'pending' AND a.submitted_at < (NOW() - INTERVAL 3 DAY) {$f['where']}",
        $f['types'],
        $f['params']
    );

    // Approval rate = approved / (approved + disapproved)
    $decided      = $approved + $disapproved;
    $approvalRate = $decided > 0 ? round($approved / $decided * 100, 1) : null;

    // Avg processing days: submission -> final decision
    $proc = scalarQuery(
        $db,
        "SELECT AVG(DATEDIFF(a.updated_at, a.submitted_at))
         FROM applications a {$f['join']}
         WHERE a.status IN ('approved','disapproved') {$f['where']}",
        $f['types'],
        $f['params']
    );
    $avgProcessingDays = $proc !== null ? round((float) $proc, 1) : null;

    // Active job orders + slot fill rate (job type / destination filters apply)
    $jobType = trim($_GET['job_type'] ?? '');
    $country = trim($_GET['country'] ?? '');
    $jw = ['j.deadline >= CURDATE()'];
    $jt = '';
    $jp = [];
    if ($jobType !== '') {
        $jw[] = 'j.position_title = ?';
        $jt .= 's';
        $jp[] = $jobType;
    }
    if ($country !== '') {
        $jw[] = 'j.worksite_country = ?';
        $jt .= 's';
        $jp[] = $country;
    }
    $jobWhere = implode(' AND ', $jw);

    $stmt = $db->prepare("SELECT COUNT(*) AS jobs, COALESCE(SUM(j.slots_available),0) AS slots
                          FROM rsf_job_orders j WHERE {$jobWhere}");
    bindDynamic($stmt, $jt, $jp);
    $stmt->execute();
    $jobRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $activeJobs = (int) ($jobRow['jobs'] ?? 0);
    $totalSlots = (int) ($jobRow['slots'] ?? 0);

    $filledSlots = (int) scalarQuery(
        $db,
        "SELECT COUNT(a.id) FROM applications a
         JOIN rsf_job_orders j ON j.id = a.rsf_id
         WHERE a.status = 'approved' AND {$jobWhere}",
        $jt,
        $jp
    );
    $slotFillRate = $totalSlots > 0 ? min(100, round($filledSlots / $totalSlots * 100, 1)) : null;

    // Registered (active) applicants — province filter applies
    $province = trim($_GET['province'] ?? '');
    if ($province !== '') {
        $applicants = (int) scalarQuery(
            $db,
            "SELECT COUNT(*) FROM clients c JOIN webuser w ON w.email = c.email
             WHERE w.is_active = 1 AND c.province = ?",
            's',
            [$province]
        );
    } else {
        $applicants = (int) scalarQuery(
            $db,
            "SELECT COUNT(*) FROM clients c JOIN webuser w ON w.email = c.email WHERE w.is_active = 1"
        );
    }

    // Active evaluators
    $activeEvaluators = (int) scalarQuery(
        $db,
        "SELECT COUNT(*) FROM evaluators ev JOIN webuser w ON w.email = ev.email WHERE w.is_active = 1"
    );

    return [
        'total'             => $total,
        'pending'           => $pending,
        'under_evaluation'  => $underEval,
        'approved'          => $approved,
        'unassign'          => $unassign,
        'change_pct'        => $changePct,
        'approved_change'   => $approvedChangePct,
        'avg_eval_days'     => $avgDays,
        'disapproved'       => $disapproved,
        'for_compliance'    => $compliance,
        'resubmitted'       => $resubmitted,
        'pending_aging'     => $pendingAging,
        'approval_rate'     => $approvalRate,
        'avg_processing'    => $avgProcessingDays,
        'active_jobs'       => $activeJobs,
        'total_slots'       => $totalSlots,
        'filled_slots'      => $filledSlots,
        'slot_fill_rate'    => $slotFillRate,
        'applicants'        => $applicants,
        'active_evaluators' => $activeEvaluators,
    ];
}

/* 2. MONTHLY BAR CHART
Applications submitted per month (current year)
Approved per month (current year) */
function getMonthly(mysqli $db, array $f): array
{
    $year       = (int) date('Y');
    $focusMonth = (int) date('n');
    $monthParam = trim((string) ($f['month'] ?? ''));
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthParam, $m)) {
        $year       = (int) $m[1];
        $focusMonth = (int) $m[2];
    }

    $submitted = array_fill(1, 12, 0);
    $approved  = array_fill(1, 12, 0);

    $sql = "SELECT MONTH(a.submitted_at) AS mo, COUNT(*) AS cnt
            FROM applications a {$f['join']}
            WHERE YEAR(a.submitted_at) = ? {$f['where']}
            GROUP BY mo";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'i' . $f['types'], array_merge([$year], $f['params']));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $submitted[(int) $row['mo']] = (int) $row['cnt'];
    }
    $stmt->close();

    $sql = "SELECT MONTH(a.updated_at) AS mo, COUNT(*) AS cnt
            FROM applications a {$f['join']}
            WHERE a.status = 'approved' AND YEAR(a.updated_at) = ? {$f['where']}
            GROUP BY mo";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'i' . $f['types'], array_merge([$year], $f['params']));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $approved[(int) $row['mo']] = (int) $row['cnt'];
    }
    $stmt->close();

    return [
        'labels'      => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'submitted'   => array_values($submitted),
        'approved'    => array_values($approved),
        'year'        => $year,
        'focus_month' => $focusMonth,
    ];
}

/* 3. STATUS BREAKDOWN BARS*/
function getStatusBreakdown(mysqli $db, array $f): array
{
    $order = [
        'pending'          => ['label' => 'Pending',       'color' => '#f59e0b'],
        'unassign'         => ['label' => 'Unassigned',    'color' => '#ef4444'],
        'assign'           => ['label' => 'Assigned',      'color' => '#3b82f6'],
        'under_evaluation' => ['label' => 'Under Eval.',   'color' => '#6366f1'],
        'for_compliance'   => ['label' => 'Compliance',    'color' => '#f97316'],
        'resubmitted'      => ['label' => 'Resubmitted',   'color' => '#8b5cf6'],
        'approved'         => ['label' => 'Approved',      'color' => '#10b981'],
        'disapproved'      => ['label' => 'Disapproved',   'color' => '#dc2626'],
    ];

    $sql  = "SELECT a.status, COUNT(*) AS cnt FROM applications a {$f['join']} WHERE 1=1 {$f['where']} GROUP BY a.status";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $s = $row['status'];
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + (int) $row['cnt'];
    }
    $stmt->close();

    $total  = array_sum($counts) ?: 1;
    $result = [];

    foreach ($order as $key => $meta) {
        $c        = $counts[$key] ?? 0;
        $result[] = [
            'status' => $key,
            'label'  => $meta['label'],
            'color'  => $meta['color'],
            'count'  => $c,
            'pct'    => round($c / $total * 100, 1),
        ];
    }

    return $result;
}

/* 4. ACTIVE JOB ORDERS (deadline not yet passed)*/
function getJobOrders(mysqli $db, array $f): array
{
    // Pull job_type/country straight from the querystring — these filters
    // describe the job posting itself, not the applicant, so they apply
    // directly to rsf_job_orders rather than through the applications join.
    $jobType = trim($_GET['job_type'] ?? '');
    $country = trim($_GET['country'] ?? '');

    $where  = ['j.deadline >= CURDATE()'];
    $types  = '';
    $params = [];

    if ($jobType !== '') {
        $where[]  = 'j.position_title = ?';
        $types   .= 's';
        $params[] = $jobType;
    }
    if ($country !== '') {
        $where[]  = 'j.worksite_country = ?';
        $types   .= 's';
        $params[] = $country;
    }

    $sql = "SELECT j.id, j.rsf_number, j.position_title, j.employer_name,
                   j.worksite_country, j.slots_available, j.deadline,
                   COUNT(a.id) AS applied
            FROM rsf_job_orders j
            LEFT JOIN applications a ON a.rsf_id = j.id AND a.status = 'approved'
            WHERE " . implode(' AND ', $where) . "
            GROUP BY j.id
            ORDER BY j.deadline ASC";

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();

    $jobs = [];
    while ($row = $res->fetch_assoc()) {
        $slots   = (int) $row['slots_available'];
        $applied = (int) $row['applied'];
        $pct     = $slots > 0 ? min(100, round($applied / $slots * 100)) : 0;

        $jobs[] = [
            'id'         => $row['id'],
            'rsf_number' => $row['rsf_number'],
            'title'      => $row['position_title'],
            'employer'   => $row['employer_name'],
            'country'    => $row['worksite_country'],
            'slots'      => $slots,
            'applied'    => $applied,
            'fill_pct'   => $pct,
            'deadline'   => $row['deadline'],
            'days_left'  => (int) ceil((strtotime($row['deadline']) - time()) / 86400),
        ];
    }
    $stmt->close();

    return $jobs;
}

/* 5. DESTINATION COUNTRIES DOUGHNUT */
function getDestinations(mysqli $db, array $f): array
{
    $sql = "SELECT j.worksite_country AS country, COUNT(a.id) AS cnt
            FROM applications a
            JOIN rsf_job_orders j ON j.id = a.rsf_id
            {$f['join']}
            WHERE 1=1 {$f['where']}
            GROUP BY j.worksite_country
            ORDER BY cnt DESC";

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $labels = [];
    $data   = [];
    $palette = [
        '#1d4ed8',
        '#059669',
        '#d97706',
        '#6366f1',
        '#dc2626',
        '#0891b2',
        '#7c3aed',
        '#db2777',
        '#65a30d',
        '#ea580c',
        '#0d9488',
        '#be123c',
        '#ca8a04',
        '#9333ea',
        '#0284c7',
        '#16a34a',
    ];
    $colors = [];

    while ($row = $res->fetch_assoc()) {
        $i = count($labels);
        $labels[] = $row['country'];
        $data[]   = (int) $row['cnt'];
        $colors[] = $palette[$i % count($palette)];
    }
    $stmt->close();

    return ['labels' => $labels, 'data' => $data, 'colors' => $colors];
}

/* 8. EVALUATOR PERFORMANCE & WORKLOAD — Objective 2 (process/admin monitoring) */
function getEvaluatorPerformance(mysqli $db, array $f): array
{
    $sql = "SELECT ev.eid, ev.full_name,
                   COUNT(e.id) AS total_actions,
                   SUM(CASE WHEN e.decision = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN e.decision = 'disapproved' THEN 1 ELSE 0 END) AS disapproved_count,
                   ROUND(AVG(CASE WHEN e.evaluation_type = 'initial' AND e.decision IS NOT NULL
                                  THEN DATEDIFF(e.evaluated_at, a.submitted_at) END), 1) AS avg_turnaround_days
            FROM evaluators ev
            JOIN evaluations e ON e.evaluator_id = ev.eid
            JOIN applications a ON a.id = e.application_id {$f['join']}
            WHERE 1=1 {$f['where']}
            GROUP BY ev.eid, ev.full_name
            ORDER BY total_actions DESC";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $labels = [];
    $totals = [];
    $approved = [];
    $disapproved = [];
    $avgDays = [];
    while ($row = $res->fetch_assoc()) {
        $labels[]      = $row['full_name'] ?: ('Evaluator #' . $row['eid']);
        $totals[]      = (int) $row['total_actions'];
        $approved[]    = (int) $row['approved_count'];
        $disapproved[] = (int) $row['disapproved_count'];
        $avgDays[]     = $row['avg_turnaround_days'] !== null ? (float) $row['avg_turnaround_days'] : null;
    }
    $stmt->close();

    return [
        'labels'      => $labels,
        'total'       => $totals,
        'approved'    => $approved,
        'disapproved' => $disapproved,
        'avg_days'    => $avgDays,
    ];
}

/* 9. DOCUMENT SUBMISSION COMPLETENESS — Objective 1 (document handling) */
function getDocumentCompletion(mysqli $db, array $f): array
{
    $sql = "SELECT a.id,
                   (SELECT COUNT(*) FROM application_documents ad WHERE ad.application_id = a.id) AS uploaded,
                   (SELECT COUNT(*) FROM rsf_required_documents rd WHERE rd.rsf_id = a.rsf_id AND rd.is_required = 1) AS required_cnt
            FROM applications a {$f['join']}
            WHERE 1=1 {$f['where']}";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $complete = 0;
    $partial  = 0;
    $none     = 0;
    $noReq    = 0;
    $uploadedTotal = 0;
    $appCount      = 0;
    while ($row = $res->fetch_assoc()) {
        $req = (int) $row['required_cnt'];
        $up  = (int) $row['uploaded'];
        $uploadedTotal += $up;
        $appCount++;
        if ($req === 0) {
            $noReq++;
            continue;
        }
        if ($up === 0)          $none++;
        elseif ($up >= $req)    $complete++;
        else                    $partial++;
    }
    $stmt->close();

    // Documents that received at least one evaluator/admin comment
    $commented = (int) scalarQuery(
        $db,
        "SELECT COUNT(DISTINCT dc.document_id)
         FROM document_comments dc
         JOIN application_documents ad ON ad.id = dc.document_id
         JOIN applications a ON a.id = ad.application_id {$f['join']}
         WHERE 1=1 {$f['where']}",
        $f['types'],
        $f['params']
    );

    // Required documents most often missing. A document counts as submitted if it is
    // linked by required_doc_id OR (for older uploads) has the same document_type.
    $sql = "SELECT rd.document_name, COUNT(*) AS missing_cnt
            FROM applications a {$f['join']}
            JOIN rsf_required_documents rd ON rd.rsf_id = a.rsf_id AND rd.is_required = 1
            WHERE NOT EXISTS (
                SELECT 1 FROM application_documents ad
                WHERE ad.application_id = a.id
                  AND (ad.required_doc_id = rd.id OR ad.document_type = rd.document_type)
            ) {$f['where']}
            GROUP BY rd.document_name
            ORDER BY missing_cnt DESC, rd.document_name ASC
            LIMIT 5";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();
    $topMissing = [];
    while ($row = $res->fetch_assoc()) {
        $topMissing[] = ['name' => $row['document_name'], 'count' => (int) $row['missing_cnt']];
    }
    $stmt->close();

    return [
        'labels' => ['Complete', 'Partial', 'No Documents', 'No Requirements Set'],
        'data'   => [$complete, $partial, $none, $noReq],
        'colors' => ['#10b981', '#f59e0b', '#dc2626', '#94a3b8'],
        'uploaded_total' => $uploadedTotal,
        'avg_per_app'    => $appCount > 0 ? round($uploadedTotal / $appCount, 1) : 0,
        'commented_docs' => $commented,
        'top_missing'    => $topMissing,
    ];
}

/* 10. COMPLIANCE CYCLES PER APPLICATION — Objectives 1 & 2 (tracking / process efficiency) */
function getComplianceCycles(mysqli $db, array $f): array
{
    $sqlTotal = "SELECT COUNT(*) FROM applications a {$f['join']} WHERE 1=1 {$f['where']}";
    $stmt = $db->prepare($sqlTotal);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $sql = "SELECT sh.application_id, COUNT(*) AS cycles
            FROM status_history sh
            JOIN applications a ON a.id = sh.application_id {$f['join']}
            WHERE sh.to_status = 'for_compliance' {$f['where']}
            GROUP BY sh.application_id";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $buckets = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0]; // 4 = "4+"
    $cyclesSum  = 0;
    $withCycles = 0;
    while ($row = $res->fetch_assoc()) {
        $c = (int) $row['cycles'];
        $cyclesSum += $c;
        $withCycles++;
        $buckets[$c >= 4 ? 4 : $c]++;
    }
    $stmt->close();

    $buckets[0] = max(0, $total - $withCycles);

    return [
        'labels'     => ['0 (First-time)', '1 round', '2 rounds', '3 rounds', '4+ rounds'],
        'data'       => array_values($buckets),
        'avg_cycles' => $total > 0 ? round($cyclesSum / $total, 2) : 0,
    ];
}

/* 11. LOGIN SECURITY OVERVIEW — ISO 25010 Objective 4.5 (Security) */
function getLoginSecurity(mysqli $db): array
{
    $days = 30;

    $sql = "SELECT DATE(attempted_at) AS d,
                   SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success,
                   SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed
            FROM login_attempts
            WHERE attempted_at >= (CURDATE() - INTERVAL ? DAY)
            GROUP BY DATE(attempted_at)
            ORDER BY d ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDate = [];
    while ($row = $res->fetch_assoc()) {
        $byDate[$row['d']] = ['success' => (int) $row['success'], 'failed' => (int) $row['failed']];
    }
    $stmt->close();

    $labels = [];
    $success = [];
    $failed  = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[]  = date('M j', strtotime($d));
        $success[] = $byDate[$d]['success'] ?? 0;
        $failed[]  = $byDate[$d]['failed'] ?? 0;
    }

    $stmt = $db->prepare(
        "SELECT email, COUNT(*) AS failed_count
         FROM login_attempts
         WHERE success = 0 AND attempted_at >= (CURDATE() - INTERVAL ? DAY)
         GROUP BY email
         ORDER BY failed_count DESC, email ASC"
    );
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $res = $stmt->get_result();
    $topFailed = [];
    while ($row = $res->fetch_assoc()) {
        $topFailed[] = ['email' => $row['email'], 'count' => (int) $row['failed_count']];
    }
    $stmt->close();

    $totalSuccess = array_sum($success);
    $totalFailed  = array_sum($failed);

    return [
        'labels'       => $labels,
        'success'      => $success,
        'failed'       => $failed,
        'top_failed'   => $topFailed,
        'success_rate' => ($totalSuccess + $totalFailed) > 0
            ? round($totalSuccess / ($totalSuccess + $totalFailed) * 100, 1)
            : null,
    ];
}

/* 12. RECENT APPLICATION ACTIVITY FEED — Objective 1/2 (process monitoring) */
function getRecentActivity(mysqli $db): array
{
    $sql = "SELECT
                sh.application_id,
                sh.from_status,
                sh.to_status,
                sh.changed_by,
                sh.changed_at,
                CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
                j.position_title
            FROM status_history sh
            JOIN applications a    ON a.id = sh.application_id
            JOIN clients c         ON c.cid = a.client_id
            JOIN rsf_job_orders j  ON j.id = a.rsf_id
            ORDER BY sh.changed_at DESC
            LIMIT 8";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/*  6b. REPORT ROWS — the actual filtered application records, used by
   report.php to render a printable / PDF-exportable table. Applies
   the exact same month / province / job_type / country filters as
   the rest of the dashboard, so "print" always matches what the
   admin currently has selected in the subbar. */
function getReportRows(mysqli $db, array $f): array
{
    // Always need clients (applicant name) and rsf_job_orders (job/country)
    // for the report regardless of whether they're used as filters, so we
    // don't rely on buildFilters()'s conditional joins here.
    $sql = "SELECT
                a.id,
                a.status,
                a.submitted_at,
                a.updated_at,
                c.first_name,
                c.middle_name,
                c.last_name,
                c.suffix,
                c.province,
                c.city_municipality,
                j.rsf_number,
                j.position_title,
                j.employer_name,
                j.worksite_country
            FROM applications a
            JOIN clients c ON c.cid = a.client_id
            JOIN rsf_job_orders j ON j.id = a.rsf_id
            {$f['join']}
            WHERE 1=1 {$f['where']}
            ORDER BY a.submitted_at DESC";

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $name = trim(implode(' ', array_filter([
            $row['first_name'],
            $row['middle_name'],
            $row['last_name'],
            $row['suffix'],
        ])));

        $rows[] = [
            'id'              => (int) $row['id'],
            'applicant'       => $name,
            'province'        => $row['province'],
            'city'            => $row['city_municipality'],
            'rsf_number'      => $row['rsf_number'],
            'position_title'  => $row['position_title'],
            'employer_name'   => $row['employer_name'],
            'country'         => $row['worksite_country'],
            'status'          => $row['status'],
            'submitted_at'    => $row['submitted_at'],
            'updated_at'      => $row['updated_at'],
        ];
    }
    $stmt->close();

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'filters'      => [
            'month'    => trim($_GET['month'] ?? ''),
            'province' => trim($_GET['province'] ?? ''),
            'job_type' => trim($_GET['job_type'] ?? ''),
            'country'  => trim($_GET['country'] ?? ''),
        ],
        'count' => count($rows),
        'rows'  => $rows,
    ];
}

/*7. DISTINCT JOB TYPES (for the "All Job Types" filter pill) */
function getJobTypes(mysqli $db): array
{
    $res = $db->query(
        "SELECT DISTINCT position_title FROM rsf_job_orders
         WHERE position_title IS NOT NULL AND position_title <> ''
         ORDER BY position_title ASC"
    );
    $types = [];
    while ($row = $res->fetch_assoc()) {
        $types[] = $row['position_title'];
    }
    return $types;
}

/* 6. ADMIN NOTIFICATIONS */
function getNotifications(mysqli $db, string $admin_email): array
{
    $stmt = $db->prepare(
        "SELECT id, title, message, type, is_read, created_at, application_id
         FROM notifications
         WHERE user_email = ? AND usertype = 'admin'
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $items  = [];
    $unread = 0;

    while ($row = $res->fetch_assoc()) {
        if (!(int) $row['is_read']) $unread++;
        $items[] = [
            'id'             => (int) $row['id'],
            'title'          => $row['title'],
            'message'        => $row['message'],
            'type'           => $row['type'],
            'is_read'        => (bool) (int) $row['is_read'],
            'application_id' => $row['application_id'],
            'time_ago'       => timeAgo($row['created_at']),
        ];
    }

    return ['unread' => $unread, 'items' => $items];
}

function markAllRead(mysqli $db, string $admin_email): void
{
    $stmt = $db->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE user_email = ? AND usertype = 'admin' AND is_read = 0"
    );
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $stmt->close();
}

/*  Helper  */
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60)   . 'm ago';
    if ($diff < 86400) return floor($diff / 3600)  . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

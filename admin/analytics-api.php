<?php
// Admin Analytics API
session_start();
require_once '../db.php'; // provides $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();
header('Content-Type: application/json');

$action      = $_GET['action'] ?? '';
$monthParam  = $_GET['month'] ?? 'all';

// Validate: must be 'all' or strictly YYYY-MM
if ($monthParam !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = 'all';
}

[$rangeStart, $rangeEnd] = getDateRange($monthParam);
$filters = buildFilters();

try {
    switch ($action) {
        case 'summary':
            echo json_encode(getSummary($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'stages':
            echo json_encode(getStages($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'avg_time':
            echo json_encode(getAvgTime($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'overdue':
            echo json_encode(getOverdue($database, $filters));
            break;
        case 'weekly':
            echo json_encode(getWeekly($database, $monthParam, $rangeStart, $rangeEnd, $filters));
            break;
        case 'services':
            echo json_encode(getServices($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'svc_monthly':
            echo json_encode(getSvcMonthly($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'destinations':
            echo json_encode(getDestinations($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'evaluators':
            echo json_encode(getEvaluators($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'job_orders':
            echo json_encode(getJobOrders($database, $rangeStart, $rangeEnd, $filters));
            break;
        case 'countries':
            echo json_encode(getCountries($database));
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}


/* FILTER HELPER — same rules as dashboard-api.php buildFilters()
   ─ province empty  → no province filter (CALABARZON label = all provinces)
   ─ province set    → clients.province = selected province
   ─ country empty   → no destination filter (all countries)
   ─ country set     → rsf_job_orders.worksite_country = selected country   */
function buildFilters(string $appAlias = 'a', string $clientAlias = 'fc', ?string $existingJobAlias = null): array
{
    $province = trim($_GET['province'] ?? '');
    $country  = trim($_GET['country'] ?? '');
    $jobAlias = $existingJobAlias ?? 'fj';

    $joins  = [];
    $where  = ["{$appAlias}.status <> 'draft'"]; // drafts are never counted in analytics
    $types  = '';
    $params = [];

    if ($province !== '') {
        $joins[] = "JOIN clients {$clientAlias} ON {$clientAlias}.cid = {$appAlias}.client_id";
        $where[]  = "{$clientAlias}.province = ?";
        $types   .= 's';
        $params[] = $province;
    }

    if ($country !== '') {
        if ($existingJobAlias === null) {
            $joins[] = "JOIN rsf_job_orders {$jobAlias} ON {$jobAlias}.id = {$appAlias}.rsf_id";
        }
        $where[]  = "{$jobAlias}.worksite_country = ?";
        $types   .= 's';
        $params[] = $country;
    }

    return [
        'join'          => $joins ? (' ' . implode(' ', $joins) . ' ') : '',
        'where'         => ' AND ' . implode(' AND ', $where),
        'types'         => $types,
        'params'        => $params,
        'province'      => $province,
        'country'       => $country,
        'client_alias'  => $clientAlias,
        'job_alias'     => $jobAlias,
    ];
}

function bindDynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function prepareBound(mysqli $db, string $sql, string $types, array $params): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $types, $params);
    return $stmt;
}

/*  DATE RANGE HELPER 
   'all'      -> Jan 1 .. Dec 31 of the current year
   'YYYY-MM'  -> first day .. last day of that month                 */
function getDateRange(string $month): array
{
    $year = date('Y');

    if ($month === 'all') {
        return ["$year-01-01 00:00:00", "$year-12-31 23:59:59"];
    }

    $ts = strtotime($month . '-01');
    if ($ts === false) {
        return ["$year-01-01 00:00:00", "$year-12-31 23:59:59"];
    }

    return [date('Y-m-01 00:00:00', $ts), date('Y-m-t 23:59:59', $ts)];
}

/* 1. SUMMARY KPI CARDS */
function getSummary(mysqli $db, string $start, string $end, array $f): array
{
    $stmt = prepareBound(
        $db,
        "SELECT COUNT(*) FROM applications a {$f['join']}
         WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = prepareBound(
        $db,
        "SELECT COUNT(*) FROM applications a {$f['join']}
         WHERE a.status IN ('unassign','unassigned')
           AND a.submitted_at BETWEEN ? AND ? {$f['where']}",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $unassigned = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) FROM evaluators WHERE created_at BETWEEN ? AND ?");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $evaluators = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $clientWhere = '';
    $clientTypes = 'ss';
    $clientParams = [$start, $end];
    if ($f['province'] !== '') {
        $clientWhere = ' AND province = ?';
        $clientTypes .= 's';
        $clientParams[] = $f['province'];
    }

    $stmt = prepareBound(
        $db,
        "SELECT COUNT(*) FROM clients WHERE created_at BETWEEN ? AND ? {$clientWhere}",
        $clientTypes,
        $clientParams
    );
    $stmt->execute();
    $clients = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return [
        'total'      => $total,
        'unassigned' => $unassigned,
        'evaluators' => $evaluators,
        'clients'    => $clients,
    ];
}

/* 2. APPLICATIONS BY STAGE (STATUS) */
function getStages(mysqli $db, string $start, string $end, array $f): array
{
    $order = [
        'pending'          => ['label' => 'Pending',       'color' => '#3b82f6'],
        'unassign'         => ['label' => 'Unassigned',    'color' => '#ef4444'],
        'assign'           => ['label' => 'Assigned',      'color' => '#60a5fa'],
        'under_evaluation' => ['label' => 'Under Eval.',   'color' => '#a78bfa'],
        'for_compliance'   => ['label' => 'Compliance',    'color' => '#f97316'],
        'resubmitted'      => ['label' => 'Resubmitted',   'color' => '#6366f1'],
        'approved'         => ['label' => 'Approved',      'color' => '#059669'],
        'disapproved'      => ['label' => 'Disapproved',   'color' => '#dc2626'],
    ];

    $stmt = prepareBound(
        $db,
        "SELECT a.status, COUNT(*) AS cnt FROM applications a {$f['join']}
         WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}
         GROUP BY a.status",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $counts = [];
    while ($r = $res->fetch_assoc()) {
        $s = $r['status'];
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + (int) $r['cnt'];
    }
    $stmt->close();

    $max    = max(array_values($counts) ?: [1]);
    $result = [];
    foreach ($order as $key => $meta) {
        $c = $counts[$key] ?? 0;
        $result[] = [
            'label' => $meta['label'],
            'color' => $meta['color'],
            'count' => $c,
            'pct'   => $max > 0 ? round($c / $max * 100) : 0,
        ];
    }
    return $result;
}

/* 3. AVERAGE TIME PER STAGE (from status_history), scoped to range */
function getAvgTime(mysqli $db, string $start, string $end, array $f): array
{
    $stmt = prepareBound(
        $db,
        "SELECT AVG(TIMESTAMPDIFF(HOUR, a.submitted_at, sh.changed_at)) AS hrs
         FROM status_history sh
         JOIN applications a ON a.id = sh.application_id
         {$f['join']}
         WHERE sh.from_status = 'pending'
           AND sh.changed_at BETWEEN ? AND ? {$f['where']}",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $submission = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $stmt = prepareBound(
        $db,
        "SELECT AVG(TIMESTAMPDIFF(HOUR, sh1.changed_at, sh2.changed_at)) AS hrs
         FROM status_history sh1
         JOIN status_history sh2
           ON sh1.application_id = sh2.application_id
          AND sh2.to_status = 'under_evaluation'
          AND sh2.changed_at > sh1.changed_at
         JOIN applications a ON a.id = sh1.application_id
         {$f['join']}
         WHERE sh1.to_status IN ('assign','assigned')
           AND sh2.changed_at BETWEEN ? AND ? {$f['where']}",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $assignment = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $stmt = prepareBound(
        $db,
        "SELECT AVG(TIMESTAMPDIFF(HOUR, sh1.changed_at, sh2.changed_at)) AS hrs
         FROM status_history sh1
         JOIN status_history sh2
           ON sh1.application_id = sh2.application_id
          AND sh2.to_status IN ('approved','disapproved')
          AND sh2.changed_at > sh1.changed_at
         JOIN applications a ON a.id = sh1.application_id
         {$f['join']}
         WHERE sh1.to_status = 'under_evaluation'
           AND sh2.changed_at BETWEEN ? AND ? {$f['where']}",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $review = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $stmt = prepareBound(
        $db,
        "SELECT AVG(TIMESTAMPDIFF(HOUR, e.evaluated_at, a.updated_at)) AS hrs
         FROM evaluations e
         JOIN applications a ON a.id = e.application_id
         {$f['join']}
         WHERE e.decision IN ('approved','disapproved')
           AND e.evaluated_at BETWEEN ? AND ? {$f['where']}",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $decision = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $max = max($submission, $assignment, $review, $decision, 0.1);

    return [
        ['label' => 'Submission', 'hours' => $submission, 'color' => '#3b82f6', 'pct' => round($submission / $max * 100)],
        ['label' => 'Assignment', 'hours' => $assignment, 'color' => '#f59e0b', 'pct' => round($assignment / $max * 100)],
        ['label' => 'Review',     'hours' => $review,     'color' => '#ef4444', 'pct' => round($review     / $max * 100)],
        ['label' => 'Decision',   'hours' => $decision,   'color' => '#059669', 'pct' => round($decision   / $max * 100)],
    ];
}

/* 4. OVERDUE (pending/under_evaluation older than 7 days), scoped to filters */
function getOverdue(mysqli $db, array $f, int $days = 7): array
{
    $fOverdue = buildFilters('a', 'c', 'r');

    $joinParts = [];
    if ($fOverdue['province'] !== '') {
        $joinParts[] = trim($fOverdue['join']);
    } else {
        $joinParts[] = 'LEFT JOIN clients c ON c.cid = a.client_id';
    }
    if ($fOverdue['country'] !== '') {
        $joinParts[] = 'JOIN rsf_job_orders r ON r.id = a.rsf_id';
    } else {
        $joinParts[] = 'LEFT JOIN rsf_job_orders r ON r.id = a.rsf_id';
    }
    $join = ' ' . implode(' ', $joinParts) . ' ';

    $stmt = prepareBound(
        $db,
        "SELECT a.id, a.status, a.submitted_at,
                CONCAT(c.first_name,' ',c.last_name) AS applicant,
                r.rsf_number, r.position_title,
                DATEDIFF(NOW(), a.submitted_at) AS days_waiting
         FROM applications a
         {$join}
         WHERE a.status NOT IN ('approved','disapproved')
           AND a.submitted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
           {$fOverdue['where']}
         ORDER BY days_waiting DESC",
        'i' . $fOverdue['types'],
        array_merge([$days], $fOverdue['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'id'           => (int) $r['id'],
            'applicant'    => $r['applicant'],
            'status'       => $r['status'],
            'rsf'          => $r['rsf_number'],
            'position'     => $r['position_title'],
            'days_waiting' => (int) $r['days_waiting'],
        ];
    }
    $stmt->close();
    return ['count' => count($items), 'items' => $items];
}

/* 5. SUBMISSION TREND
   - 'all' (current year)  -> monthly buckets (Jan..Dec)
   - specific month        -> weekly buckets within that month       */
function getWeekly(mysqli $db, string $monthParam, string $start, string $end, array $f): array
{
    if ($monthParam === 'all') {
        $labels      = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $submitted   = array_fill(1, 12, 0);
        $approved    = array_fill(1, 12, 0);
        $disapproved = array_fill(1, 12, 0);

        $stmt = prepareBound(
            $db,
            "SELECT MONTH(a.submitted_at) AS mo, COUNT(*) AS cnt
             FROM applications a {$f['join']}
             WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}
             GROUP BY mo",
            'ss' . $f['types'],
            array_merge([$start, $end], $f['params'])
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $submitted[(int) $r['mo']] = (int) $r['cnt'];
        $stmt->close();

        $stmt = prepareBound(
            $db,
            "SELECT MONTH(a.updated_at) AS mo, a.status, COUNT(*) AS cnt
             FROM applications a {$f['join']}
             WHERE a.status IN ('approved','disapproved')
               AND a.updated_at BETWEEN ? AND ? {$f['where']}
             GROUP BY mo, a.status",
            'ss' . $f['types'],
            array_merge([$start, $end], $f['params'])
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $mo = (int) $r['mo'];
            if ($r['status'] === 'approved')    $approved[$mo]    = (int) $r['cnt'];
            if ($r['status'] === 'disapproved') $disapproved[$mo] = (int) $r['cnt'];
        }
        $stmt->close();

        return [
            'labels'      => $labels,
            'submitted'   => array_values($submitted),
            'approved'    => array_values($approved),
            'disapproved' => array_values($disapproved),
        ];
    }

    // Specific month -> weekly buckets
    $weeks  = [];
    $cursor = strtotime($start);
    $endTs  = strtotime($end);
    while ($cursor <= $endTs) {
        $weeks[] = date('Y', $cursor) . '-W' . str_pad((int) date('W', $cursor), 2, '0', STR_PAD_LEFT);
        $cursor  = strtotime('+1 week', $cursor);
    }
    $lastWeek = date('Y', $endTs) . '-W' . str_pad((int) date('W', $endTs), 2, '0', STR_PAD_LEFT);
    if (!in_array($lastWeek, $weeks, true)) $weeks[] = $lastWeek;
    $weeks = array_values(array_unique($weeks));

    $submitted   = array_fill_keys($weeks, 0);
    $approved    = array_fill_keys($weeks, 0);
    $disapproved = array_fill_keys($weeks, 0);

    $stmt = prepareBound(
        $db,
        "SELECT CONCAT(YEAR(a.submitted_at),'-W',LPAD(WEEK(a.submitted_at,3),2,'0')) AS wk, COUNT(*) AS cnt
         FROM applications a {$f['join']}
         WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}
         GROUP BY wk",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) if (isset($submitted[$r['wk']])) $submitted[$r['wk']] = (int) $r['cnt'];
    $stmt->close();

    $stmt = prepareBound(
        $db,
        "SELECT CONCAT(YEAR(a.updated_at),'-W',LPAD(WEEK(a.updated_at,3),2,'0')) AS wk, a.status, COUNT(*) AS cnt
         FROM applications a {$f['join']}
         WHERE a.status IN ('approved','disapproved')
           AND a.updated_at BETWEEN ? AND ? {$f['where']}
         GROUP BY wk, a.status",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if ($r['status'] === 'approved'    && isset($approved[$r['wk']]))    $approved[$r['wk']]    = (int) $r['cnt'];
        if ($r['status'] === 'disapproved' && isset($disapproved[$r['wk']])) $disapproved[$r['wk']] = (int) $r['cnt'];
    }
    $stmt->close();

    $labels = array_map(fn($w) => 'W' . ltrim(explode('-W', $w)[1], '0'), $weeks);

    return [
        'labels'      => $labels,
        'submitted'   => array_values($submitted),
        'approved'    => array_values($approved),
        'disapproved' => array_values($disapproved),
    ];
}

/* 6. TOP SERVICES BY APPLICATION VOLUME, scoped to range */
function getServices(mysqli $db, string $start, string $end, array $f): array
{
    $countryWhere = $f['country'] !== '' ? ' AND r.worksite_country = ?' : '';
    $countryTypes = $f['country'] !== '' ? 's' : '';
    $countryParams = $f['country'] !== '' ? [$f['country']] : [];

    $provinceJoin = '';
    $provinceTypes = '';
    $provinceParams = [];
    $countExpr = 'COUNT(a.id)';

    if ($f['province'] !== '') {
        $provinceJoin = 'LEFT JOIN clients fc ON fc.cid = a.client_id AND fc.province = ?';
        $provinceTypes = 's';
        $provinceParams = [$f['province']];
        $countExpr = 'COUNT(CASE WHEN fc.cid IS NOT NULL THEN a.id END)';
    }

    $stmt = prepareBound(
        $db,
        "SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
                r.worksite_country, r.classification, r.slots_available, r.deadline,
                {$countExpr} AS applied
         FROM rsf_job_orders r
                  LEFT JOIN applications a ON a.rsf_id = r.id AND a.status <> 'draft' AND a.submitted_at BETWEEN ? AND ?
         {$provinceJoin}
         WHERE 1=1 {$countryWhere}
         GROUP BY r.id
         ORDER BY applied DESC",
        'ss' . $provinceTypes . $countryTypes,
        array_merge([$start, $end], $provinceParams, $countryParams)
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $max  = 1;
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
        if ((int) $r['applied'] > $max) $max = (int) $r['applied'];
    }
    $stmt->close();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'rsf'      => $r['rsf_number'],
            'title'    => $r['position_title'],
            'employer' => $r['employer_name'],
            'country'  => $r['worksite_country'],
            'type'     => $r['classification'] ?? 'Government',
            'slots'    => (int) $r['slots_available'],
            'applied'  => (int) $r['applied'],
            'pct'      => round((int) $r['applied'] / $max * 100),
            'deadline' => $r['deadline'],
        ];
    }
    return $items;
}

/* 7. MONTHLY JOB ORDERS POSTED vs APPLICATIONS RECEIVED, scoped to range */
function getSvcMonthly(mysqli $db, string $start, string $end, array $f): array
{
    $posted   = array_fill(1, 12, 0);
    $received = array_fill(1, 12, 0);

    $postedSql = "SELECT MONTH(created_at) AS mo, COUNT(*) AS cnt
                  FROM rsf_job_orders
                  WHERE created_at BETWEEN ? AND ?";
    $postedTypes = 'ss';
    $postedParams = [$start, $end];
    if ($f['country'] !== '') {
        $postedSql .= ' AND worksite_country = ?';
        $postedTypes .= 's';
        $postedParams[] = $f['country'];
    }
    $postedSql .= ' GROUP BY mo';

    $stmt = prepareBound($db, $postedSql, $postedTypes, $postedParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $posted[(int) $r['mo']] = (int) $r['cnt'];
    $stmt->close();

    $stmt = prepareBound(
        $db,
        "SELECT MONTH(a.submitted_at) AS mo, COUNT(*) AS cnt
         FROM applications a {$f['join']}
         WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}
         GROUP BY mo",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $received[(int) $r['mo']] = (int) $r['cnt'];
    $stmt->close();

    return [
        'labels'   => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'posted'   => array_values($posted),
        'received' => array_values($received),
    ];
}

/* 8. TOP DESTINATION COUNTRIES, scoped to range */
function getDestinations(mysqli $db, string $start, string $end, array $f): array
{
    $fDest = buildFilters('a', 'fc', 'r');

    $stmt = prepareBound(
        $db,
        "SELECT r.worksite_country AS country, COUNT(a.id) AS cnt
         FROM applications a
         JOIN rsf_job_orders r ON r.id = a.rsf_id
         {$fDest['join']}
         WHERE a.submitted_at BETWEEN ? AND ? {$fDest['where']}
         GROUP BY r.worksite_country
         ORDER BY cnt DESC",
        'ss' . $fDest['types'],
        array_merge([$start, $end], $fDest['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ['country' => $r['country'] ?: 'Unspecified', 'cnt' => (int) $r['cnt']];
    }
    $stmt->close();

    $palette = ['#1d4ed8', '#059669', '#d97706', '#6366f1', '#dc2626', '#94a3b8', '#0891b2', '#7c3aed'];

    // Keep top 6, fold the remainder into "Other" so totals still match the filtered period
    $TOP_N = 6;
    $top   = array_slice($rows, 0, $TOP_N);
    $rest  = array_slice($rows, $TOP_N);

    if (!empty($rest)) {
        $otherTotal = array_sum(array_column($rest, 'cnt'));
        if ($otherTotal > 0) {
            $top[] = ['country' => 'Other', 'cnt' => $otherTotal];
        }
    }

    $labels = [];
    $data   = [];
    $colors = [];
    foreach ($top as $i => $row) {
        $labels[] = $row['country'];
        $data[]   = $row['cnt'];
        $colors[] = $palette[$i % count($palette)]; // cycle, never runs out
    }

    $total  = array_sum($data) ?: 1;
    $legend = [];
    foreach ($labels as $i => $l) {
        $legend[] = ['label' => $l, 'pct' => round($data[$i] / $total * 100), 'color' => $colors[$i]];
    }

    return ['labels' => $labels, 'data' => $data, 'colors' => $colors, 'legend' => $legend];
}

/* 9. EVALUATOR PERFORMANCE & WORKLOAD, scoped to range
   Each (evaluator, application) pair in `assignments` lands in exactly ONE bucket.
   The first matching WHEN wins. */
function getEvaluators(mysqli $db, string $start, string $end, array $f): array
{
    $wlColors = ['#059669', '#d97706', '#3b82f6', '#6366f1', '#dc2626', '#94a3b8'];

    /* A. Evaluator list */
    $evaluators = [];
    $res = $db->query(
        "SELECT e.eid, e.full_name, e.email, COALESCE(w.is_active, 1) AS is_active
         FROM evaluators e
         LEFT JOIN webuser w ON w.email = e.email"
    );
    while ($r = $res->fetch_assoc()) {
        $evaluators[(int) $r['eid']] = $r;
    }

    /* B. Mutually exclusive buckets */
    $buckets = [];
    $stmt = prepareBound(
        $db,
        "SELECT x.evaluator_id,
                SUM(x.bucket = 'approved')    AS approved,
                SUM(x.bucket = 'disapproved') AS disapproved,
                SUM(x.bucket = 'queue')       AS queue,
                SUM(x.bucket = 'with_admin')  AS with_admin,
                SUM(x.bucket = 'by_admin')    AS by_admin,
                SUM(x.bucket = 'reassigned')  AS reassigned
         FROM (
             SELECT DISTINCT asn.evaluator_id, asn.application_id,
                CASE
                  WHEN a.status = 'approved' AND EXISTS (
                         SELECT 1 FROM evaluations ev
                         WHERE ev.application_id = a.id AND ev.evaluator_id = asn.evaluator_id
                           AND ev.evaluation_type = 'initial' AND ev.decision = 'approved')
                    THEN 'approved'
                  WHEN a.status = 'disapproved' AND EXISTS (
                         SELECT 1 FROM evaluations ev
                         WHERE ev.application_id = a.id AND ev.evaluator_id = asn.evaluator_id
                           AND ev.evaluation_type = 'initial' AND ev.decision = 'disapproved')
                    THEN 'disapproved'
                  WHEN NOT (a.current_evaluator_id <=> asn.evaluator_id)
                    THEN 'reassigned'
                  WHEN a.status IN ('approved','disapproved')
                    THEN 'by_admin'
                  WHEN a.handling_party = 'admin'
                    THEN 'with_admin'
                  ELSE 'queue'
                END AS bucket
             FROM assignments asn
             JOIN applications a ON a.id = asn.application_id
             {$f['join']}
             WHERE asn.assigned_at BETWEEN ? AND ? {$f['where']}
         ) x
         GROUP BY x.evaluator_id",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $buckets[(int) $r['evaluator_id']] = $r;
    }
    $stmt->close();

    /* C. Avg. time from first assignment to the evaluator's own decision (seconds precision) */
    $turnaround = [];
    $stmt = prepareBound(
        $db,
        "SELECT t.evaluator_id, AVG(t.secs) AS avg_secs
         FROM (
             SELECT ev.evaluator_id,
                    TIMESTAMPDIFF(SECOND,
                        (SELECT MIN(asn.assigned_at) FROM assignments asn
                          WHERE asn.application_id = ev.application_id
                            AND asn.evaluator_id   = ev.evaluator_id),
                        ev.evaluated_at) AS secs
             FROM evaluations ev
             JOIN applications a ON a.id = ev.application_id
             {$f['join']}
             WHERE ev.evaluator_id IS NOT NULL
               AND ev.evaluation_type = 'initial'
               AND ev.decision IN ('approved','disapproved')
               AND ev.evaluated_at BETWEEN ? AND ? {$f['where']}
         ) t
         WHERE t.secs IS NOT NULL AND t.secs >= 0
         GROUP BY t.evaluator_id",
        'ss' . $f['types'],
        array_merge([$start, $end], $f['params'])
    );
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $turnaround[(int) $r['evaluator_id']] = round(((float) $r['avg_secs']) / 3600, 4);
    }
    $stmt->close();

    /* Assemble */
    $list = [];
    foreach ($evaluators as $eid => $r) {
        $b          = $buckets[$eid] ?? [];
        $approved   = (int) ($b['approved'] ?? 0);
        $disap      = (int) ($b['disapproved'] ?? 0);
        $queue      = (int) ($b['queue'] ?? 0);
        $withAdmin  = (int) ($b['with_admin'] ?? 0);
        $byAdmin    = (int) ($b['by_admin'] ?? 0);
        $reassigned = (int) ($b['reassigned'] ?? 0);
        $handled    = $approved + $disap + $queue + $withAdmin + $byAdmin + $reassigned;
        $decided    = $approved + $disap;

        $initials = substr(implode('', array_map(
            fn($p) => strtoupper($p[0] ?? ''),
            array_filter(explode(' ', $r['full_name']))
        )), 0, 2);

        $list[] = [
            'name'          => $r['full_name'],
            'email'         => $r['email'],
            'initials'      => $initials,
            'active'        => (int) $r['is_active'] === 1,
            'handled'       => $handled,
            'approved'      => $approved,
            'disapproved'   => $disap,
            'queue'         => $queue,
            'with_admin'    => $withAdmin,
            'by_admin'      => $byAdmin,
            'reassigned'    => $reassigned,
            'completion'    => $handled > 0 ? (int) round($decided / $handled * 100) : 0,
            'approval_rate' => $decided > 0 ? (int) round($approved / $decided * 100) : null,
            'avg_hours'     => $turnaround[$eid] ?? null,
        ];
    }

    usort($list, fn($a, $b) => [$b['handled'], $a['name']] <=> [$a['handled'], $b['name']]);

    $wlNames = [];
    $wlData  = [];
    $wlCols  = [];
    foreach ($list as $i => &$row) {
        $row['color'] = $wlColors[$i % count($wlColors)];
        $wlCols[]     = $row['color'];
        $parts        = explode(' ', $row['name']);
        $wlNames[]    = ($parts[0] ?? '') . ' ' . (isset($parts[1][0]) ? $parts[1][0] . '.' : '');
        $wlData[]     = $row['handled'];
    }
    unset($row);

    return [
        'staff'           => $list,
        'workload_names'  => $wlNames,
        'workload_data'   => $wlData,
        'workload_colors' => $wlCols,
    ];
}

/* 10. ACTIVE JOB ORDERS — deadline not yet passed; applied count scoped to range */
function getJobOrders(mysqli $db, string $start, string $end, array $f): array
{
    $where = ['r.deadline >= CURDATE()'];
    $types = 'ss';
    $params = [$start, $end];

    $provinceJoin = '';
    $countExpr = 'COUNT(a.id)';
    if ($f['province'] !== '') {
        $provinceJoin = 'LEFT JOIN clients fc ON fc.cid = a.client_id AND fc.province = ?';
        $types .= 's';
        $params[] = $f['province'];
        $countExpr = 'COUNT(CASE WHEN fc.cid IS NOT NULL THEN a.id END)';
    }

    if ($f['country'] !== '') {
        $where[] = 'r.worksite_country = ?';
        $types .= 's';
        $params[] = $f['country'];
    }

    $stmt = prepareBound(
        $db,
        "SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
                r.worksite_country, r.slots_available, r.deadline,
                {$countExpr} AS applied
         FROM rsf_job_orders r
                 LEFT JOIN applications a ON a.rsf_id = r.id AND a.status <> 'draft' AND a.submitted_at BETWEEN ? AND ?
         {$provinceJoin}
         WHERE " . implode(' AND ', $where) . "
         GROUP BY r.id
         ORDER BY r.deadline ASC",
        $types,
        $params
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $jobs = [];
    while ($r = $res->fetch_assoc()) {
        $slots   = (int) $r['slots_available'];
        $applied = (int) $r['applied'];
        $pct     = $slots > 0 ? min(100, round($applied / $slots * 100)) : 0;

        $jobs[] = [
            'rsf'       => $r['rsf_number'],
            'title'     => $r['position_title'],
            'employer'  => $r['employer_name'],
            'country'   => $r['worksite_country'],
            'slots'     => $slots,
            'applied'   => $applied,
            'fill_pct'  => $pct,
            'deadline'  => $r['deadline'],
            'days_left' => (int) ceil((strtotime($r['deadline']) - time()) / 86400),
        ];
    }
    $stmt->close();
    return $jobs;
}

/* 11. DISTINCT DESTINATION COUNTRIES — for the filter pill dropdown */
function getCountries(mysqli $db): array
{
    $res = $db->query(
        "SELECT DISTINCT worksite_country
         FROM rsf_job_orders
         WHERE worksite_country IS NOT NULL AND worksite_country <> ''
         ORDER BY worksite_country ASC"
    );

    $countries = [];
    while ($row = $res->fetch_assoc()) {
        $countries[] = $row['worksite_country'];
    }

    return $countries;
}

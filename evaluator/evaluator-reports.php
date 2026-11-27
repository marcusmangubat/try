<?php

/**
 * evaluator-reports.php – DMW RO IV-A Evaluator Reports
 * Scoped version of the admin "Applicant Reports" page: shows ONLY the
 * applications this logged-in evaluator has ever been linked to — either
 * currently assigned (applications.current_evaluator_id), historically
 * evaluated (evaluations.evaluator_id), or historically assigned
 * (assignments.evaluator_id). That scope condition is baked into
 * buildWhere() so it automatically applies to every summary card, chart,
 * table row, and export — there is no code path that can show another
 * evaluator's applications.
 *
 * One row per application (correlated subqueries used for 1:many
 * relations) so no duplicate rows appear even though an application can
 * have many documents, evaluations, or preferred countries/positions.
 *
 * UI NOTE: presentation layer rebuilt with Tailwind CSS (responsive, with a
 * dedicated mobile card view). All backend logic below is unchanged.
 */
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

/*RESOLVE THE LOGGED-IN EVALUATOR */
$evaluator_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $evaluator_email);
$stmt->execute();
$evalRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$evalRow) {
    // Not a recognized evaluator record — deny access rather than showing an empty/broken report.
    header('Location: ../login.php');
    exit;
}

$evaluator_id   = (int) $evalRow['eid'];
$evaluator_name = $evalRow['full_name'] ?: 'Evaluator';

$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts    = explode(' ', trim($evaluator_name));
$initials = strtoupper((substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1)));

/*HELPERS */

function bindDynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') return;
    $refs = [];
    foreach ($params as $k => $v) $refs[$k] = &$params[$k];
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function q(mysqli $db, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error . ' — SQL: ' . $sql);
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
}

function whereSql(array $where): string
{
    return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
}

function statusLabel(string $status): string
{
    $labels = [
        'pending'          => 'Pending',
        'under_evaluation' => 'Under Evaluation',
        'for_compliance'   => 'For Compliance',
        'resubmitted'      => 'Resubmitted',
        'approved'         => 'Approved',
        'disapproved'      => 'Disapproved',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function badgeClass(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'b-approved';
        case 'disapproved':
            return 'b-disapproved';
        case 'pending':
            return 'b-pending';
        default:
            return 'b-other';
    }
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

function resolveDateRange(string $preset, string $from, string $to): array
{
    switch ($preset) {
        case 'today':
            $d = date('Y-m-d');
            return [$d . ' 00:00:00', $d . ' 23:59:59'];
        case 'week':
            $start = date('Y-m-d', strtotime('monday this week'));
            $end   = date('Y-m-d', strtotime('sunday this week'));
            return [$start . ' 00:00:00', $end . ' 23:59:59'];
        case 'month':
            return [date('Y-m-01') . ' 00:00:00', date('Y-m-t') . ' 23:59:59'];
        case 'year':
            return [date('Y-01-01') . ' 00:00:00', date('Y-12-31') . ' 23:59:59'];
        case 'custom':
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $today = date('Y-m-d');
                if ($from > $today) $from = $today;
                if ($to > $today)   $to   = $today;
                if ($from > $to)    $from = $to;
                return [$from . ' 00:00:00', $to . ' 23:59:59'];
            }
            return [null, null];
        default:
            return [null, null];
    }
}

function chooseGranularity(?string $start, ?string $end): string
{
    if (!$start || !$end) return 'month';
    $days = (strtotime($end) - strtotime($start)) / 86400;
    if ($days <= 31) return 'day';
    if ($days <= 400) return 'month';
    return 'year';
}

/* FILTERS */

$f_date_preset = trim($_GET['date_preset'] ?? '');
$f_date_from   = trim($_GET['date_from'] ?? '');
$f_date_to     = trim($_GET['date_to'] ?? '');
$today_date    = date('Y-m-d');
$f_rsf_id      = (int) ($_GET['rsf_id'] ?? 0);
$f_country     = trim($_GET['country'] ?? '');
$f_status      = trim($_GET['status'] ?? '');
$f_employer    = trim($_GET['employer'] ?? '');
$f_search      = trim($_GET['search'] ?? '');

$validStatuses = ['pending', 'under_evaluation', 'for_compliance', 'resubmitted', 'approved', 'disapproved'];
if ($f_status !== '' && !in_array($f_status, $validStatuses, true)) $f_status = '';

[$rangeStart, $rangeEnd] = resolveDateRange($f_date_preset, $f_date_from, $f_date_to);

/**
 * Builds the WHERE clause used by every summary/chart/table query.
 * The evaluator-scope condition is always the FIRST element, so this
 * evaluator can never see another evaluator's applications no matter
 * what other filters are (or aren't) applied.
 */
function buildWhere(int $evaluatorId, ?string $rangeStart, ?string $rangeEnd): array
{
    global $f_rsf_id, $f_country, $f_status, $f_employer, $f_search;

    $where  = [];
    $types  = '';
    $params = [];

    // Mandatory scope: only applications currently assigned to this evaluator.
    $where[] = 'a.current_evaluator_id = ?';
    $types   .= 'i';
    $params[] = $evaluatorId;

    if ($rangeStart && $rangeEnd) {
        $where[] = 'a.submitted_at BETWEEN ? AND ?';
        $types  .= 'ss';
        $params[] = $rangeStart;
        $params[] = $rangeEnd;
    }
    if ($f_rsf_id > 0) {
        $where[] = 'a.rsf_id = ?';
        $types  .= 'i';
        $params[] = $f_rsf_id;
    }
    if ($f_country !== '') {
        $where[] = 'j.worksite_country = ?';
        $types  .= 's';
        $params[] = $f_country;
    }
    if ($f_status !== '') {
        $where[] = 'a.status = ?';
        $types  .= 's';
        $params[] = $f_status;
    }
    if ($f_employer !== '') {
        $where[] = 'j.employer_name = ?';
        $types  .= 's';
        $params[] = $f_employer;
    }
    if ($f_search !== '') {
        if (ctype_digit($f_search)) {
            $where[]  = '(a.id = ? OR c.email LIKE ? OR CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name, c.suffix) LIKE ?)';
            $types   .= 'iss';
            $params[] = (int) $f_search;
            $params[] = '%' . $f_search . '%';
            $params[] = '%' . $f_search . '%';
        } else {
            $where[]  = '(c.email LIKE ? OR CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name, c.suffix) LIKE ?)';
            $types   .= 'ss';
            $params[] = '%' . $f_search . '%';
            $params[] = '%' . $f_search . '%';
        }
    }

    return [$where, $types, $params];
}

[$where, $types, $params] = buildWhere($evaluator_id, $rangeStart, $rangeEnd);
$whereSql = whereSql($where);

$baseFrom = "FROM applications a
             JOIN clients c ON c.cid = a.client_id
             JOIN rsf_job_orders j ON j.id = a.rsf_id";

$baseFromFull = $baseFrom . "
             LEFT JOIN job_subcategories jsub ON jsub.id = j.subcategory_id
             LEFT JOIN job_categories jc ON jc.id = jsub.category_id
             LEFT JOIN client_profiles cp ON cp.client_id = c.cid
             LEFT JOIN degree_programs dp ON dp.id = cp.degree_id
             LEFT JOIN evaluators ev ON ev.eid = a.current_evaluator_id";

/*  FILTER OPTION LISTS (only from RSFs / countries / employers that
   actually appear among THIS evaluator's applications) */

$scopeSql = "a.current_evaluator_id = ?";

$rsfOptions = [];
$res = q($database, "
    SELECT DISTINCT j.id, j.rsf_number, j.position_title, j.employer_name
    FROM rsf_job_orders j
    JOIN applications a ON a.rsf_id = j.id
    WHERE $scopeSql
    ORDER BY j.position_title ASC
", 'i', [$evaluator_id]);
while ($row = $res->fetch_assoc()) $rsfOptions[] = $row;

$countryOptions = [];
$res = q($database, "
    SELECT DISTINCT j.worksite_country
    FROM rsf_job_orders j
    JOIN applications a ON a.rsf_id = j.id
    WHERE $scopeSql AND j.worksite_country IS NOT NULL AND j.worksite_country <> ''
    ORDER BY j.worksite_country ASC
", 'i', [$evaluator_id]);
while ($row = $res->fetch_assoc()) $countryOptions[] = $row['worksite_country'];

$employerOptions = [];
$res = q($database, "
    SELECT DISTINCT j.employer_name
    FROM rsf_job_orders j
    JOIN applications a ON a.rsf_id = j.id
    WHERE $scopeSql AND j.employer_name IS NOT NULL AND j.employer_name <> ''
    ORDER BY j.employer_name ASC
", 'i', [$evaluator_id]);
while ($row = $res->fetch_assoc()) $employerOptions[] = $row['employer_name'];

/*FILTER SUMMARY TEXT (for the PDF header) */
$filterSummaryParts = [];
if ($f_date_preset !== '') {
    $filterSummaryParts[] = ($f_date_preset === 'custom' && $f_date_from && $f_date_to)
        ? 'Date: ' . $f_date_from . ' to ' . $f_date_to
        : 'Date: ' . ucfirst($f_date_preset);
}
if ($f_rsf_id > 0) {
    foreach ($rsfOptions as $o) {
        if ((int) $o['id'] === $f_rsf_id) {
            $filterSummaryParts[] = 'Position: ' . $o['position_title'];
            break;
        }
    }
}
if ($f_country !== '') $filterSummaryParts[] = 'Country: ' . $f_country;
if ($f_status !== '') $filterSummaryParts[] = 'Status: ' . statusLabel($f_status);
if ($f_employer !== '') $filterSummaryParts[] = 'Employer: ' . $f_employer;
if ($f_search !== '') $filterSummaryParts[] = 'Search: "' . $f_search . '"';
$filterSummaryText = $filterSummaryParts ? implode('  •  ', $filterSummaryParts) : 'All my applications (no filters applied)';

/*SUMMARY CARDS */

$summary = ['total' => 0, 'pending' => 0, 'under_evaluation' => 0, 'for_compliance' => 0, 'resubmitted' => 0, 'approved' => 0, 'disapproved' => 0];
$res = q($database, "
    SELECT
        COUNT(*) AS total,
        SUM(a.status = 'pending') AS pending,
        SUM(a.status = 'under_evaluation') AS under_evaluation,
        SUM(a.status = 'for_compliance') AS for_compliance,
        SUM(a.status = 'resubmitted') AS resubmitted,
        SUM(a.status = 'approved') AS approved,
        SUM(a.status = 'disapproved') AS disapproved
    $baseFrom
    $whereSql
", $types, $params);
if ($row = $res->fetch_assoc()) {
    foreach ($summary as $k => $v) $summary[$k] = (int) ($row[$k] ?? 0);
}
$summary['active'] = $summary['pending'] + $summary['under_evaluation'] + $summary['for_compliance'] + $summary['resubmitted'];

// My own decision stats — strictly from evaluations rows I authored, filtered the
// same way (date range / rsf / country / status / employer / search) but scoped
// to e.evaluator_id, not the broader "linked to me" application scope above.
function buildMyEvalWhere(int $evaluatorId, ?string $rangeStart, ?string $rangeEnd): array
{
    global $f_rsf_id, $f_country, $f_status, $f_employer, $f_search;

    $where  = ['e.evaluator_id = ?', 'a.current_evaluator_id = ?'];
    $types  = 'ii';
    $params = [$evaluatorId, $evaluatorId];

    if ($rangeStart && $rangeEnd) {
        $where[] = 'a.submitted_at BETWEEN ? AND ?';
        $types  .= 'ss';
        $params[] = $rangeStart;
        $params[] = $rangeEnd;
    }
    if ($f_rsf_id > 0) {
        $where[] = 'a.rsf_id = ?';
        $types  .= 'i';
        $params[] = $f_rsf_id;
    }
    if ($f_country !== '') {
        $where[] = 'j.worksite_country = ?';
        $types  .= 's';
        $params[] = $f_country;
    }
    if ($f_status !== '') {
        $where[] = 'a.status = ?';
        $types  .= 's';
        $params[] = $f_status;
    }
    if ($f_employer !== '') {
        $where[] = 'j.employer_name = ?';
        $types  .= 's';
        $params[] = $f_employer;
    }
    if ($f_search !== '') {
        if (ctype_digit($f_search)) {
            $where[]  = '(a.id = ? OR c.email LIKE ? OR CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name, c.suffix) LIKE ?)';
            $types   .= 'iss';
            $params[] = (int) $f_search;
            $params[] = '%' . $f_search . '%';
            $params[] = '%' . $f_search . '%';
        } else {
            $where[]  = '(c.email LIKE ? OR CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name, c.suffix) LIKE ?)';
            $types   .= 'ss';
            $params[] = '%' . $f_search . '%';
            $params[] = '%' . $f_search . '%';
        }
    }

    return [$where, $types, $params];
}

[$myEvalWhere, $myEvalTypes, $myEvalParams] = buildMyEvalWhere($evaluator_id, $rangeStart, $rangeEnd);
$myEvalWhereSql = whereSql($myEvalWhere);
$myEvalFrom = "FROM evaluations e
               JOIN applications a ON a.id = e.application_id
               JOIN clients c ON c.cid = a.client_id
               JOIN rsf_job_orders j ON j.id = a.rsf_id";

$myEvalStats = ['total_evaluations' => 0, 'approved' => 0, 'disapproved' => 0, 'for_compliance' => 0, 'avg_days' => null, 'decided_count' => 0];
$res = q($database, "
    SELECT
        COUNT(*) AS total_evaluations,
        SUM(e.decision = 'approved') AS approved,
        SUM(e.decision = 'disapproved') AS disapproved,
        SUM(e.decision = 'for_compliance') AS for_compliance
    $myEvalFrom
    $myEvalWhereSql
", $myEvalTypes, $myEvalParams);
if ($row = $res->fetch_assoc()) {
    $myEvalStats['total_evaluations'] = (int) ($row['total_evaluations'] ?? 0);
    $myEvalStats['approved']          = (int) ($row['approved'] ?? 0);
    $myEvalStats['disapproved']       = (int) ($row['disapproved'] ?? 0);
    $myEvalStats['for_compliance']    = (int) ($row['for_compliance'] ?? 0);
}

// Avg. decision time: only the LAST terminal decision per application, authored by me.
$sqlAvg = "
    WITH my_latest_eval AS (
        SELECT e.application_id, e.evaluated_at,
               ROW_NUMBER() OVER (PARTITION BY e.application_id ORDER BY e.evaluated_at DESC) AS rn
        FROM evaluations e
        WHERE e.evaluator_id = ? AND e.decision IN ('approved','disapproved')
    )
    SELECT ROUND(AVG(DATEDIFF(mle.evaluated_at, a.submitted_at)), 1) AS avg_days, COUNT(*) AS decided_count
    FROM applications a
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    JOIN my_latest_eval mle ON mle.application_id = a.id AND mle.rn = 1
";
// Re-use the same optional filters (date/rsf/country/status/employer/search) applied to `a`/`j`/`c`.
$avgFilters = ['a.current_evaluator_id = ?'];
$avgTypes   = 'i';
$avgParams  = [$evaluator_id];
if ($rangeStart && $rangeEnd) {
    $avgFilters[] = 'a.submitted_at BETWEEN ? AND ?';
    $avgTypes .= 'ss';
    $avgParams[] = $rangeStart;
    $avgParams[] = $rangeEnd;
}
if ($f_rsf_id > 0) {
    $avgFilters[] = 'a.rsf_id = ?';
    $avgTypes .= 'i';
    $avgParams[] = $f_rsf_id;
}
if ($f_country !== '') {
    $avgFilters[] = 'j.worksite_country = ?';
    $avgTypes .= 's';
    $avgParams[] = $f_country;
}
if ($f_status !== '') {
    $avgFilters[] = 'a.status = ?';
    $avgTypes .= 's';
    $avgParams[] = $f_status;
}
if ($f_employer !== '') {
    $avgFilters[] = 'j.employer_name = ?';
    $avgTypes .= 's';
    $avgParams[] = $f_employer;
}
if ($f_search !== '') {
    if (ctype_digit($f_search)) {
        $avgFilters[]  = '(a.id = ? OR c.email LIKE ? OR CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name, c.suffix) LIKE ?)';
        $avgTypes .= 'iss';
        $avgParams[] = (int) $f_search;
        $avgParams[] = '%' . $f_search . '%';
        $avgParams[] = '%' . $f_search . '%';
    } else {
        $avgFilters[]  = '(c.email LIKE ? OR CONCAT_WS(\' \', c.first_name, c.middle_name, c.last_name, c.suffix) LIKE ?)';
        $avgTypes .= 'ss';
        $avgParams[] = '%' . $f_search . '%';
        $avgParams[] = '%' . $f_search . '%';
    }
}
if ($avgFilters) $sqlAvg .= ' WHERE ' . implode(' AND ', $avgFilters);

$res = q($database, $sqlAvg, 'i' . $avgTypes, array_merge([$evaluator_id], $avgParams));
if ($row = $res->fetch_assoc()) {
    $myEvalStats['avg_days']       = $row['avg_days'] !== null ? (float) $row['avg_days'] : null;
    $myEvalStats['decided_count']  = (int) ($row['decided_count'] ?? 0);
}

/*CHART DATASETS */

$granularity = chooseGranularity($rangeStart, $rangeEnd);
$dateExpr = $granularity === 'day' ? "DATE_FORMAT(a.submitted_at, '%Y-%m-%d')"
    : ($granularity === 'year' ? "DATE_FORMAT(a.submitted_at, '%Y')" : "DATE_FORMAT(a.submitted_at, '%Y-%m')");
$trend = [];
$res = q($database, "SELECT $dateExpr AS bucket, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY bucket ORDER BY bucket ASC", $types, $params);
while ($row = $res->fetch_assoc()) $trend[] = $row;

$statusChart = [];
foreach (['pending', 'under_evaluation', 'for_compliance', 'resubmitted', 'approved', 'disapproved'] as $s) {
    $statusChart[] = ['label' => statusLabel($s), 'cnt' => $summary[$s]];
}

$byCountry = [];
$res = q($database, "SELECT COALESCE(NULLIF(j.worksite_country,''),'Not Specified') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byCountry[] = $row;

$byPosition = [];
$res = q($database, "SELECT COALESCE(NULLIF(j.position_title,''),'Not Specified') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byPosition[] = $row;

$byEmployer = [];
$res = q($database, "SELECT COALESCE(NULLIF(j.employer_name,''),'Not Specified') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byEmployer[] = $row;

$approvedVsDisapproved = [
    ['label' => 'Approved', 'cnt' => $myEvalStats['approved']],
    ['label' => 'Disapproved', 'cnt' => $myEvalStats['disapproved']],
];

/* MAIN APPLICANT TABLE — one row per application, scoped to me */

$rows = [];
$res = q($database, "
    SELECT
        a.id AS application_id, a.status, a.handling_party, a.submitted_at, a.updated_at, a.notes AS admin_notes,
        c.cid AS client_id, c.first_name, c.middle_name, c.last_name, c.suffix,
        c.email AS applicant_email, c.phone AS applicant_phone,
        j.id AS job_order_id, j.rsf_number, j.position_title, j.employer_name, j.worksite_country, j.classification,
        jc.category_name, jsub.subcategory_name,
        ev.full_name AS current_evaluator_name,
        cp.educational_attainment, cp.course_degree, dp.degree_name, cp.work_experience_years,
        cp.last_employer, cp.last_position, cp.citizenship, cp.civil_status, cp.school_name,
        cp.year_graduated, cp.height_cm, cp.weight_kg,
        (SELECT e.decision FROM evaluations e WHERE e.application_id = a.id AND e.evaluator_id = ? ORDER BY e.evaluated_at DESC LIMIT 1) AS my_latest_decision,
        (SELECT e.evaluated_at FROM evaluations e WHERE e.application_id = a.id AND e.evaluator_id = ? ORDER BY e.evaluated_at DESC LIMIT 1) AS my_latest_decision_at,
        (SELECT e.comments FROM evaluations e WHERE e.application_id = a.id AND e.evaluator_id = ? ORDER BY e.evaluated_at DESC LIMIT 1) AS my_latest_comment,
        (SELECT COUNT(*) FROM evaluations e WHERE e.application_id = a.id AND e.evaluator_id = ?) AS my_evaluation_count,
        (SELECT COUNT(*) FROM application_documents ad WHERE ad.application_id = a.id) AS document_count,
        (SELECT pc.country FROM client_preferred_countries pc WHERE pc.client_id = c.cid ORDER BY pc.priority_order ASC LIMIT 1) AS preferred_country,
        (SELECT jsub2.subcategory_name FROM client_preferred_positions pp
            JOIN job_subcategories jsub2 ON jsub2.id = pp.subcategory_id
            WHERE pp.client_id = c.cid ORDER BY pp.priority_order ASC LIMIT 1) AS preferred_position
    $baseFromFull
    $whereSql
    ORDER BY a.submitted_at DESC
    LIMIT 5000
", 'iiii' . $types, array_merge([$evaluator_id, $evaluator_id, $evaluator_id, $evaluator_id], $params));
while ($row = $res->fetch_assoc()) $rows[] = $row;

foreach ($rows as &$r) {
    $r['applicant_name']         = fullName($r);
    $r['submitted_at_fmt']       = $r['submitted_at'] ? date('M j, Y g:i A', strtotime($r['submitted_at'])) : '—';
    $r['updated_at_fmt']         = $r['updated_at'] ? date('M j, Y g:i A', strtotime($r['updated_at'])) : '—';
    $r['status_label']           = statusLabel($r['status']);
    $r['status_badge_class']     = badgeClass($r['status']);
    $r['handling_party_label']   = $r['handling_party'] ? ucfirst($r['handling_party']) : '—';
    $r['current_evaluator_name'] = $r['current_evaluator_name'] ?: 'Unassigned';
    $r['my_latest_decision_label'] = $r['my_latest_decision'] ? statusLabel($r['my_latest_decision']) : 'No decision yet';
    $r['my_latest_decision_at_fmt'] = $r['my_latest_decision_at'] ? date('M j, Y g:i A', strtotime($r['my_latest_decision_at'])) : '—';
    $r['my_latest_comment']      = $r['my_latest_comment'] ?: '—';
    $r['my_evaluation_count']    = (int) $r['my_evaluation_count'];
    $r['document_count']         = (int) $r['document_count'];
    $r['degree_course']          = $r['degree_name'] ?: ($r['course_degree'] ?: '—');
    $r['educational_attainment'] = $r['educational_attainment'] ?: '—';
    $r['work_experience_display'] = $r['work_experience_years'] !== null ? $r['work_experience_years'] . ' yr(s)' : '—';
    $r['preferred_country']      = $r['preferred_country'] ?: '—';
    $r['preferred_position']     = $r['preferred_position'] ?: '—';
    $r['category_name']          = $r['category_name'] ?: '—';
    $r['subcategory_name']       = $r['subcategory_name'] ?: '—';

    $profileFields = [
        $r['citizenship'],
        $r['civil_status'],
        $r['educational_attainment'] !== '—' ? $r['educational_attainment'] : null,
        $r['degree_course'] !== '—' ? $r['degree_course'] : null,
        $r['school_name'],
        $r['year_graduated'],
        $r['work_experience_years'],
        $r['last_employer'],
        $r['last_position'],
        $r['height_cm'],
        $r['weight_kg'],
    ];
    $filled = count(array_filter($profileFields, fn($v) => $v !== null && $v !== ''));
    $r['profile_completion'] = round($filled / count($profileFields) * 100);
}
unset($r);

$exportColumns = [
    ['key' => 'applicant_name',            'label' => 'Applicant Name'],
    ['key' => 'applicant_email',           'label' => 'Email'],
    ['key' => 'applicant_phone',           'label' => 'Phone'],
    ['key' => 'submitted_at_fmt',          'label' => 'Application Date'],
    ['key' => 'position_title',            'label' => 'Position'],
    ['key' => 'category_name',             'label' => 'Category'],
    ['key' => 'subcategory_name',          'label' => 'Subcategory'],
    ['key' => 'employer_name',             'label' => 'Employer'],
    ['key' => 'worksite_country',          'label' => 'Worksite Country'],
    ['key' => 'classification',            'label' => 'Classification'],
    ['key' => 'status_label',              'label' => 'Status'],
    ['key' => 'handling_party_label',      'label' => 'Handling Party'],
    ['key' => 'current_evaluator_name',    'label' => 'Currently Assigned To'],
    ['key' => 'my_latest_decision_label',  'label' => 'My Latest Decision'],
    ['key' => 'my_latest_decision_at_fmt', 'label' => 'My Latest Decision Date'],
    ['key' => 'my_evaluation_count',       'label' => 'My Evaluation Count'],
    ['key' => 'my_latest_comment',         'label' => 'My Latest Comment'],
    ['key' => 'document_count',            'label' => 'Documents'],
    ['key' => 'profile_completion',        'label' => 'Profile Completion (%)'],
    ['key' => 'educational_attainment',    'label' => 'Education'],
    ['key' => 'degree_course',             'label' => 'Degree / Course'],
    ['key' => 'work_experience_display',   'label' => 'Work Experience'],
    ['key' => 'preferred_country',         'label' => 'Preferred Country'],
    ['key' => 'preferred_position',        'label' => 'Preferred Position'],
    ['key' => 'updated_at_fmt',            'label' => 'Last Updated'],
];

$tableColumns = [
    ['key' => 'applicant_name',           'label' => 'Applicant Name'],
    ['key' => 'applicant_email',          'label' => 'Email'],
    ['key' => 'position_title',           'label' => 'Position'],
    ['key' => 'employer_name',            'label' => 'Employer'],
    ['key' => 'worksite_country',         'label' => 'Country'],
    ['key' => 'status_label',             'label' => 'Status'],
    ['key' => 'my_latest_decision_label', 'label' => 'My Decision'],
    ['key' => 'submitted_at_fmt',         'label' => 'Application Date'],
];

// Dock "Apps" badge — applications currently assigned to me and still active.
$badge_stmt = $database->prepare("SELECT COUNT(*) AS total FROM applications WHERE current_evaluator_id = ? AND status IN ('under_evaluation', 'for_compliance', 'resubmitted')");
$badge_stmt->bind_param('i', $evaluator_id);
$badge_stmt->execute();
$active_apps = $badge_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$badge_stmt->close();

$generatedAt = date('F j, Y g:i A');
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

/* ── VIEW-ONLY: shared Tailwind class strings (presentation only, no logic) ── */
$cardCls   = 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-4 sm:p-6 mb-4 print:p-3 print:mb-3 print:border-slate-300 print:break-inside-avoid';
$h2Cls     = 'flex items-center gap-2 m-0 mb-1 text-[15px] font-bold text-slate-900 dark:text-slate-100';
$subCls    = 'm-0 mb-4 text-[11px] leading-relaxed text-slate-600 dark:text-slate-400';
$btnBase   = 'inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 sm:py-2 rounded-lg border text-xs font-semibold no-underline cursor-pointer transition-colors disabled:opacity-60 disabled:cursor-not-allowed';
$btnDef    = $btnBase . ' border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 hover:bg-slate-50 dark:hover:bg-slate-700';
$btnPri    = $btnBase . ' border-blue-700 bg-blue-700 text-white hover:bg-blue-500 hover:border-blue-500';
$btnGreen  = $btnBase . ' border-emerald-500 bg-emerald-500 text-white hover:bg-emerald-600 hover:border-emerald-600';
$btnRed    = $btnBase . ' border-red-600 bg-red-600 text-white hover:bg-red-700 hover:border-red-700';
$labelCls  = 'block mb-1.5 text-[10.5px] font-bold uppercase tracking-wide text-slate-600 dark:text-slate-400';
$fieldCls  = 'w-full px-2.5 py-2 border border-slate-200 dark:border-slate-600 rounded-lg text-base sm:text-[12.5px] bg-slate-50 dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:bg-white dark:focus:bg-slate-800';
$dateCls   = 'w-full py-2 pl-8 pr-2.5 border border-slate-200 dark:border-slate-600 rounded-lg text-base sm:text-[12.5px] bg-slate-50 dark:bg-slate-700 text-slate-900 dark:text-slate-100 cursor-pointer dark:[color-scheme:dark] focus:outline-none focus:ring-2 focus:ring-blue-400 focus:bg-white dark:focus:bg-slate-800 group-[.dw-error]:border-red-600 group-[.dw-error]:bg-red-50 dark:group-[.dw-error]:bg-red-400/10 [&::-webkit-calendar-picker-indicator]:opacity-0 [&::-webkit-calendar-picker-indicator]:absolute [&::-webkit-calendar-picker-indicator]:inset-0 [&::-webkit-calendar-picker-indicator]:w-full [&::-webkit-calendar-picker-indicator]:h-full [&::-webkit-calendar-picker-indicator]:cursor-pointer';
$dateLbl   = 'flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-600 dark:text-slate-400';
$tileCls   = 'border border-l-[3px] rounded-[10px] px-3.5 py-3 bg-slate-50 dark:bg-slate-700/60 lg:flex-1 lg:min-w-[135px] lg:whitespace-nowrap print:break-inside-avoid';
$chartCard = 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-3.5 sm:p-4 print:mb-3.5 print:w-full print:break-inside-avoid print:border-slate-300';
$chartH3   = 'flex items-center gap-1.5 m-0 mb-3 text-[12.5px] font-bold text-slate-900 dark:text-slate-100';
$chartBox  = 'relative h-[240px] sm:h-[260px] print:h-[230px] print:!w-full';

$tiles = [
    [$summary['total'],            'My Total Applications',   'border-blue-500'],
    [$summary['pending'],          'Pending',                 'border-yellow-500'],
    [$summary['under_evaluation'], 'Under Evaluation',        'border-indigo-500'],
    [$summary['for_compliance'],   'For Compliance',          'border-amber-600'],
    [$summary['resubmitted'],      'Resubmitted',             'border-pink-500'],
    [$summary['approved'],         'Approved',                'border-emerald-500'],
    [$summary['disapproved'],      'Disapproved',             'border-red-600'],
    [$summary['active'],           'Active Applications',     'border-blue-700'],
    [$myEvalStats['total_evaluations'], 'My Evaluation Entries', 'border-cyan-600'],
    [$myEvalStats['avg_days'] !== null ? $myEvalStats['avg_days'] . 'd' : '—', 'Avg. Decision Time (' . $myEvalStats['decided_count'] . ' decided)', 'border-slate-500'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Reports – DMW RO IV-A Evaluator Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Dark mode follows the site's existing [data-theme="dark"] attribute (set by theme.js).
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]'],
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"DM Sans"', 'system-ui', 'sans-serif'],
                        serif: ['"DM Serif Display"', 'serif']
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <!-- Shared portal chrome (top bar, dock, theme toggle) -->
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <style>
        /* Only what Tailwind utilities cannot express: shared top-bar logo + exact print colours */
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
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

<body class="m-0 font-sans !bg-[#eef2f7] dark:!bg-slate-900 text-slate-900 dark:text-slate-200 print:!bg-white">

    <!--  TOP BAR  -->
    <header class="topbar no-print print:hidden">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="h-9">
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

    <div class="max-w-[1280px] mx-auto mt-4 sm:mt-5 px-3 sm:px-4 pb-28 print:max-w-full print:m-0 print:p-0">

        <!-- LETTERHEAD -->
        <div class="<?= $cardCls ?> flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-3.5 min-w-0">
                <img src="../pictures/dmw_logo.png" alt="DMW logo" class="h-11 sm:h-[46px] w-auto shrink-0">
                <div class="min-w-0">
                    <h1 class="m-0 mb-0.5 font-serif text-base sm:text-lg font-normal text-slate-900 dark:text-slate-100">My Evaluation Reports</h1>
                    <p class="m-0 text-[11px] text-slate-600 dark:text-slate-400"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($evaluator_name) ?> — Applications and evaluations linked to you only</p>
                </div>
            </div>
            <div class="flex flex-row sm:flex-col gap-x-4 gap-y-0.5 flex-wrap sm:text-right text-[11px] text-slate-600 dark:text-slate-400">
                <div>Generated: <b class="text-slate-900 dark:text-slate-100"><?= htmlspecialchars($generatedAt) ?></b></div>
                <div>Records matched: <b class="text-slate-900 dark:text-slate-100"><?= (int) $summary['total'] ?></b></div>
            </div>
        </div>

        <!-- ACTIONS -->
        <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-2 mb-4 no-print print:hidden">
            <a href="dashboard.php" class="<?= $btnDef ?>"><i class="fas fa-arrow-left"></i> Back<span class="hidden sm:inline">&nbsp;to Dashboard</span></a>
            <button class="<?= $btnDef ?>" onclick="window.print()"><i class="fas fa-print"></i> Print<span class="hidden sm:inline">&nbsp;Report</span></button>
            <button class="<?= $btnGreen ?>" onclick="exportExcel()"><i class="fas fa-file-excel"></i> Export Excel</button>
            <button class="<?= $btnDef ?>" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
            <div class="relative col-span-2 sm:col-span-1 pdf-export-wrap">
                <button class="<?= $btnRed ?> w-full sm:w-auto" id="btnPdf" onclick="togglePdfMenu(event)" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-file-pdf"></i> Export PDF <i class="fas fa-chevron-down text-[9px] ml-0.5"></i>
                </button>
                <div id="pdfExportMenu" class="hidden absolute top-full left-0 right-0 sm:right-auto sm:w-72 mt-1.5 p-1.5 z-[90] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-[10px] shadow-xl">
                    <button type="button" class="flex items-start gap-2.5 w-full text-left p-2.5 rounded-lg bg-transparent border-0 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700" onclick="exportPDF('details')">
                        <i class="fas fa-table-list text-blue-500 text-[13px] mt-0.5 w-4 text-center shrink-0"></i>
                        <span class="flex flex-col gap-px">
                            <strong class="text-xs font-bold text-slate-900 dark:text-slate-100">My Applications Only</strong>
                            <small class="text-[10.5px] text-slate-500 dark:text-slate-400">Summary cards + applicant table + full records</small>
                        </span>
                    </button>
                    <button type="button" class="flex items-start gap-2.5 w-full text-left p-2.5 rounded-lg bg-transparent border-0 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700" onclick="exportPDF('charts')">
                        <i class="fas fa-chart-column text-indigo-500 text-[13px] mt-0.5 w-4 text-center shrink-0"></i>
                        <span class="flex flex-col gap-px">
                            <strong class="text-xs font-bold text-slate-900 dark:text-slate-100">Graphs Only</strong>
                            <small class="text-[10.5px] text-slate-500 dark:text-slate-400">Visual analytics charts</small>
                        </span>
                    </button>
                    <div class="h-px bg-slate-200 dark:bg-slate-700 my-1 mx-0.5"></div>
                    <button type="button" class="flex items-start gap-2.5 w-full text-left p-2.5 rounded-lg bg-transparent border-0 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700" onclick="exportPDF('both')">
                        <i class="fas fa-file-pdf text-red-600 text-[13px] mt-0.5 w-4 text-center shrink-0"></i>
                        <span class="flex flex-col gap-px">
                            <strong class="text-xs font-bold text-slate-900 dark:text-slate-100">Both (Complete Report)</strong>
                            <small class="text-[10.5px] text-slate-500 dark:text-slate-400">Downloads both PDF files</small>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- FILTERS -->
        <form class="<?= $cardCls ?> no-print print:hidden" id="filterForm" method="GET" action="evaluator-reports.php">
            <div class="flex items-center justify-between gap-2">
                <h2 class="<?= $h2Cls ?>"><i class="fas fa-sliders text-blue-500"></i> Filters</h2>
                <button type="button" id="filterToggle" class="md:hidden <?= $btnDef ?> !px-3 !py-1.5" aria-controls="filterBody" aria-expanded="<?= $filterSummaryParts ? 'true' : 'false' ?>">
                    <i class="fas fa-chevron-down text-[9px] transition-transform <?= $filterSummaryParts ? 'rotate-180' : '' ?>" id="filterToggleIcon"></i>
                    <span id="filterToggleText"><?= $filterSummaryParts ? 'Hide filters' : 'Show filters' ?></span>
                </button>
            </div>

            <div id="filterBody" class="<?= $filterSummaryParts ? '' : 'hidden' ?> md:block mt-1">
                <p class="<?= $subCls ?>">Only applications you currently handle or have evaluated appear here. All filters apply together to the summary cards, charts, table, and exports.</p>

                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,190px),1fr))] gap-3">
                    <div>
                        <label class="<?= $labelCls ?>">Date Range</label>
                        <select name="date_preset" id="datePreset" class="<?= $fieldCls ?>">
                            <option value="" <?= $f_date_preset === '' ? 'selected' : '' ?>>All Time</option>
                            <option value="today" <?= $f_date_preset === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= $f_date_preset === 'week' ? 'selected' : '' ?>>This Week</option>
                            <option value="month" <?= $f_date_preset === 'month' ? 'selected' : '' ?>>This Month</option>
                            <option value="year" <?= $f_date_preset === 'year' ? 'selected' : '' ?>>This Year</option>
                            <option value="custom" <?= $f_date_preset === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                        </select>
                    </div>

                    <div class="col-span-full sm:col-span-2">
                        <label class="<?= $labelCls ?>">Custom Range</label>
                        <div id="customRange" class="grid-cols-1 sm:grid-cols-2 gap-3 <?= $f_date_preset === 'custom' ? 'grid' : 'hidden' ?>">
                            <div class="flex flex-col gap-1.5">
                                <span class="<?= $dateLbl ?>"><i class="fas fa-calendar-day text-[9px] text-blue-500"></i> From</span>
                                <div class="group relative flex items-center" id="fromWrap">
                                    <i class="fas fa-calendar absolute left-2.5 text-xs text-slate-400 pointer-events-none"></i>
                                    <input type="date" name="date_from" id="dateFrom" class="<?= $dateCls ?>" value="<?= htmlspecialchars($f_date_from) ?>" max="<?= htmlspecialchars($f_date_to !== '' && $f_date_to < $today_date ? $f_date_to : $today_date) ?>">
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <span class="<?= $dateLbl ?>"><i class="fas fa-calendar-check text-[9px] text-blue-500"></i> To</span>
                                <div class="group relative flex items-center" id="toWrap">
                                    <i class="fas fa-calendar absolute left-2.5 text-xs text-slate-400 pointer-events-none"></i>
                                    <input type="date" name="date_to" id="dateTo" class="<?= $dateCls ?>" value="<?= htmlspecialchars($f_date_to) ?>" min="<?= htmlspecialchars($f_date_from ?: '') ?>" max="<?= htmlspecialchars($today_date) ?>">
                                </div>
                            </div>
                            <div id="rangeHint" class="col-span-full flex items-center gap-1.5 min-h-[14px] text-[10.5px] text-slate-400 [&.err]:text-red-600 [&.err]:font-semibold"><i class="fas fa-circle-info text-[10px]"></i> Select both a start and end date.</div>
                        </div>
                    </div>

                    <div>
                        <label class="<?= $labelCls ?>">Job / Position</label>
                        <select name="rsf_id" class="<?= $fieldCls ?>">
                            <option value="0">All Positions</option>
                            <?php foreach ($rsfOptions as $o): ?>
                                <option value="<?= (int) $o['id'] ?>" <?= $f_rsf_id === (int) $o['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($o['position_title'] . ' — ' . $o['employer_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="<?= $labelCls ?>">Country</label>
                        <select name="country" class="<?= $fieldCls ?>">
                            <option value="">All Countries</option>
                            <?php foreach ($countryOptions as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $f_country === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="<?= $labelCls ?>">Status</label>
                        <select name="status" class="<?= $fieldCls ?>">
                            <option value="">All Statuses</option>
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= statusLabel($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="<?= $labelCls ?>">Employer</label>
                        <select name="employer" class="<?= $fieldCls ?>">
                            <option value="">All Employers</option>
                            <?php foreach ($employerOptions as $e): ?>
                                <option value="<?= htmlspecialchars($e) ?>" <?= $f_employer === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-span-full sm:col-span-2">
                        <label class="<?= $labelCls ?>">Search (Name / Email / Application ID)</label>
                        <input type="text" name="search" class="<?= $fieldCls ?>" value="<?= htmlspecialchars($f_search) ?>" placeholder="e.g. Marcus Mangubat, mgmangubat@ccc.edu.ph, 123">
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:flex gap-2 mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                    <button type="submit" class="<?= $btnPri ?>"><i class="fas fa-filter"></i> Generate Report</button>
                    <a href="evaluator-reports.php" class="<?= $btnDef ?>"><i class="fas fa-rotate-left"></i> Reset Filters</a>
                </div>
            </div>
        </form>

        <!-- SUMMARY CARDS -->
        <div class="<?= $cardCls ?>">
            <h2 class="<?= $h2Cls ?>"><i class="fas fa-chart-simple text-blue-500"></i> Report Summary</h2>
            <p class="<?= $subCls ?>">Totals for the applications matching the current filters and linked to you.</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2.5 lg:flex lg:overflow-x-auto lg:pb-2 print:flex print:flex-wrap">
                <?php foreach ($tiles as [$tv, $tl, $tc]): ?>
                    <div class="<?= $tileCls ?> <?= $tc ?>">
                        <div class="text-xl sm:text-[22px] font-extrabold leading-tight"><?= htmlspecialchars((string) $tv) ?></div>
                        <div class="mt-0.5 text-[10.5px] font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-400"><?= htmlspecialchars($tl) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- CHARTS -->
        <div class="<?= $cardCls ?> print:break-inside-auto">
            <h2 class="<?= $h2Cls ?>"><i class="fas fa-chart-column text-indigo-500"></i> Visual Analytics</h2>
            <p class="<?= $subCls ?>">All charts reflect the currently applied filters and only your own applications/evaluations.</p>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,360px),1fr))] gap-4 print:block">
                <div class="<?= $chartCard ?>">
                    <h3 class="<?= $chartH3 ?>"><i class="fas fa-chart-line text-blue-500"></i> My Applications Over Time (<?= $granularity ?>)</h3>
                    <div class="<?= $chartBox ?>"><canvas id="chartTrend"></canvas></div>
                </div>
                <div class="<?= $chartCard ?>">
                    <h3 class="<?= $chartH3 ?>"><i class="fas fa-chart-pie text-indigo-500"></i> Status Distribution</h3>
                    <div class="<?= $chartBox ?>"><canvas id="chartStatus"></canvas></div>
                </div>
                <div class="<?= $chartCard ?>">
                    <h3 class="<?= $chartH3 ?>"><i class="fas fa-globe text-amber-600"></i> By Country</h3>
                    <div class="<?= $chartBox ?>"><canvas id="chartCountry"></canvas></div>
                </div>
                <div class="<?= $chartCard ?>">
                    <h3 class="<?= $chartH3 ?>"><i class="fas fa-briefcase text-yellow-500"></i> By Position</h3>
                    <div class="<?= $chartBox ?>"><canvas id="chartPosition"></canvas></div>
                </div>
                <div class="<?= $chartCard ?>">
                    <h3 class="<?= $chartH3 ?>"><i class="fas fa-building text-pink-500"></i> By Employer</h3>
                    <p class="-mt-2 mb-2 text-[10.5px] text-slate-400"><i class="fas fa-arrows-left-right"></i> Scroll horizontally to view full employer names</p>
                    <div id="employerScrollHolder" class="<?= $chartBox ?> overflow-x-auto overflow-y-hidden print:overflow-visible [&::-webkit-scrollbar]:h-1.5 [&::-webkit-scrollbar-thumb]:bg-slate-300 dark:[&::-webkit-scrollbar-thumb]:bg-slate-600 [&::-webkit-scrollbar-thumb]:rounded-full">
                        <div id="employerScrollInner" class="h-full print:!w-full print:!min-w-0">
                            <canvas id="chartEmployer"></canvas>
                        </div>
                    </div>
                </div>
                <div class="<?= $chartCard ?>">
                    <h3 class="<?= $chartH3 ?>"><i class="fas fa-scale-balanced text-blue-700"></i> My Decisions: Approved vs. Disapproved</h3>
                    <div class="<?= $chartBox ?>"><canvas id="chartApprovedVs"></canvas></div>
                </div>
            </div>
        </div>

        <!-- APPLICANT TABLE / MOBILE CARDS -->
        <div class="<?= $cardCls ?> print:break-inside-auto">
            <h2 class="<?= $h2Cls ?>"><i class="fas fa-table-list text-blue-500"></i> My Applications Table</h2>
            <p class="<?= $subCls ?>">One row per application linked to you. <span class="md:hidden">Tap a card to view the full application detail.</span><span class="hidden md:inline">Click a row to view the full application detail. Click a column header to sort.</span></p>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5 mb-3 no-print print:hidden">
                <div class="flex items-center gap-2 w-full sm:w-auto sm:min-w-[240px] px-3 py-2 sm:py-1.5 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700">
                    <i class="fas fa-magnifying-glass text-slate-400"></i>
                    <input type="text" id="quickFilter" class="flex-1 min-w-0 bg-transparent border-0 outline-none text-base sm:text-[12.5px] text-slate-900 dark:text-slate-100 placeholder:text-slate-400" placeholder="Quick filter current results...">
                </div>
                <div class="flex items-center justify-between gap-3">
                    <select id="mobileSort" class="md:hidden flex-1 px-2.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-base text-slate-900 dark:text-slate-100" aria-label="Sort applications"></select>
                    <div class="text-xs text-slate-600 dark:text-slate-400 whitespace-nowrap">
                        Showing <span id="rangeLabel">0</span> of <span id="totalLabel">0</span> results
                    </div>
                </div>
            </div>

            <!-- Desktop / tablet / print: table -->
            <div class="hidden md:block print:block overflow-x-auto border border-slate-200 dark:border-slate-700 rounded-[10px] print:overflow-visible print:border-0">
                <table class="w-full border-collapse text-[11.5px] print:text-[8.5px]" id="dataTable">
                    <thead class="print:table-header-group">
                        <tr id="tableHeadRow"></tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr>
                            <td colspan="9" class="text-center text-slate-400 italic p-7">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile: card list -->
            <div id="mobileList" class="md:hidden print:hidden flex flex-col gap-2.5"></div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-3 text-xs text-slate-600 dark:text-slate-400 no-print print:hidden">
                <div class="flex items-center gap-2">
                    Rows per page:
                    <select id="pageSizeSelect" class="border border-slate-200 dark:border-slate-600 rounded-md px-2 py-1 text-xs bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="flex items-center justify-between sm:justify-end gap-1.5">
                    <button id="prevPageBtn" onclick="changePage(-1)" class="px-3.5 py-2 sm:px-2.5 sm:py-1 border border-slate-200 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 text-xs cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>
                    <span id="pageIndicator" class="px-1.5 text-center">Page 1</span>
                    <button id="nextPageBtn" onclick="changePage(1)" class="px-3.5 py-2 sm:px-2.5 sm:py-1 border border-slate-200 dark:border-slate-600 rounded-md bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 text-xs cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <p class="mt-4 text-center text-[10.5px] text-slate-400">DMW Regional Office IV-A — CALABARZON &nbsp;•&nbsp; My Evaluation Reports &nbsp;•&nbsp; For official use only</p>
    </div>

    <script>
        /* ───────── Custom searchable dropdown (replaces native selects, Tailwind-styled) ───────── */
        const ENHANCED_CLOSERS = [];

        function enhanceReportSelect(select, opts) {
            opts = opts || {};
            const emptyValue = opts.emptyValue !== undefined ? opts.emptyValue : '';
            const includeEmpty = opts.includeEmpty !== false;
            const searchable = opts.searchable !== false && select.options.length > 8;
            const inline = !!opts.inline;
            const placeholderOpt = select.querySelector(`option[value="${emptyValue}"]`);
            const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

            const wrapper = document.createElement('div');
            wrapper.className = inline ? 'relative inline-flex align-middle' : 'relative';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.style.display = 'none';
            select.tabIndex = -1;

            const TRIGGER_CLS = inline ?
                'px-2 py-1 border rounded-md text-xs bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 inline-flex items-center gap-1.5 cursor-pointer transition-colors' :
                'w-full px-2.5 py-2 border rounded-lg text-base sm:text-[12.5px] bg-slate-50 dark:bg-slate-700 flex items-center justify-between gap-2 cursor-pointer transition-colors text-left';
            const TRIGGER_IDLE = ' border-slate-200 dark:border-slate-600';
            const TRIGGER_OPEN = ' border-blue-400 ring-2 ring-blue-400/30';

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = TRIGGER_CLS + TRIGGER_IDLE;
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.innerHTML = '<span class="rs-label overflow-hidden text-ellipsis whitespace-nowrap"></span>' +
                '<i class="fas fa-chevron-down text-[9px] text-slate-400 transition-transform shrink-0"></i>';
            wrapper.appendChild(trigger);

            const panel = document.createElement('div');
            panel.setAttribute('role', 'listbox');
            panel.className = 'hidden absolute left-0 z-[80] overflow-hidden bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-[10px] shadow-xl ' +
                (inline ? 'bottom-full mb-1.5 min-w-[110px]' : 'top-full mt-1.5 right-0');
            wrapper.appendChild(panel);

            let searchInput = null;
            if (searchable) {
                const searchWrap = document.createElement('div');
                searchWrap.className = 'p-2 border-b border-slate-200 dark:border-slate-700';
                searchWrap.innerHTML = '<div class="relative">' +
                    '<i class="fas fa-magnifying-glass absolute left-2.5 top-1/2 -translate-y-1/2 text-[10px] text-slate-400"></i>' +
                    '<input type="text" placeholder="Search…" class="w-full py-2 pl-7 pr-2 border border-slate-200 dark:border-slate-600 rounded-[7px] text-base sm:text-[11.5px] outline-none bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:border-blue-400">' +
                    '</div>';
                panel.appendChild(searchWrap);
                searchInput = searchWrap.querySelector('input');
            }

            const list = document.createElement('div');
            list.className = 'max-h-60 overflow-y-auto py-1';
            panel.appendChild(list);

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.className = 'flex items-center justify-between gap-2 px-3 py-2.5 sm:py-2 text-[12.5px] cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-700';
                row.dataset.value = optionEl.value;
                const label = document.createElement('span');
                label.className = 'overflow-hidden text-ellipsis whitespace-nowrap';
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

            function renderList(filterText) {
                list.innerHTML = '';
                const filter = (filterText || '').trim().toLowerCase();
                let any = false;
                Array.from(select.options).forEach(opt => {
                    if (opt.value === emptyValue && !includeEmpty) return;
                    if (filter && !opt.textContent.toLowerCase().includes(filter)) return;
                    any = true;
                    list.appendChild(buildRow(opt));
                });
                if (!any) {
                    const empty = document.createElement('div');
                    empty.className = 'px-3 py-4 text-center text-[11.5px] text-slate-400';
                    empty.textContent = 'No matches found.';
                    list.appendChild(empty);
                }
                markSelected();
            }

            const SEL_ON = ['bg-blue-50', 'dark:bg-blue-500/20', 'text-blue-700', 'dark:text-blue-300', 'font-bold'];
            const SEL_OFF = ['text-slate-900', 'dark:text-slate-100', 'font-normal'];

            function markSelected() {
                list.querySelectorAll('[role="option"]').forEach(row => {
                    const selected = row.dataset.value === select.value;
                    row.classList.remove(...(selected ? SEL_OFF : SEL_ON));
                    row.classList.add(...(selected ? SEL_ON : SEL_OFF));
                    const check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check text-[10px] text-blue-500 shrink-0';
                        row.appendChild(icon);
                    } else if (!selected && check) {
                        check.remove();
                    }
                });
            }

            function refresh() {
                const opt = select.options[select.selectedIndex];
                const label = trigger.querySelector('.rs-label');
                if (select.value === emptyValue || !opt) {
                    label.textContent = placeholder;
                    label.className = 'rs-label overflow-hidden text-ellipsis whitespace-nowrap text-slate-600 dark:text-slate-400 font-normal';
                } else {
                    label.textContent = opt.textContent;
                    label.className = 'rs-label overflow-hidden text-ellipsis whitespace-nowrap text-slate-900 dark:text-slate-100 font-semibold';
                }
                markSelected();
            }

            function openPanel() {
                ENHANCED_CLOSERS.forEach(fn => {
                    if (fn !== closePanel) fn();
                });
                renderList('');
                panel.classList.remove('hidden');
                trigger.className = TRIGGER_CLS + TRIGGER_OPEN;
                trigger.setAttribute('aria-expanded', 'true');
                trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
                if (searchInput) {
                    searchInput.value = '';
                    setTimeout(() => searchInput.focus(), 10);
                }
            }

            function closePanel() {
                panel.classList.add('hidden');
                trigger.className = TRIGGER_CLS + TRIGGER_IDLE;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
            }
            ENHANCED_CLOSERS.push(closePanel);

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
            if (searchInput) {
                searchInput.addEventListener('input', () => renderList(searchInput.value));
                searchInput.addEventListener('click', (e) => e.stopPropagation());
            }
            document.addEventListener('click', closePanel);

            refresh();
            return {
                refresh,
                closePanel
            };
        }

        enhanceReportSelect(document.getElementById('datePreset'), {
            searchable: false
        });
        enhanceReportSelect(document.querySelector('select[name="rsf_id"]'), {
            emptyValue: '0',
            searchable: true
        });
        enhanceReportSelect(document.querySelector('select[name="country"]'), {
            searchable: true
        });
        enhanceReportSelect(document.querySelector('select[name="status"]'), {
            searchable: false
        });
        enhanceReportSelect(document.querySelector('select[name="employer"]'), {
            searchable: true
        });
        enhanceReportSelect(document.getElementById('pageSizeSelect'), {
            searchable: false,
            inline: true
        });

        /* Mobile: collapsible filter panel */
        (function() {
            const btn = document.getElementById('filterToggle');
            const body = document.getElementById('filterBody');
            const icon = document.getElementById('filterToggleIcon');
            const txt = document.getElementById('filterToggleText');
            btn.addEventListener('click', () => {
                const nowHidden = body.classList.toggle('hidden');
                icon.classList.toggle('rotate-180', !nowHidden);
                txt.textContent = nowHidden ? 'Show filters' : 'Hide filters';
                btn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
            });
        })();
    </script>
    <script>
        /* DATA FROM SERVER  */
        const REPORT_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const EXPORT_COLUMNS = <?= json_encode($exportColumns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        const TABLE_COLUMNS = <?= json_encode($tableColumns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        const REPORT_META = <?= json_encode([
                                'filters'     => $filterSummaryText,
                                'generatedAt' => $generatedAt,
                                'preparedBy'  => $evaluator_name,
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        const DMW_LOGO_URL = '../pictures/dmw_logo.png';

        const STATUS_LABELS = {
            pending: 'Pending',
            under_evaluation: 'Under Evaluation',
            for_compliance: 'For Compliance',
            resubmitted: 'Resubmitted',
            approved: 'Approved',
            disapproved: 'Disapproved'
        };

        const STATUS_COLORS = {
            pending: [180, 83, 9],
            under_evaluation: [55, 48, 163],
            for_compliance: [180, 83, 9],
            resubmitted: [157, 23, 77],
            approved: [4, 120, 87],
            disapproved: [185, 28, 28]
        };

        // Tailwind badge styles per status (UI only)
        const BADGE_BASE = 'inline-block px-2 py-0.5 rounded-full text-[10px] font-bold whitespace-nowrap ';
        const BADGE_CLS = {
            approved: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400',
            disapproved: 'bg-red-100 text-red-700 dark:bg-red-600/20 dark:text-red-400',
            pending: 'bg-amber-100 text-amber-700 dark:bg-amber-600/20 dark:text-amber-400',
            other: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-300'
        };

        const PDF_NAVY = [11, 31, 75];
        const PDF_TEXT1 = [31, 41, 55];
        const PDF_TEXT2 = [75, 85, 99];
        const PDF_TEXT3 = [156, 163, 175];
        const PDF_BORDER = [229, 231, 235];
        const PDF_LBL_BG = [248, 250, 252];

        function sectionTitleBar(doc, x, y, width, text, opts = {}) {
            const h = opts.height || 20;
            doc.setFillColor(...PDF_NAVY);
            doc.rect(x, y, width, h, 'F');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(opts.fontSize || 10.5);
            doc.setTextColor(255, 255, 255);
            doc.text(text.toUpperCase(), x + 10, y + h / 2 + 3.5);
            return y + h;
        }

        function nz(v, fallback = '—') {
            return (v === null || v === undefined || v === '') ? fallback : v;
        }

        const chartInstances = {};
        let currentRows = REPORT_ROWS.slice();
        let sortKey = 'submitted_at';
        let sortDir = 'desc';
        let page = 1;
        let pageSize = 25;

        const TH_CLS = 'bg-slate-50 dark:bg-slate-700 text-left px-2.5 py-2.5 font-bold text-[10.5px] uppercase tracking-wide text-slate-600 dark:text-slate-300 border-b-2 border-slate-200 dark:border-slate-600 whitespace-nowrap cursor-pointer select-none hover:text-blue-700 dark:hover:text-blue-400';
        const TD_CLS = 'px-2.5 py-2 border-b border-slate-200 dark:border-slate-700 text-slate-900 dark:text-slate-100 whitespace-nowrap';
        const TR_CLS = 'cursor-pointer hover:bg-blue-50 dark:hover:bg-slate-700 even:bg-slate-50/70 dark:even:bg-slate-800/70 print:break-inside-avoid';

        function buildHead() {
            const tr = document.getElementById('tableHeadRow');
            tr.innerHTML = '';
            TABLE_COLUMNS.forEach(col => {
                const th = document.createElement('th');
                th.className = TH_CLS;
                th.dataset.key = col.key;
                const span = document.createElement('span');
                span.textContent = col.label;
                th.appendChild(span);
                const ic = document.createElement('i');
                ic.className = 'fas fa-sort sort-ic ml-1 text-[9px] opacity-50';
                th.appendChild(ic);
                th.addEventListener('click', () => {
                    if (sortKey === col.key) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortKey = col.key;
                        sortDir = 'asc';
                    }
                    page = 1;
                    renderTable();
                });
                tr.appendChild(th);
            });
            const thAction = document.createElement('th');
            thAction.className = TH_CLS.replace('cursor-pointer', 'cursor-default');
            thAction.textContent = 'View';
            tr.appendChild(thAction);
        }

        /* Mobile sort control (cards have no clickable headers) */
        const MOBILE_SORTS = [
            ['submitted_at|desc', 'Newest first'],
            ['submitted_at|asc', 'Oldest first'],
            ['applicant_name|asc', 'Applicant (A–Z)'],
            ['status_label|asc', 'Status (A–Z)'],
            ['position_title|asc', 'Position (A–Z)'],
            ['worksite_country|asc', 'Country (A–Z)']
        ];
        (function buildMobileSort() {
            const sel = document.getElementById('mobileSort');
            MOBILE_SORTS.forEach(([v, l]) => {
                const o = document.createElement('option');
                o.value = v;
                o.textContent = 'Sort: ' + l;
                sel.appendChild(o);
            });
            sel.addEventListener('change', () => {
                const [k, d] = sel.value.split('|');
                sortKey = k;
                sortDir = d;
                page = 1;
                renderTable();
            });
        })();

        function applyQuickFilter() {
            const q = document.getElementById('quickFilter').value.trim().toLowerCase();
            if (!q) {
                currentRows = REPORT_ROWS.slice();
                return;
            }
            currentRows = REPORT_ROWS.filter(r => {
                return String(r.application_id).includes(q) ||
                    (r.applicant_name || '').toLowerCase().includes(q) ||
                    (r.applicant_email || '').toLowerCase().includes(q) ||
                    (r.position_title || '').toLowerCase().includes(q) ||
                    (r.employer_name || '').toLowerCase().includes(q) ||
                    (r.worksite_country || '').toLowerCase().includes(q) ||
                    (r.status_label || '').toLowerCase().includes(q) ||
                    (r.my_latest_decision_label || '').toLowerCase().includes(q);
            });
        }

        function sortRows(rows) {
            const sorted = rows.slice().sort((a, b) => {
                let va = a[sortKey],
                    vb = b[sortKey];
                if (va === null || va === undefined) va = '';
                if (vb === null || vb === undefined) vb = '';
                if (!isNaN(va) && !isNaN(vb) && va !== '' && vb !== '') {
                    va = parseFloat(va);
                    vb = parseFloat(vb);
                } else {
                    va = String(va).toLowerCase();
                    vb = String(vb).toLowerCase();
                }
                if (va < vb) return sortDir === 'asc' ? -1 : 1;
                if (va > vb) return sortDir === 'asc' ? 1 : -1;
                return 0;
            });
            return sorted;
        }

        function badgeSpan(text, status) {
            const span = document.createElement('span');
            span.className = BADGE_BASE + (BADGE_CLS[status] || BADGE_CLS.other);
            span.textContent = text;
            return span;
        }

        function el(tag, cls, text) {
            const e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text !== undefined) e.textContent = text;
            return e;
        }

        function openApplication(r) {
            window.open('application-detail.php?id=' + encodeURIComponent(r.application_id), '_blank');
        }

        function mobileField(label, value, span) {
            const d = el('div', span ? 'col-span-2' : '');
            d.appendChild(el('dt', 'text-[9.5px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400', label));
            d.appendChild(el('dd', 'm-0 mt-0.5 text-xs text-slate-900 dark:text-slate-100 break-words', nz(value)));
            return d;
        }

        function buildMobileCard(r) {
            const card = el('div', 'rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-3.5 cursor-pointer active:bg-blue-50 dark:active:bg-slate-700');
            card.setAttribute('role', 'link');
            card.tabIndex = 0;
            card.addEventListener('click', () => openApplication(r));
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') openApplication(r);
            });

            const top = el('div', 'flex items-start justify-between gap-2');
            const left = el('div', 'min-w-0');
            left.appendChild(el('p', 'm-0 text-sm font-bold text-slate-900 dark:text-slate-100 break-words', nz(r.applicant_name)));
            left.appendChild(el('p', 'm-0 mt-0.5 text-[11px] text-slate-500 dark:text-slate-400 break-all', nz(r.applicant_email)));
            top.appendChild(left);
            top.appendChild(badgeSpan(r.status_label, r.status));
            card.appendChild(top);

            const dl = el('dl', 'grid grid-cols-2 gap-x-3 gap-y-2.5 m-0 mt-3 pt-3 border-t border-slate-200 dark:border-slate-700');
            dl.appendChild(mobileField('Position', r.position_title, true));
            dl.appendChild(mobileField('Employer', r.employer_name, true));
            dl.appendChild(mobileField('Country', r.worksite_country));
            dl.appendChild(mobileField('My Decision', r.my_latest_decision_label));
            dl.appendChild(mobileField('Application Date', r.submitted_at_fmt));
            dl.appendChild(mobileField('Application ID', '#' + r.application_id));
            card.appendChild(dl);

            const foot = el('div', 'flex items-center justify-end gap-1.5 mt-3 text-[11px] font-semibold text-blue-700 dark:text-blue-400');
            foot.innerHTML = 'View application <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>';
            card.appendChild(foot);
            return card;
        }

        function renderTable() {
            applyQuickFilter();
            const sorted = sortRows(currentRows);
            const total = sorted.length;
            const size = pageSize === 'all' ? Math.max(total, 1) : pageSize;
            const maxPage = Math.max(1, Math.ceil(total / size));
            if (page > maxPage) page = maxPage;
            const start = (page - 1) * size;
            const pageRows = sorted.slice(start, start + size);

            const tbody = document.getElementById('tableBody');
            const mlist = document.getElementById('mobileList');
            tbody.innerHTML = '';
            mlist.innerHTML = '';

            if (!pageRows.length) {
                const tr = document.createElement('tr');
                const td = el('td', 'text-center text-slate-400 italic p-7', 'No applications found for the selected filters.');
                td.colSpan = TABLE_COLUMNS.length + 1;
                tr.appendChild(td);
                tbody.appendChild(tr);
                mlist.appendChild(el('div', 'text-center text-slate-400 italic p-7 border border-dashed border-slate-300 dark:border-slate-600 rounded-xl text-sm', 'No applications found for the selected filters.'));
            } else {
                pageRows.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.className = TR_CLS;
                    tr.addEventListener('click', () => openApplication(r));
                    TABLE_COLUMNS.forEach(col => {
                        const td = document.createElement('td');
                        td.className = TD_CLS;
                        if (col.key === 'status_label') {
                            td.appendChild(badgeSpan(r.status_label, r.status));
                        } else {
                            td.textContent = (r[col.key] === null || r[col.key] === undefined || r[col.key] === '') ? '—' : r[col.key];
                        }
                        tr.appendChild(td);
                    });
                    const tdView = document.createElement('td');
                    tdView.className = TD_CLS;
                    tdView.innerHTML = '<i class="fas fa-arrow-up-right-from-square text-blue-500"></i>';
                    tr.appendChild(tdView);
                    tbody.appendChild(tr);

                    mlist.appendChild(buildMobileCard(r));
                });
            }

            document.getElementById('totalLabel').textContent = total;
            document.getElementById('rangeLabel').textContent = pageRows.length ? `${start + 1}-${start + pageRows.length}` : '0';
            document.getElementById('pageIndicator').textContent = `Page ${page} of ${maxPage}`;
            document.getElementById('prevPageBtn').disabled = page <= 1;
            document.getElementById('nextPageBtn').disabled = page >= maxPage;

            document.querySelectorAll('#tableHeadRow th').forEach(th => {
                const ic = th.querySelector('.sort-ic');
                if (!ic) return;
                if (th.dataset.key === sortKey) {
                    ic.className = 'fas sort-ic ml-1 text-[9px] opacity-100 ' + (sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                } else {
                    ic.className = 'fas fa-sort sort-ic ml-1 text-[9px] opacity-50';
                }
            });

            // Keep the mobile sort control in sync with column-header sorting
            const ms = document.getElementById('mobileSort');
            const cur = sortKey + '|' + sortDir;
            if (MOBILE_SORTS.some(s => s[0] === cur)) ms.value = cur;
        }

        function changePage(delta) {
            page += delta;
            renderTable();
        }

        document.getElementById('pageSizeSelect').addEventListener('change', (e) => {
            pageSize = e.target.value === 'all' ? 'all' : parseInt(e.target.value, 10);
            page = 1;
            renderTable();
        });
        document.getElementById('quickFilter').addEventListener('input', () => {
            page = 1;
            renderTable();
        });

        buildHead();
        renderTable();

        const datePreset = document.getElementById('datePreset');
        const customRange = document.getElementById('customRange');
        datePreset.addEventListener('change', () => {
            const isCustom = datePreset.value === 'custom';
            customRange.classList.toggle('hidden', !isCustom);
            customRange.classList.toggle('grid', isCustom);
            if (isCustom) validateDateRange();
        });

        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const fromWrap = document.getElementById('fromWrap');
        const toWrap = document.getElementById('toWrap');
        const rangeHint = document.getElementById('rangeHint');
        const filterForm = document.getElementById('filterForm');
        const TODAY = <?= json_encode($today_date) ?>;

        function validateDateRange() {
            fromWrap.classList.remove('dw-error');
            toWrap.classList.remove('dw-error');
            rangeHint.classList.remove('err');

            dateTo.max = TODAY;
            dateTo.min = dateFrom.value || '';
            dateFrom.max = (dateTo.value && dateTo.value < TODAY) ? dateTo.value : TODAY;

            const fromFuture = dateFrom.value && dateFrom.value > TODAY;
            const toFuture = dateTo.value && dateTo.value > TODAY;
            if (fromFuture || toFuture) {
                if (fromFuture) fromWrap.classList.add('dw-error');
                if (toFuture) toWrap.classList.add('dw-error');
                rangeHint.classList.add('err');
                rangeHint.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Future dates are not allowed. Choose today or an earlier date.';
                return false;
            }

            if (!dateFrom.value && !dateTo.value) {
                rangeHint.innerHTML = '<i class="fas fa-circle-info"></i> Select both a start and end date.';
                return true;
            }
            if (dateFrom.value && !dateTo.value) {
                rangeHint.innerHTML = '<i class="fas fa-circle-info"></i> Now pick an end date.';
                return true;
            }
            if (!dateFrom.value && dateTo.value) {
                rangeHint.innerHTML = '<i class="fas fa-circle-info"></i> Now pick a start date.';
                return true;
            }
            if (dateFrom.value > dateTo.value) {
                fromWrap.classList.add('dw-error');
                toWrap.classList.add('dw-error');
                rangeHint.classList.add('err');
                rangeHint.innerHTML = '<i class="fas fa-triangle-exclamation"></i> "From" date must be before or equal to "To" date.';
                return false;
            }

            const days = Math.round((new Date(dateTo.value) - new Date(dateFrom.value)) / 86400000) + 1;
            rangeHint.innerHTML = `<i class="fas fa-check-circle text-emerald-500"></i> ${days} day${days === 1 ? '' : 's'} selected (${dateFrom.value} → ${dateTo.value})`;
            return true;
        }

        ['change', 'input'].forEach(evt => {
            dateFrom.addEventListener(evt, validateDateRange);
            dateTo.addEventListener(evt, validateDateRange);
        });
        if (datePreset.value === 'custom') validateDateRange();

        filterForm.addEventListener('submit', (e) => {
            if (datePreset.value === 'custom' && !validateDateRange()) {
                e.preventDefault();
            }
        });

        function escapeCsv(val) {
            const s = String(val === null || val === undefined ? '' : val);
            if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
            return s;
        }

        function exportRowsFlat() {
            return currentRows.map(r => {
                const obj = {};
                EXPORT_COLUMNS.forEach(col => obj[col.label] = r[col.key] === null || r[col.key] === undefined ? '' : r[col.key]);
                return obj;
            });
        }

        function exportCSV() {
            const rows = exportRowsFlat();
            const headers = EXPORT_COLUMNS.map(c => c.label);
            const lines = [headers.map(escapeCsv).join(',')];
            rows.forEach(r => lines.push(headers.map(h => escapeCsv(r[h])).join(',')));
            const blob = new Blob([lines.join('\n')], {
                type: 'text/csv;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'My_Evaluation_Report_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(url);
        }

        /* ───────── Styled Excel export (ExcelJS) ───────── */
        const XL = {
            navy: 'FF0B1F4B',
            white: 'FFFFFFFF',
            zebra: 'FFF8FAFC',
            border: 'FFD1D5DB',
            grey: 'FF6B7280',
            text: 'FF1F2937',
            totalBg: 'FFE9EDF7'
        };
        // [background, text] per application status
        const XL_STATUS = {
            approved: ['FFD1FAE5', 'FF047857'],
            disapproved: ['FFFEE2E2', 'FFB91C1C'],
            pending: ['FFFEF3C7', 'FFB45309'],
            under_evaluation: ['FFE0E7FF', 'FF3730A3'],
            for_compliance: ['FFFFEDD5', 'FFC2410C'],
            resubmitted: ['FFFCE7F3', 'FF9D174D']
        };
        const XL_NUMERIC_KEYS = ['document_count', 'my_evaluation_count'];
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

        function xlImageRatio(dataUrl) {
            return new Promise(res => {
                const i = new Image();
                i.onload = () => res(i.naturalWidth / i.naturalHeight);
                i.onerror = () => res(1);
                i.src = dataUrl;
            });
        }

        function xlLetterhead(ws, wb, logo, N) {
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

        async function exportExcel() {
            const rows = currentRows; // respects the quick-filter box, same as CSV/PDF
            const cols = EXPORT_COLUMNS;
            const N = cols.length;
            const M = Math.min(N, 8); // title/meta span

            const wb = new ExcelJS.Workbook();
            wb.creator = REPORT_META.preparedBy;
            wb.company = 'DMW Regional Office IV-A';
            wb.created = new Date();

            let logo = null;
            const logoData = await loadImageAsDataURL(DMW_LOGO_URL);
            if (logoData) {
                logo = {
                    id: wb.addImage({
                        base64: logoData.split(',')[1],
                        extension: 'png'
                    }),
                    ratio: await xlImageRatio(logoData)
                };
            }

            /* ── Sheet 1: My Evaluation Report ── */
            const ws = wb.addWorksheet('My Evaluation Report', {
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

            xlLetterhead(ws, wb, logo, N);
            xlMergedLine(ws, 5, M, 'MY EVALUATION REPORT', {
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
                }, 30);

            const HR = 8;
            const hr = ws.getRow(HR);
            hr.height = 30;
            cols.forEach((col, i) => {
                const c = hr.getCell(i + 1);
                c.value = col.label;
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

            const widths = cols.map(c => String(c.label).length + 4);
            rows.forEach((r, idx) => {
                const row = ws.getRow(HR + 1 + idx);
                row.height = 20;
                cols.forEach((col, i) => {
                    const raw = r[col.key];
                    const c = row.getCell(i + 1);
                    let isNum = false;

                    if (col.key === 'profile_completion') {
                        c.value = (Number(raw) || 0) / 100;
                        c.numFmt = '0%';
                        isNum = true;
                    } else if (XL_NUMERIC_KEYS.includes(col.key)) {
                        c.value = Number(raw) || 0;
                        c.numFmt = '0';
                        isNum = true;
                    } else {
                        const txt = (raw === null || raw === undefined || raw === '') ? '—' : String(raw);
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

                    // Status / My Latest Decision badge colors
                    const badgeKey = col.key === 'status_label' ? r.status :
                        col.key === 'my_latest_decision_label' ? r.my_latest_decision :
                        null;
                    if (badgeKey && XL_STATUS[badgeKey]) {
                        const [bg, fg] = XL_STATUS[badgeKey];
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
                    // Profile completion: green ≥ 80%, amber ≥ 50%, red below
                    if (col.key === 'profile_completion') {
                        const v = c.value;
                        const [bg, fg] = v >= 0.8 ? XL_STATUS.approved : (v >= 0.5 ? XL_STATUS.pending : XL_STATUS.disapproved);
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
                c.value = 'No applications found for the selected filters.';
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
                horizontal: 'center'
            };
            ws.pageSetup.printArea = `A1:${ws.getColumn(N).letter}${lastRow}`;
            ws.pageSetup.printTitlesRow = `${HR}:${HR}`;

            /* ── Sheet 2: Summary (status counts with live formulas) ── */
            const ss = wb.addWorksheet('Summary', {
                properties: {
                    tabColor: {
                        argb: 'FF3B82F6'
                    }
                },
                views: [{
                    showGridLines: false
                }],
                pageSetup: {
                    orientation: 'portrait',
                    paperSize: 9,
                    fitToPage: true,
                    fitToWidth: 1,
                    fitToHeight: 0
                }
            });
            ss.getColumn(1).width = 30;
            ss.getColumn(2).width = 16;
            ss.getColumn(3).width = 16;
            xlLetterhead(ss, wb, logo, 3);
            xlMergedLine(ss, 5, 3, 'MY EVALUATION REPORT — STATUS SUMMARY', {
                name: 'Calibri',
                size: 14,
                bold: true,
                color: {
                    argb: XL.navy
                }
            }, 26);
            xlMergedLine(ss, 6, 3, `Filters: ${REPORT_META.filters}\nGenerated: ${REPORT_META.generatedAt}`, {
                name: 'Calibri',
                size: 9,
                italic: true,
                color: {
                    argb: XL.grey
                }
            }, 30);

            const sh = ss.getRow(8);
            sh.height = 24;
            ['Status', 'Applications', '% of Total'].forEach((h, i) => {
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

            const keys = Object.keys(STATUS_LABELS);
            const first = 9,
                last = first + keys.length - 1,
                totalRow = last + 1;
            keys.forEach((k, i) => {
                const rNo = first + i;
                const r = ss.getRow(rNo);
                r.height = 20;
                const count = rows.filter(x => x.status === k).length;
                const [bg, fg] = XL_STATUS[k] || ['FFF3F4F6', XL.text];

                const a = r.getCell(1);
                a.value = STATUS_LABELS[k];
                a.font = {
                    name: 'Calibri',
                    size: 10,
                    bold: true,
                    color: {
                        argb: fg
                    }
                };
                a.fill = xlFill(bg);
                const b = r.getCell(2);
                b.value = count;
                b.numFmt = '0';
                const p = r.getCell(3);
                p.value = {
                    formula: `IF($B$${totalRow}=0,0,B${rNo}/$B$${totalRow})`
                };
                p.numFmt = '0.0%';
                [a, b, p].forEach((c, j) => {
                    if (j > 0) c.font = {
                        name: 'Calibri',
                        size: 10,
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
                });
            });

            const t = ss.getRow(totalRow);
            t.height = 22;
            [
                ['TOTAL', null],
                [{
                    formula: `SUM(B${first}:B${last})`
                }, '0'],
                [{
                    formula: `SUM(C${first}:C${last})`
                }, '0.0%']
            ].forEach(([v, fmt], i) => {
                const c = t.getCell(i + 1);
                c.value = v;
                if (fmt) c.numFmt = fmt;
                c.font = {
                    name: 'Calibri',
                    size: 10,
                    bold: true,
                    color: {
                        argb: XL.navy
                    }
                };
                c.fill = xlFill(XL.totalBg);
                c.alignment = {
                    vertical: 'middle',
                    horizontal: i === 0 ? 'left' : 'center',
                    indent: i === 0 ? 1 : 0
                };
                c.border = {
                    top: {
                        style: 'medium',
                        color: {
                            argb: XL.navy
                        }
                    },
                    bottom: xlThin(),
                    left: xlThin(),
                    right: xlThin()
                };
            });

            /* ── Save ── */
            const buf = await wb.xlsx.writeBuffer();
            const blob = new Blob([buf], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'My_Evaluation_Report_' + new Date().toISOString().slice(0, 10) + '.xlsx';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        }

        /*  PDF EXPORT MENU TOGGLE  */
        function togglePdfMenu(e) {
            e.stopPropagation();
            const menu = document.getElementById('pdfExportMenu');
            const btn = document.getElementById('btnPdf');
            const willOpen = menu.classList.contains('hidden');
            menu.classList.toggle('hidden', !willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        function closePdfMenu() {
            const menu = document.getElementById('pdfExportMenu');
            const btn = document.getElementById('btnPdf');
            menu.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
        }

        document.addEventListener('click', (e) => {
            const wrap = document.querySelector('.pdf-export-wrap');
            if (wrap && !wrap.contains(e.target)) closePdfMenu();
        });

        async function loadImageAsDataURL(url) {
            try {
                const response = await fetch(url, {
                    mode: 'cors'
                });
                if (!response.ok) throw new Error('Logo fetch failed: ' + response.status);
                const blob = await response.blob();
                const dataUrl = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.onerror = () => reject(new Error('Could not read logo blob'));
                    reader.readAsDataURL(blob);
                });
                return await downscaleLogo(dataUrl, 160);
            } catch (err) {
                console.warn('DMW logo could not be embedded in the PDF.', err);
                return null;
            }
        }

        // Logo is drawn ~32pt tall, so ~160px is plenty. Keeps per-page stamping cheap.
        function downscaleLogo(dataUrl, targetH) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    const scale = Math.min(1, targetH / img.naturalHeight);
                    const c = document.createElement('canvas');
                    c.width = Math.round(img.naturalWidth * scale);
                    c.height = Math.round(img.naturalHeight * scale);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    resolve(c.toDataURL('image/png'));
                };
                img.onerror = () => resolve(dataUrl);
                img.src = dataUrl;
            });
        }

        const LETTERHEAD_BOTTOM = 66; // y where the navy line sits
        const CONTENT_TOP_NEXT = 78; // where content starts on pages 2+

        function drawLetterhead(doc, logoData) {
            const pageWidth = doc.internal.pageSize.getWidth();
            const marginX = 30;

            if (logoData) {
                try {
                    doc.addImage(logoData, 'PNG', marginX, 14, 32, 32, 'dmwLogo', 'FAST');
                } catch (e) {
                    console.warn('Failed to draw logo on PDF:', e);
                }
            }
            const textX = logoData ? marginX + 42 : marginX;

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7.8);
            doc.setTextColor(...PDF_TEXT2);
            doc.text('Republic of the Philippines', textX, 21);

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(12.5);
            doc.setTextColor(...PDF_NAVY);
            doc.text('DEPARTMENT OF MIGRANT WORKERS', textX, 32);

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7.6);
            doc.setTextColor(...PDF_TEXT2);
            doc.text('Regional Office IV-A (CALABARZON)', textX, 41);
            doc.text('Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027', textX, 49);
            doc.text('Tel: 0962 671 9976  |  Email: calamba@dmw.gov.ph  |  dmw.gov.ph', textX, 57);

            doc.setDrawColor(...PDF_NAVY);
            doc.setLineWidth(1.4);
            doc.line(marginX, LETTERHEAD_BOTTOM, pageWidth - marginX, LETTERHEAD_BOTTOM);
        }

        // Stamp the same letterhead on every page after the first
        function stampLetterhead(doc, logoData) {
            const total = doc.internal.getNumberOfPages();
            for (let i = 2; i <= total; i++) {
                doc.setPage(i);
                drawLetterhead(doc, logoData);
            }
        }

        function drawReportHeader(doc, logoData, totalRecords, generatedAt, filterSummaryText,
            titleText = 'MY EVALUATION REPORT',
            subtitleText = 'Applications and evaluations linked to this evaluator only — DMW Application Management System') {
            const pageWidth = doc.internal.pageSize.getWidth();
            const marginX = 30;

            drawLetterhead(doc, logoData);

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(14);
            doc.setTextColor(...PDF_NAVY);
            doc.text(titleText, pageWidth / 2, 84, {
                align: 'center'
            });

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8.5);
            doc.setTextColor(...PDF_TEXT2);
            doc.text(subtitleText, pageWidth / 2, 94, {
                align: 'center'
            });

            const metaY = 108;
            doc.setDrawColor(...PDF_BORDER);
            doc.setLineWidth(0.75);
            doc.line(marginX, metaY + 8, pageWidth - marginX, metaY + 8);

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7.8);
            doc.setTextColor(...PDF_TEXT2);
            doc.text(`Generated: ${generatedAt}`, marginX, metaY);
            doc.text(`Total Records: ${totalRecords}`, pageWidth / 2, metaY, {
                align: 'center'
            });
            doc.text(`Filters: ${filterSummaryText}`, pageWidth - marginX, metaY, {
                align: 'right',
                maxWidth: 260
            });

            return metaY + 22;
        }

        function drawIntro(doc, startY, totalRecords, customText = null) {
            const pageWidth = doc.internal.pageSize.getWidth();
            const marginX = 30;
            const text = customText || `This report presents the applications and evaluations linked to this evaluator, currently matching the applied filters. It includes ${totalRecords} application(s) drawn from the DMW Application Management System, summarizing status distribution, visual analytics, and complete per-applicant detail for review or compliance purposes.`;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8.3);
            doc.setTextColor(...PDF_TEXT2);
            const lines = doc.splitTextToSize(text, pageWidth - marginX * 2);
            doc.text(lines, marginX, startY);
            return startY + lines.length * 10 + 10;
        }

        function drawSummaryCards(doc, statusCounts, total, startY) {
            const pageWidth = doc.internal.pageSize.getWidth();
            const marginX = 30;
            const cardH = 46;

            const cards = [{
                    label: 'Total Applicants',
                    value: total
                },
                ...Object.keys(STATUS_LABELS).map(k => ({
                    label: STATUS_LABELS[k],
                    value: statusCounts[k] || 0
                }))
            ];

            const gap = 6;
            const cardW = (pageWidth - marginX * 2 - gap * (cards.length - 1)) / cards.length;

            cards.forEach((c, i) => {
                const x = marginX + i * (cardW + gap);
                doc.setFillColor(...PDF_LBL_BG);
                doc.setDrawColor(...PDF_BORDER);
                doc.setLineWidth(0.75);
                doc.rect(x, startY, cardW, cardH, 'FD');

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(13);
                doc.setTextColor(...PDF_NAVY);
                doc.text(String(c.value), x + cardW / 2, startY + 22, {
                    align: 'center'
                });

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(6.3);
                doc.setTextColor(...PDF_TEXT2);
                doc.text(c.label.toUpperCase(), x + cardW / 2, startY + 35, {
                    align: 'center',
                    maxWidth: cardW - 8
                });
            });

            return startY + cardH + 20;
        }

        function drawSummaryTable(doc, rows, startY) {
            const marginX = 30;
            const pageWidth = doc.internal.pageSize.getWidth();

            const afterTitle = sectionTitleBar(doc, marginX, startY, pageWidth - marginX * 2, 'Applicant Summary');
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(7.5);
            doc.setTextColor(...PDF_TEXT3);
            doc.text('One row per application, matching the applied filters.', marginX, afterTitle + 10);

            const body = rows.map(r => [
                nz(r.applicant_name), nz(r.position_title), nz(r.worksite_country),
                nz(r.employer_name), nz(r.status_label), nz(r.my_latest_decision_label), nz(r.my_latest_decision_at_fmt)
            ]);

            doc.autoTable({
                head: [
                    ['Applicant', 'Position', 'Country', 'Employer', 'Status', 'My Decision', 'Decision Date']
                ],
                body,
                startY: afterTitle + 16,
                theme: 'grid',
                margin: {
                    left: marginX,
                    right: marginX,
                    top: CONTENT_TOP_NEXT
                },
                styles: {
                    font: 'helvetica',
                    fontSize: 7.5,
                    cellPadding: 5,
                    overflow: 'linebreak',
                    valign: 'middle',
                    lineColor: PDF_BORDER,
                    lineWidth: 0.5,
                    textColor: PDF_TEXT1
                },
                headStyles: {
                    fillColor: PDF_LBL_BG,
                    textColor: PDF_TEXT2,
                    fontStyle: 'bold',
                    fontSize: 7,
                    lineColor: PDF_BORDER,
                    lineWidth: 0.5
                },
                alternateRowStyles: {
                    fillColor: [251, 252, 254]
                },
                columnStyles: {
                    0: {
                        cellWidth: 120
                    },
                    1: {
                        cellWidth: 130
                    },
                    2: {
                        cellWidth: 80
                    },
                    3: {
                        cellWidth: 130
                    },
                    4: {
                        cellWidth: 82
                    },
                    5: {
                        cellWidth: 100
                    },
                    6: {
                        cellWidth: 90
                    }
                },
                didParseCell: (data) => {
                    if (data.section === 'body' && data.column.index === 4) {
                        const statusKey = rows[data.row.index].status;
                        data.cell.styles.textColor = STATUS_COLORS[statusKey] || PDF_TEXT2;
                        data.cell.styles.fontStyle = 'bold';
                    }
                },
            });
        }

        function buildApplicantSectionRows(r) {
            const statusColor = STATUS_COLORS[r.status] || PDF_TEXT2;
            const rows = [];

            rows.push([{
                content: `APPLICATION #${r.application_id}   —   ${nz(r.applicant_name)}`,
                colSpan: 4,
                styles: {
                    fillColor: PDF_NAVY,
                    textColor: 255,
                    fontStyle: 'bold',
                    fontSize: 9,
                    cellPadding: {
                        top: 6,
                        bottom: 6,
                        left: 10,
                        right: 8
                    }
                }
            }]);

            const addSection = (title, pairs) => {
                rows.push([{
                    content: title.toUpperCase(),
                    colSpan: 4,
                    styles: {
                        fillColor: PDF_LBL_BG,
                        textColor: PDF_TEXT2,
                        fontStyle: 'bold',
                        fontSize: 7.2,
                        cellPadding: {
                            top: 5,
                            bottom: 5,
                            left: 10,
                            right: 4
                        }
                    }
                }]);
                const label = (p) => p ? {
                    content: p.label,
                    styles: {
                        fontStyle: 'bold',
                        textColor: PDF_TEXT2,
                        fontSize: 7.6
                    }
                } : {
                    content: ''
                };
                const value = (p) => p ? {
                    content: nz(p.value),
                    styles: Object.assign({
                        textColor: PDF_TEXT1,
                        fontSize: 7.8
                    }, p.styles || {})
                } : {
                    content: ''
                };
                for (let i = 0; i < pairs.length; i += 2) {
                    rows.push([label(pairs[i]), value(pairs[i]), label(pairs[i + 1]), value(pairs[i + 1])]);
                }
            };

            addSection('APPLICANT INFORMATION', [{
                    label: 'Name',
                    value: r.applicant_name
                },
                {
                    label: 'Email',
                    value: r.applicant_email
                },
                {
                    label: 'Phone',
                    value: r.applicant_phone
                },
                {
                    label: 'Application Date',
                    value: r.submitted_at_fmt
                }
            ]);

            addSection('EMPLOYMENT', [{
                    label: 'Position',
                    value: r.position_title
                },
                {
                    label: 'Category',
                    value: r.category_name
                },
                {
                    label: 'Subcategory',
                    value: r.subcategory_name
                },
                {
                    label: 'Employer',
                    value: r.employer_name
                },
                {
                    label: 'Worksite Country',
                    value: r.worksite_country
                },
                {
                    label: 'Classification',
                    value: r.classification
                }
            ]);

            addSection('APPLICATION STATUS', [{
                    label: 'Status',
                    value: r.status_label,
                    styles: {
                        textColor: statusColor,
                        fontStyle: 'bold'
                    }
                },
                {
                    label: 'Handling Party',
                    value: r.handling_party_label
                },
                {
                    label: 'Currently Assigned To',
                    value: r.current_evaluator_name
                },
                {
                    label: 'My Latest Decision',
                    value: r.my_latest_decision_label
                },
                {
                    label: 'My Decision Date',
                    value: r.my_latest_decision_at_fmt
                }
            ]);

            addSection('PROFILE', [{
                    label: 'Documents',
                    value: (r.document_count ?? 0) + ' file(s)'
                },
                {
                    label: 'Profile Completion',
                    value: (r.profile_completion ?? 0) + '%'
                },
                {
                    label: 'Education',
                    value: r.educational_attainment
                },
                {
                    label: 'Degree / Course',
                    value: r.degree_course
                },
                {
                    label: 'Work Experience',
                    value: r.work_experience_display
                },
                {
                    label: 'Preferred Country',
                    value: r.preferred_country
                },
                {
                    label: 'Preferred Position',
                    value: r.preferred_position
                },
                {
                    label: 'Last Updated',
                    value: r.updated_at_fmt
                }
            ]);

            rows.push([{
                content: '',
                colSpan: 4,
                styles: {
                    minCellHeight: 6,
                    lineWidth: 0
                }
            }]);
            return rows;
        }

        function drawDetailedRecords(doc, rows) {
            const marginX = 30;
            const pageWidth = doc.internal.pageSize.getWidth();

            const afterTitle = sectionTitleBar(doc, marginX, CONTENT_TOP_NEXT, pageWidth - marginX * 2, 'Detailed Applicant Records');
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(7.8);
            doc.setTextColor(...PDF_TEXT3);
            doc.text(`${rows.length} application(s) — one record per section`, marginX, afterTitle + 10);

            if (!rows.length) {
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(9);
                doc.setTextColor(...PDF_TEXT2);
                doc.text('No applications matched the selected filters.', marginX, afterTitle + 30);
                return;
            }

            let body = [];
            rows.forEach(r => {
                body = body.concat(buildApplicantSectionRows(r));
            });

            doc.autoTable({
                body,
                startY: afterTitle + 18,
                theme: 'grid',
                margin: {
                    left: marginX,
                    right: marginX,
                    top: CONTENT_TOP_NEXT,
                    bottom: 40
                },
                styles: {
                    font: 'helvetica',
                    fontSize: 7.6,
                    cellPadding: 4.5,
                    lineColor: PDF_BORDER,
                    lineWidth: 0.5,
                    overflow: 'linebreak',
                    valign: 'middle'
                },
                columnStyles: {
                    0: {
                        cellWidth: 80
                    },
                    1: {
                        cellWidth: 190
                    },
                    2: {
                        cellWidth: 80
                    },
                    3: {
                        cellWidth: 185
                    }
                },
                rowPageBreak: 'avoid'
            });
        }

        function drawFooter(doc, pageNum, totalPages, generatedAt) {
            const pageWidth = doc.internal.pageSize.getWidth();
            const pageHeight = doc.internal.pageSize.getHeight();
            const marginX = 30;
            const footY = pageHeight - 34;

            doc.setDrawColor(...PDF_TEXT3);
            doc.setLineWidth(0.75);
            doc.line(marginX, footY, pageWidth - marginX, footY);

            const leftW = (pageWidth - marginX * 2) * 0.62;
            const rightX = marginX + leftW + 14;

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.6);
            doc.setTextColor(...PDF_TEXT2);
            const disclaimer = 'This is an official system-generated report from the DMW Application Management System — Regional Office IV-A (CALABARZON). Information is confidential and intended solely for internal evaluation and official reporting.';
            doc.text(doc.splitTextToSize(disclaimer, leftW), marginX, footY + 10);

            doc.setDrawColor(...PDF_BORDER);
            doc.setLineWidth(0.5);
            doc.line(rightX - 7, footY + 4, rightX - 7, pageHeight - 12);

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.6);
            doc.setTextColor(...PDF_TEXT2);
            doc.text('Department of Migrant Workers - RO IV-A (CALABARZON)', rightX, footY + 10);
            doc.text('System-Generated Document • For Official Use Only', rightX, footY + 18);
            doc.text(`Generated: ${generatedAt}`, rightX, footY + 26);

            doc.setFontSize(6.6);
            doc.setTextColor(...PDF_TEXT3);
            doc.text(`Page ${pageNum} of ${totalPages}`, pageWidth - marginX, pageHeight - 12, {
                align: 'right'
            });
        }

        const PDF_CHART_CONFIG = [{
                id: 'chartTrend',
                title: 'My Applications Over Time'
            },
            {
                id: 'chartStatus',
                title: 'Status Distribution'
            },
            {
                id: 'chartCountry',
                title: 'By Country'
            },
            {
                id: 'chartPosition',
                title: 'By Position'
            },
            {
                id: 'chartEmployer',
                title: 'By Employer'
            },
            {
                id: 'chartApprovedVs',
                title: 'My Decisions: Approved vs. Disapproved'
            },
        ];

        function drawChartsPage(doc, startY = 26) {
            const pageWidth = doc.internal.pageSize.getWidth();
            const pageHeight = doc.internal.pageSize.getHeight();
            const marginX = 30;
            const firstY = startY;
            const nextY = CONTENT_TOP_NEXT;
            const bottomLimit = pageHeight - 34;

            const afterTitle = sectionTitleBar(doc, marginX, firstY, pageWidth - marginX * 2, 'Visual Analytics');
            const cols = 2;
            const gap = 14;
            const cardW = (pageWidth - marginX * 2 - gap * (cols - 1)) / cols;
            const cardH = 185;
            const titleH = 18;

            let cursorY = afterTitle + 14;
            let col = 0;

            PDF_CHART_CONFIG.forEach((cfg) => {
                const chart = chartInstances[cfg.id];
                if (!chart) return;

                const x = marginX + col * (cardW + gap);

                if (cursorY + titleH + cardH > bottomLimit) {
                    doc.addPage('a4', 'l');
                    cursorY = nextY;
                    col = 0;
                }

                const cardY = cursorY;

                doc.setDrawColor(...PDF_BORDER);
                doc.setLineWidth(0.75);
                doc.rect(x, cardY, cardW, titleH + cardH);

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8);
                doc.setTextColor(...PDF_NAVY);
                doc.text(cfg.title.toUpperCase(), x + 8, cardY + 13);

                doc.setDrawColor(...PDF_BORDER);
                doc.setLineWidth(0.5);
                doc.line(x + 8, cardY + 17, x + cardW - 8, cardY + 17);

                try {
                    const canvasEl = chart.canvas;
                    const nativeW = canvasEl.width;
                    const nativeH = canvasEl.height;
                    const imgData = chart.toBase64Image('image/png', 1.0);

                    const innerPad = 8;
                    const maxW = cardW - innerPad * 2;
                    const maxH = cardH - innerPad;

                    const scale = Math.min(maxW / nativeW, maxH / nativeH);
                    const drawW = nativeW * scale;
                    const drawH = nativeH * scale;
                    const drawX = x + innerPad + (maxW - drawW) / 2;
                    const drawY = cardY + titleH + (maxH - drawH) / 2;

                    doc.addImage(imgData, 'PNG', drawX, drawY, drawW, drawH, undefined, 'FAST');
                } catch (e) {
                    doc.setFont('helvetica', 'italic');
                    doc.setFontSize(8);
                    doc.setTextColor(...PDF_TEXT3);
                    doc.text('Chart unavailable', x + 8, cardY + titleH + 20);
                }

                col++;
                if (col >= cols) {
                    col = 0;
                    cursorY += titleH + cardH + gap;
                }
            });
        }

        /*  PDF REPORT: MAIN EXPORT — mode: 'details' | 'charts' | 'both'  */
        async function exportPDF(mode) {
            closePdfMenu();
            const btn = document.getElementById('btnPdf');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            try {
                const {
                    jsPDF
                } = window.jspdf;
                const generatedAt = '<?= htmlspecialchars($generatedAt) ?>';
                const filterSummary = <?= json_encode($filterSummaryText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
                const rows = currentRows; // respects the quick-filter box, same data used by CSV/Excel export
                const dateStr = new Date().toISOString().slice(0, 10);

                const statusCounts = {};
                rows.forEach(r => {
                    statusCounts[r.status] = (statusCounts[r.status] || 0) + 1;
                });

                const logoData = await loadImageAsDataURL(DMW_LOGO_URL);

                /*  FILE 1: MY APPLICATIONS REPORT  */
                if (mode === 'details' || mode === 'both') {
                    const doc = new jsPDF('l', 'pt', 'a4');
                    let cursorY = drawReportHeader(doc, logoData, rows.length, generatedAt, filterSummary);
                    cursorY = drawIntro(doc, cursorY, rows.length);
                    cursorY = drawSummaryCards(doc, statusCounts, rows.length, cursorY);
                    drawSummaryTable(doc, rows, cursorY);

                    doc.addPage('a4', 'p');
                    drawDetailedRecords(doc, rows);

                    stampLetterhead(doc, logoData);
                    const totalPages = doc.internal.getNumberOfPages();
                    for (let i = 1; i <= totalPages; i++) {
                        doc.setPage(i);
                        drawFooter(doc, i, totalPages, generatedAt);
                    }
                    doc.save('My_Evaluation_Report_' + dateStr + '.pdf');
                }

                /*  FILE 2: VISUAL ANALYTICS REPORT  */
                if (mode === 'charts' || mode === 'both') {
                    const analyticsDoc = new jsPDF('l', 'pt', 'a4');
                    let aCursorY = drawReportHeader(
                        analyticsDoc, logoData, rows.length, generatedAt, filterSummary,
                        'MY EVALUATION REPORT — VISUAL ANALYTICS',
                        'Charts and graphical summaries for applications linked to this evaluator'
                    );
                    aCursorY = drawIntro(
                        analyticsDoc, aCursorY, rows.length,
                        `This report presents the visual analytics for the ${rows.length} application(s) linked to this evaluator and currently matching the applied filters, including status distribution, trends, and decision breakdowns.`
                    );
                    drawChartsPage(analyticsDoc, aCursorY);

                    stampLetterhead(analyticsDoc, logoData);
                    const totalAnalyticsPages = analyticsDoc.internal.getNumberOfPages();
                    for (let i = 1; i <= totalAnalyticsPages; i++) {
                        analyticsDoc.setPage(i);
                        drawFooter(analyticsDoc, i, totalAnalyticsPages, generatedAt);
                    }
                    analyticsDoc.save('My_Evaluation_Report_Analytics_' + dateStr + '.pdf');
                }

            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        const palette = ['#1d4ed8', '#3b82f6', '#10b981', '#d97706', '#6366f1', '#eab308', '#dc2626', '#ec4899', '#0891b2', '#64748b'];

        function applyChartTheme() {
            const dark = document.documentElement.getAttribute('data-theme') === 'dark';
            const textColor = dark ? '#cbd5e1' : '#475569';
            const gridColor = dark ? '#334155' : '#e2e8f0';

            // Still set defaults so any chart created AFTER this point picks up
            // the right colors immediately.
            Chart.defaults.color = textColor;
            Chart.defaults.borderColor = gridColor;

            // Chart.js merges defaults into each chart's own options at creation time,
            // so already-created charts never see later default changes. Patch each
            // instance's resolved options directly, then update().
            Object.values(chartInstances).forEach(chart => {
                if (!chart || !chart.options) return;

                const legend = chart.options.plugins && chart.options.plugins.legend;
                if (legend && legend.labels) legend.labels.color = textColor;

                if (chart.options.scales) {
                    Object.values(chart.options.scales).forEach(scale => {
                        if (!scale) return;
                        if (scale.ticks) scale.ticks.color = textColor;
                        if (scale.grid) scale.grid.color = gridColor;
                        if (scale.border) scale.border.color = gridColor;
                    });
                }

                chart.update();
            });
        }
        applyChartTheme();

        chartInstances['chartTrend'] = new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($trend, 'bucket')) ?>,
                datasets: [{
                    label: 'Applications',
                    data: <?= json_encode(array_map('intval', array_column($trend, 'cnt'))) ?>,
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29,78,216,.12)',
                    fill: true,
                    tension: .3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        chartInstances['chartStatus'] = new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($statusChart, 'label')) ?>,
                datasets: [{
                    data: <?= json_encode(array_map('intval', array_column($statusChart, 'cnt'))) ?>,
                    backgroundColor: palette
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 10
                            },
                            boxWidth: 10
                        }
                    }
                }
            }
        });

        function barChart(canvasId, labels, data, color) {
            const chart = new Chart(document.getElementById(canvasId), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: color,
                        borderRadius: 5
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
            chartInstances[canvasId] = chart;
            return chart;
        }

        barChart('chartCountry', <?= json_encode(array_column($byCountry, 'label')) ?>, <?= json_encode(array_map('intval', array_column($byCountry, 'cnt'))) ?>, '#d97706');
        barChart('chartPosition', <?= json_encode(array_column($byPosition, 'label')) ?>, <?= json_encode(array_map('intval', array_column($byPosition, 'cnt'))) ?>, '#eab308');

        function barChartScrollable(canvasId, innerId, holderId, labels, data, color) {
            const inner = document.getElementById(innerId);
            const holder = document.getElementById(holderId);
            const canvas = document.getElementById(canvasId);

            const ctx = canvas.getContext('2d');
            ctx.font = '11px "DM Sans", system-ui, sans-serif';
            const longestLabelWidth = labels.reduce(
                (max, l) => Math.max(max, ctx.measureText(String(l)).width),
                0
            );

            const baseWidth = holder.clientWidth || 320;
            const neededWidth = Math.max(baseWidth, longestLabelWidth + 260);
            inner.style.width = neededWidth + 'px';
            inner.style.minWidth = neededWidth + 'px';

            const chart = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: color,
                        borderRadius: 5
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        },
                        y: {
                            ticks: {
                                autoSkip: false,
                                crossAlign: 'far'
                            },
                            afterFit: (scale) => {
                                scale.width = Math.min(longestLabelWidth + 20, 300);
                            }
                        }
                    }
                }
            });
            chartInstances[canvasId] = chart;
            return chart;
        }

        barChartScrollable(
            'chartEmployer', 'employerScrollInner', 'employerScrollHolder',
            <?= json_encode(array_column($byEmployer, 'label')) ?>,
            <?= json_encode(array_map('intval', array_column($byEmployer, 'cnt'))) ?>,
            '#ec4899'
        );

        chartInstances['chartApprovedVs'] = new Chart(document.getElementById('chartApprovedVs'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($approvedVsDisapproved, 'label')) ?>,
                datasets: [{
                    data: <?= json_encode(array_map('intval', array_column($approvedVsDisapproved, 'cnt'))) ?>,
                    backgroundColor: ['#10b981', '#dc2626']
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        document.querySelector('.theme-toggle')?.addEventListener('click', () => {
            setTimeout(() => {
                applyChartTheme();
            }, 50);
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

        let prePrintEmployerWidth = null;

        window.addEventListener('beforeprint', () => {
            const inner = document.getElementById('employerScrollInner');
            if (inner) {
                prePrintEmployerWidth = inner.style.width;
                inner.style.width = '100%';
                inner.style.minWidth = '0';
            }
            Object.values(chartInstances).forEach(chart => {
                try {
                    chart.resize();
                } catch (e) {}
            });
        });

        window.addEventListener('afterprint', () => {
            const inner = document.getElementById('employerScrollInner');
            if (inner && prePrintEmployerWidth !== null) {
                inner.style.width = prePrintEmployerWidth;
                inner.style.minWidth = prePrintEmployerWidth;
            }
            Object.values(chartInstances).forEach(chart => {
                try {
                    chart.resize();
                } catch (e) {}
            });
        });
    </script>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
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

            <a href="evaluator-reports.php" class="dk active" data-tip="Applicant Reports">
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
        window.DMW_USER_EMAIL = <?= json_encode($evaluator_email) ?>;
        window.DMW_NOTIF_API = '../api/poll_notifications.php';
        window.DMW_APP_VIEW_URL = 'view-application.php?id=';
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
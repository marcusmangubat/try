<?php

/**
 * applicant-reports.php – DMW RO IV-A Admin Reports
 * Comprehensive Applicant Reports: filters -> summary cards -> charts -> applicant table.
 * One row per application (correlated subqueries used for 1:many relations so no
 * duplicate rows appear even though an application can have many documents,
 * evaluations, assignments, answers, or preferred countries/positions).
 * Every filter, summary card, chart, and export operates on the SAME filtered
 * result set (built once via buildWhere()) so numbers always stay in sync.
 */
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper((substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1)));

/* HELPERS (same shape as report.php) */

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

/**
 * Resolves a date-range filter (day / week / month / year / custom) into
 * concrete [start, end] datetime strings for use in a BETWEEN clause.
 * Returns [null, null] when no range should be applied (all-time).
 */
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

/* FILTERS — read from querystring */

$f_date_preset    = trim($_GET['date_preset'] ?? '');
$f_date_from      = trim($_GET['date_from'] ?? '');
$f_date_to        = trim($_GET['date_to'] ?? '');
$today_date       = date('Y-m-d');
$f_rsf_id         = (int) ($_GET['rsf_id'] ?? 0);
$f_country        = trim($_GET['country'] ?? '');
$f_status         = trim($_GET['status'] ?? '');
$f_evaluator_id   = (int) ($_GET['evaluator_id'] ?? 0);
$f_employer       = trim($_GET['employer'] ?? '');
$f_search         = trim($_GET['search'] ?? '');

$validStatuses = ['pending', 'under_evaluation', 'for_compliance', 'resubmitted', 'approved', 'disapproved'];
if ($f_status !== '' && !in_array($f_status, $validStatuses, true)) $f_status = '';

[$rangeStart, $rangeEnd] = resolveDateRange($f_date_preset, $f_date_from, $f_date_to);

function buildWhere(?string $rangeStart, ?string $rangeEnd): array
{
    global $f_rsf_id, $f_country, $f_status,
        $f_evaluator_id, $f_employer, $f_search;

    // Drafts are unsubmitted, in-progress applications — never shown in
    // admin-facing summaries, charts, tables, or exports.
    $where  = ["a.status != 'draft'"];
    $types  = '';
    $params = [];

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
    if ($f_evaluator_id > 0) {
        $where[] = 'a.current_evaluator_id = ?';
        $types  .= 'i';
        $params[] = $f_evaluator_id;
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

[$where, $types, $params] = buildWhere($rangeStart, $rangeEnd);
$whereSql = whereSql($where);

// Base FROM used by summary + chart queries (lightweight — no client_profiles/degree join)
$baseFrom = "FROM applications a
             JOIN clients c ON c.cid = a.client_id
             JOIN rsf_job_orders j ON j.id = a.rsf_id
             LEFT JOIN evaluators ev ON ev.eid = a.current_evaluator_id";

// Extended FROM used by the main applicant table (adds category/subcategory display + profile + degree)
$baseFromFull = $baseFrom . "
             LEFT JOIN job_subcategories jsub ON jsub.id = j.subcategory_id
             LEFT JOIN job_categories jc ON jc.id = jsub.category_id
             LEFT JOIN client_profiles cp ON cp.client_id = c.cid
             LEFT JOIN degree_programs dp ON dp.id = cp.degree_id";

// Lean FROM (no evaluator-of-record join) — used by the avg-processing-time query below
$baseFromNoEval = "FROM applications a
             JOIN clients c ON c.cid = a.client_id
             JOIN rsf_job_orders j ON j.id = a.rsf_id";

/* FILTER OPTION LISTS*/

$rsfOptions = [];
$res = $database->query("SELECT id, rsf_number, position_title, employer_name FROM rsf_job_orders ORDER BY position_title ASC");
while ($row = $res->fetch_assoc()) $rsfOptions[] = $row;

$classificationOptions = [];
$res = $database->query("SELECT DISTINCT classification FROM rsf_job_orders WHERE classification IS NOT NULL AND classification <> '' ORDER BY classification ASC");
while ($row = $res->fetch_assoc()) $classificationOptions[] = $row['classification'];

$countryOptions = [];
$res = $database->query("SELECT DISTINCT worksite_country FROM rsf_job_orders WHERE worksite_country IS NOT NULL AND worksite_country <> '' ORDER BY worksite_country ASC");
while ($row = $res->fetch_assoc()) $countryOptions[] = $row['worksite_country'];

$evaluatorOptions = [];
$res = $database->query("SELECT eid, full_name, email FROM evaluators ORDER BY full_name ASC");
while ($row = $res->fetch_assoc()) $evaluatorOptions[] = $row;

$classificationOptions = [];
$res = $database->query("SELECT DISTINCT classification FROM rsf_job_orders WHERE classification IS NOT NULL AND classification <> '' ORDER BY classification ASC");
while ($row = $res->fetch_assoc()) $classificationOptions[] = $row['classification'];

$employerOptions = [];
$res = $database->query("SELECT DISTINCT employer_name FROM rsf_job_orders WHERE employer_name IS NOT NULL AND employer_name <> '' ORDER BY employer_name ASC");
while ($row = $res->fetch_assoc()) $employerOptions[] = $row['employer_name'];

/* FILTER SUMMARY TEXT (presentational only — used in PDF header)*/

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
if ($f_evaluator_id > 0) {
    foreach ($evaluatorOptions as $o) {
        if ((int) $o['eid'] === $f_evaluator_id) {
            $filterSummaryParts[] = 'Evaluator: ' . ($o['full_name'] ?: $o['email']);
            break;
        }
    }
}
if ($f_employer !== '') $filterSummaryParts[] = 'Employer: ' . $f_employer;
if ($f_search !== '') $filterSummaryParts[] = 'Search: "' . $f_search . '"';
$filterSummaryText = $filterSummaryParts ? implode('  •  ', $filterSummaryParts) : 'All records (no filters applied)';

/*  SUMMARY CARDS */

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

/* CHART DATASETS (all reuse the same filtered WHERE clause) */

// 1) Applications over time
$granularity = chooseGranularity($rangeStart, $rangeEnd);
$dateExpr = $granularity === 'day' ? "DATE_FORMAT(a.submitted_at, '%Y-%m-%d')"
    : ($granularity === 'year' ? "DATE_FORMAT(a.submitted_at, '%Y')" : "DATE_FORMAT(a.submitted_at, '%Y-%m')");
$trend = [];
$res = q($database, "SELECT $dateExpr AS bucket, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY bucket ORDER BY bucket ASC", $types, $params);
while ($row = $res->fetch_assoc()) $trend[] = $row;

// 2) Status distribution (fixed order, from summary above)
$statusChart = [];
foreach (['pending', 'under_evaluation', 'for_compliance', 'resubmitted', 'approved', 'disapproved'] as $s) {
    $statusChart[] = ['label' => statusLabel($s), 'cnt' => $summary[$s]];
}

// 3) By country
$byCountry = [];
$res = q($database, "SELECT COALESCE(NULLIF(j.worksite_country,''),'Not Specified') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byCountry[] = $row;

// 4) By position
$byPosition = [];
$res = q($database, "SELECT COALESCE(NULLIF(j.position_title,''),'Not Specified') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byPosition[] = $row;


// 6) By employer
$byEmployer = [];
$res = q($database, "SELECT COALESCE(NULLIF(j.employer_name,''),'Not Specified') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byEmployer[] = $row;

// 9) Average processing time (submitted → final decision), by the evaluator who decided
// Uses a window function to grab each application's LAST terminal decision, then
// matches it to the evaluator who made it (not necessarily current_evaluator_id,
// since reassignment can happen). Requires MySQL 8.0+ (this DB runs 8.4.3, so it's fine).
$avgProcessing = [];
$sqlAvg = "
    WITH latest_eval AS (
        SELECT e.application_id, e.evaluator_id, e.evaluated_at,
               ROW_NUMBER() OVER (PARTITION BY e.application_id ORDER BY e.evaluated_at DESC) AS rn
        FROM evaluations e
        WHERE e.decision IN ('approved','disapproved') AND e.evaluator_id IS NOT NULL
    )
    SELECT evr.full_name AS label,
           ROUND(AVG(DATEDIFF(le.evaluated_at, a.submitted_at)), 1) AS avg_days,
           COUNT(*) AS decided_count
    $baseFromNoEval
    JOIN latest_eval le ON le.application_id = a.id AND le.rn = 1
    JOIN evaluators evr ON evr.eid = le.evaluator_id
    $whereSql
    GROUP BY evr.eid, evr.full_name
    ORDER BY avg_days ASC
";
$res = q($database, $sqlAvg, $types, $params);
while ($row = $res->fetch_assoc()) {
    $row['avg_days']       = (float) $row['avg_days'];
    $row['decided_count']  = (int) $row['decided_count'];
    $avgProcessing[] = $row;
}

// 10) RSF job order fill rate (applications received vs. slots available)
// Application-level filters (date range, status, evaluator, handling_party, search) are
// applied inside the LEFT JOIN's ON clause — not WHERE — so RSFs with zero *matching*
// applications still appear with a 0 count instead of disappearing. RSF-level filters
// (category, subcategory, country, classification, employer, rsf_id) filter the WHERE
// clause directly since rsf_job_orders is the driving table here.
function buildFillRateFilters(?string $rangeStart, ?string $rangeEnd): array
{
    global $f_rsf_id, $f_country, $f_status,
        $f_evaluator_id, $f_employer, $f_search;

    $rsfWhere = [];
    $rsfTypes = '';
    $rsfParams = [];
    if ($f_rsf_id > 0) {
        $rsfWhere[] = 'j.id = ?';
        $rsfTypes .= 'i';
        $rsfParams[] = $f_rsf_id;
    }
    if ($f_country !== '') {
        $rsfWhere[] = 'j.worksite_country = ?';
        $rsfTypes .= 's';
        $rsfParams[] = $f_country;
    }
    if ($f_employer !== '') {
        $rsfWhere[] = 'j.employer_name = ?';
        $rsfTypes .= 's';
        $rsfParams[] = $f_employer;
    }

    // Drafts shouldn't count as "received" applications against a job order's slots.
    $appOn = ["a.status != 'draft'"];
    $appTypes = '';
    $appParams = [];
    if ($rangeStart && $rangeEnd) {
        $appOn[] = 'a.submitted_at BETWEEN ? AND ?';
        $appTypes .= 'ss';
        $appParams[] = $rangeStart;
        $appParams[] = $rangeEnd;
    }
    if ($f_status !== '') {
        $appOn[] = 'a.status = ?';
        $appTypes .= 's';
        $appParams[] = $f_status;
    }
    if ($f_evaluator_id > 0) {
        $appOn[] = 'a.current_evaluator_id = ?';
        $appTypes .= 'i';
        $appParams[] = $f_evaluator_id;
    }
    if ($f_search !== '') {
        if (ctype_digit($f_search)) {
            $appOn[]  = '(a.id = ? OR EXISTS (SELECT 1 FROM clients cx WHERE cx.cid = a.client_id AND (cx.email LIKE ? OR CONCAT_WS(\' \', cx.first_name, cx.middle_name, cx.last_name, cx.suffix) LIKE ?)))';
            $appTypes .= 'iss';
            $appParams[] = (int) $f_search;
            $appParams[] = '%' . $f_search . '%';
            $appParams[] = '%' . $f_search . '%';
        } else {
            $appOn[]  = 'EXISTS (SELECT 1 FROM clients cx WHERE cx.cid = a.client_id AND (cx.email LIKE ? OR CONCAT_WS(\' \', cx.first_name, cx.middle_name, cx.last_name, cx.suffix) LIKE ?))';
            $appTypes .= 'ss';
            $appParams[] = '%' . $f_search . '%';
            $appParams[] = '%' . $f_search . '%';
        }
    }

    return [$rsfWhere, $rsfTypes, $rsfParams, $appOn, $appTypes, $appParams];
}

[$rsfWhere, $rsfTypes, $rsfParams, $appOn, $appTypes, $appParams] = buildFillRateFilters($rangeStart, $rangeEnd);
$rsfWhereSql = $rsfWhere ? ('WHERE ' . implode(' AND ', $rsfWhere)) : '';
$appOnSql    = $appOn ? (' AND ' . implode(' AND ', $appOn)) : '';

$fillRate = [];
$sqlFill = "
    SELECT j.id, j.rsf_number, j.position_title, j.slots_available,
           COUNT(a.id) AS application_count
    FROM rsf_job_orders j
    LEFT JOIN applications a ON a.rsf_id = j.id $appOnSql
    $rsfWhereSql
    GROUP BY j.id, j.rsf_number, j.position_title, j.slots_available
    HAVING j.slots_available IS NOT NULL AND j.slots_available > 0
    ORDER BY (COUNT(a.id) / j.slots_available) ASC, j.slots_available DESC
    LIMIT 15
";
// ON-clause params come before WHERE-clause params in the SQL text, so bind in that order
$res = q($database, $sqlFill, $appTypes . $rsfTypes, array_merge($appParams, $rsfParams));
while ($row = $res->fetch_assoc()) {
    $row['application_count'] = (int) $row['application_count'];
    $row['slots_available']   = (int) $row['slots_available'];
    $row['label']              = $row['position_title'] ?: $row['rsf_number'];
    $fillRate[] = $row;
}

// 7) By evaluator
$byEvaluator = [];
$res = q($database, "SELECT COALESCE(ev.full_name,'Unassigned') AS label, COUNT(*) AS cnt $baseFrom $whereSql GROUP BY label ORDER BY cnt DESC LIMIT 10", $types, $params);
while ($row = $res->fetch_assoc()) $byEvaluator[] = $row;

// 8) Approved vs Disapproved
$approvedVsDisapproved = [
    ['label' => 'Approved', 'cnt' => $summary['approved']],
    ['label' => 'Disapproved', 'cnt' => $summary['disapproved']],
];

/*MAIN APPLICANT TABLE — one row per application
Correlated subqueries prevent duplicate rows from the 1:many
evaluations / documents / preferred-countries / preferred-positions
relationships.*/

$rows = [];
$res = q($database, "
    SELECT
        a.id AS application_id, a.status, a.handling_party, a.submitted_at, a.updated_at, a.notes AS admin_notes,
        c.cid AS client_id, c.first_name, c.middle_name, c.last_name, c.suffix,
        c.email AS applicant_email, c.phone AS applicant_phone,
        j.id AS job_order_id, j.rsf_number, j.position_title, j.employer_name, j.worksite_country, j.classification,
        jc.category_name, jsub.subcategory_name,
        ev.eid AS evaluator_id, ev.full_name AS evaluator_name,
        cp.educational_attainment, cp.course_degree, dp.degree_name, cp.work_experience_years,
        cp.last_employer, cp.last_position, cp.citizenship, cp.civil_status, cp.school_name,
        cp.year_graduated, cp.height_cm, cp.weight_kg,
        (SELECT e.decision FROM evaluations e WHERE e.application_id = a.id ORDER BY e.evaluated_at DESC LIMIT 1) AS latest_decision,
        (SELECT e.evaluated_at FROM evaluations e WHERE e.application_id = a.id ORDER BY e.evaluated_at DESC LIMIT 1) AS latest_decision_at,
        (SELECT COUNT(*) FROM application_documents ad WHERE ad.application_id = a.id) AS document_count,
        (SELECT pc.country FROM client_preferred_countries pc WHERE pc.client_id = c.cid ORDER BY pc.priority_order ASC LIMIT 1) AS preferred_country,
        (SELECT jsub2.subcategory_name FROM client_preferred_positions pp
            JOIN job_subcategories jsub2 ON jsub2.id = pp.subcategory_id
            WHERE pp.client_id = c.cid ORDER BY pp.priority_order ASC LIMIT 1) AS preferred_position
    $baseFromFull
    $whereSql
    ORDER BY a.submitted_at DESC
    LIMIT 5000
", $types, $params);
while ($row = $res->fetch_assoc()) $rows[] = $row;

foreach ($rows as &$r) {
    $r['applicant_name']        = fullName($r);
    $r['submitted_at_fmt']      = $r['submitted_at'] ? date('M j, Y g:i A', strtotime($r['submitted_at'])) : '—';
    $r['updated_at_fmt']        = $r['updated_at'] ? date('M j, Y g:i A', strtotime($r['updated_at'])) : '—';
    $r['status_label']          = statusLabel($r['status']);
    $r['status_badge_class']    = badgeClass($r['status']);
    $r['handling_party_label']  = $r['handling_party'] ? ucfirst($r['handling_party']) : '—';
    $r['evaluator_name']        = $r['evaluator_name'] ?: 'Unassigned';
    $r['latest_decision_label'] = $r['latest_decision'] ? statusLabel($r['latest_decision']) : '—';
    $r['latest_decision_at_fmt'] = $r['latest_decision_at'] ? date('M j, Y g:i A', strtotime($r['latest_decision_at'])) : '—';
    $r['document_count']        = (int) $r['document_count'];
    $r['degree_course']         = $r['degree_name'] ?: ($r['course_degree'] ?: '—');
    $r['educational_attainment'] = $r['educational_attainment'] ?: '—';
    $r['work_experience_display'] = $r['work_experience_years'] !== null ? $r['work_experience_years'] . ' yr(s)' : '—';
    $r['preferred_country']     = $r['preferred_country'] ?: '—';
    $r['preferred_position']    = $r['preferred_position'] ?: '—';
    $r['category_name']         = $r['category_name'] ?: '—';
    $r['subcategory_name']      = $r['subcategory_name'] ?: '—';

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
    ['key' => 'applicant_name',        'label' => 'Applicant Name'],
    ['key' => 'applicant_email',       'label' => 'Email'],
    ['key' => 'applicant_phone',       'label' => 'Phone'],
    ['key' => 'submitted_at_fmt',      'label' => 'Application Date'],
    ['key' => 'position_title',        'label' => 'Position'],
    ['key' => 'category_name',         'label' => 'Category'],
    ['key' => 'subcategory_name',      'label' => 'Subcategory'],
    ['key' => 'employer_name',         'label' => 'Employer'],
    ['key' => 'worksite_country',      'label' => 'Worksite Country'],
    ['key' => 'classification',        'label' => 'Classification'],
    ['key' => 'status_label',          'label' => 'Status'],
    ['key' => 'handling_party_label',  'label' => 'Handling Party'],
    ['key' => 'evaluator_name',        'label' => 'Current Evaluator'],
    ['key' => 'latest_decision_label', 'label' => 'Latest Decision'],
    ['key' => 'latest_decision_at_fmt', 'label' => 'Latest Decision Date'],
    ['key' => 'document_count',        'label' => 'Documents'],
    ['key' => 'profile_completion',    'label' => 'Profile Completion (%)'],
    ['key' => 'educational_attainment', 'label' => 'Education'],
    ['key' => 'degree_course',         'label' => 'Degree / Course'],
    ['key' => 'work_experience_display', 'label' => 'Work Experience'],
    ['key' => 'preferred_country',     'label' => 'Preferred Country'],
    ['key' => 'preferred_position',    'label' => 'Preferred Position'],
    ['key' => 'updated_at_fmt',        'label' => 'Last Updated'],
];
// Columns exposed to the client for the dynamic table + CSV/Excel/PDF export
// Slimmed-down set shown in the on-screen table. Full detail stays in
// EXPORT_COLUMNS for CSV/Excel/PDF — clicking a row still opens the
// full application-detail.php record.
$tableColumns = [
    ['key' => 'applicant_name',   'label' => 'Applicant Name'],
    ['key' => 'applicant_email',  'label' => 'Email'],
    ['key' => 'position_title',   'label' => 'Position'],
    ['key' => 'employer_name',    'label' => 'Employer'],
    ['key' => 'worksite_country', 'label' => 'Country'],
    ['key' => 'status_label',     'label' => 'Status'],
    ['key' => 'evaluator_name',   'label' => 'Evaluator'],
    ['key' => 'submitted_at_fmt', 'label' => 'Application Date'],
];

// Dock "Apps" badge — same query as report.php / dashboard.php
$badge_stmt = $database->prepare("SELECT COUNT(*) AS total FROM applications WHERE status IN ('pending', 'for_compliance', 'resubmitted')");
$badge_stmt->execute();
$active_apps = $badge_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$badge_stmt->close();

$generatedAt = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applicant Reports – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
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
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
            --ok: #10b981;
            --amber: #d97706;
            --indigo: #6366f1;
            --gold: #eab308;
            --red: #dc2626;
            --pink: #ec4899;
            --text1: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;
            --bdr: #e2e8f0;
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
            background: #eef2f7;
            color: var(--text1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--bdr);
            background: var(--surface);
            color: var(--text1);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, border-color .15s;
        }

        .btn:hover {
            background: var(--surface2);
        }

        .btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .btn-primary {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--blue-lt);
        }

        .btn-green {
            background: var(--ok);
            border-color: var(--ok);
            color: #fff;
        }

        .btn-green:hover {
            background: var(--ok);
            border-color: var(--ok);
            color: #fff;
            filter: brightness(1.08);
        }

        .btn-red {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
        }

        .btn-red:hover {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
            filter: brightness(1.08);
        }

        .btn-ghost {
            background: transparent;
        }

        /* ── Page shell ─────────────────────────────────────────── */
        .sheet {
            max-width: 1280px;
            margin: 20px auto;
            padding: 0 16px 110px;
        }

        .letterhead {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .letterhead-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .letterhead-left img {
            height: 46px;
        }

        .letterhead h1 {
            font-size: 16px;
            margin: 0 0 2px;
            font-family: 'DM Serif Display', serif;
        }

        .letterhead p {
            margin: 0;
            font-size: 11px;
            color: var(--text2);
        }

        .letterhead-right {
            text-align: right;
            font-size: 11px;
            color: var(--text2);
        }

        .letterhead-right b {
            color: var(--text1);
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .report-card {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
        }

        .report-card h2 {
            font-size: 15px;
            margin: 0 0 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .report-card .sub {
            font-size: 11px;
            color: var(--text2);
            margin: 0 0 16px;
        }

        /* ── Filter panel ───────────────────────────────────────── */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 12px;
        }

        .filter-field label {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--text2);
            margin-bottom: 5px;
        }

        .filter-field select,
        .filter-field input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--bdr);
            border-radius: 8px;
            font-size: 12.5px;
            font-family: inherit;
            background: var(--surface2);
            color: var(--text1);
        }

        .filter-field select:focus,
        .filter-field input:focus {
            outline: 2px solid var(--blue-lt);
            outline-offset: 1px;
            background: #fff;
        }

        .custom-range {
            display: none;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .custom-range.show {
            display: grid;
        }

        .date-input-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .date-input-group .di-label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--text2);
        }

        .date-input-group .di-label i {
            font-size: 9px;
            color: var(--blue-lt);
        }

        .date-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .date-input-wrap i.di-icon {
            position: absolute;
            left: 10px;
            font-size: 12px;
            color: var(--text3);
            pointer-events: none;
            transition: color .15s;
        }

        .date-input-wrap input[type="date"] {
            padding: 8px 10px 8px 30px;
            border: 1px solid var(--bdr);
            border-radius: 8px;
            font-size: 12.5px;
            font-family: inherit;
            background: var(--surface2);
            color: var(--text1);
            width: 100%;
            cursor: pointer;
            color-scheme: light;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }

        .date-input-wrap input[type="date"]:hover {
            background: #fff;
            border-color: #cbd5e1;
        }

        .date-input-wrap input[type="date"]:focus {
            outline: none;
            background: #fff;
            border-color: var(--blue-lt);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .15);
        }

        .date-input-wrap input[type="date"]:focus+i.di-icon,
        .date-input-wrap:focus-within i.di-icon {
            color: var(--blue-lt);
        }

        /* Invalid range feedback (To earlier than From) */
        .date-input-wrap.dw-error input[type="date"] {
            border-color: var(--red);
            background: #fef2f2;
        }

        .date-range-hint {
            grid-column: 1 / -1;
            font-size: 10.5px;
            color: var(--text3);
            display: flex;
            align-items: center;
            gap: 5px;
            min-height: 14px;
        }

        .date-range-hint.err {
            color: var(--red);
            font-weight: 600;
        }

        .date-range-hint i {
            font-size: 10px;
        }

        /* Normalize the native calendar-picker glyph so it doesn't clash with our icon */
        .date-input-wrap input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0;
            position: absolute;
            right: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--bdr);
        }

        /* ── Summary tiles ──────────────────────────────────────── */
        .tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));
            gap: 10px;
        }

        .tile {
            background: var(--surface2);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 12px 14px;
            border-left: 3px solid var(--blue-lt);
        }

        .tile .tv {
            font-size: 22px;
            font-weight: 800;
        }

        .tile .tl {
            font-size: 10.5px;
            color: var(--text2);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        /* ── Charts grid ────────────────────────────────────────── */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 16px;
        }

        .chart-card {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 12px;
            padding: 16px 18px;
        }

        .chart-card h3 {
            font-size: 12.5px;
            margin: 0 0 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text1);
        }

        .chart-card .chart-holder {
            position: relative;
            height: 260px;
        }

        .chart-holder.chart-holder-x-scroll {
            overflow-x: auto;
            overflow-y: hidden;
        }

        .chart-holder.chart-holder-x-scroll .chart-scroll-inner {
            height: 100%;
        }

        /* thin, unobtrusive scrollbar for the employer chart */
        .chart-holder.chart-holder-x-scroll {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f8fafc;
        }

        .chart-holder.chart-holder-x-scroll::-webkit-scrollbar {
            height: 6px;
        }

        .chart-holder.chart-holder-x-scroll::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        .chart-holder.chart-holder-x-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        /* Applicant table + custom dropdown option list scrollbars */
        .table-scroll,
        .rs-option-list {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f8fafc;
        }

        .table-scroll::-webkit-scrollbar,
        .rs-option-list::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .table-scroll::-webkit-scrollbar-track,
        .rs-option-list::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        .table-scroll::-webkit-scrollbar-thumb,
        .rs-option-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        /* ── Table ──────────────────────────────────────────────── */
        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .quick-search {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface2);
            border: 1px solid var(--bdr);
            border-radius: 8px;
            padding: 7px 12px;
            min-width: 240px;
        }

        .quick-search input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 12.5px;
            font-family: inherit;
            flex: 1;
        }

        .table-scroll {
            overflow-x: auto;
            border: 1px solid var(--bdr);
            border-radius: 10px;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }

        table.data-table thead th {
            background: var(--surface2);
            text-align: left;
            padding: 9px 10px;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: var(--text2);
            border-bottom: 2px solid var(--bdr);
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
        }

        table.data-table thead th:hover {
            color: var(--blue);
        }

        table.data-table thead th .sort-ic {
            font-size: 9px;
            margin-left: 4px;
            opacity: .5;
        }

        table.data-table tbody td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--bdr);
            color: var(--text1);
            white-space: nowrap;
        }

        table.data-table tbody tr {
            cursor: pointer;
        }

        table.data-table tbody tr:hover {
            background: #eff6ff;
        }

        table.data-table tbody tr:nth-child(even) {
            background: #fbfcfe;
        }

        table.data-table tbody tr:nth-child(even):hover {
            background: #eff6ff;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
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
            background: #e0e7ff;
            color: #3730a3;
        }

        .empty-row td {
            text-align: center;
            color: var(--text3);
            padding: 28px;
            font-style: italic;
        }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            font-size: 12px;
            color: var(--text2);
        }

        .pagination-bar select {
            border: 1px solid var(--bdr);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            background: var(--surface);
        }

        .page-btns {
            display: flex;
            gap: 6px;
        }

        .page-btns button {
            border: 1px solid var(--bdr);
            background: var(--surface);
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .page-btns button:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        .footer-note {
            font-size: 10.5px;
            color: var(--text3);
            text-align: center;
            margin-top: 18px;
        }

        .pdf-export-wrap {
            position: relative;
            display: inline-block;
        }

        .pdf-export-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            min-width: 260px;
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(11, 31, 75, .16);
            z-index: 90;
            padding: 6px;
        }

        .pdf-export-menu.open {
            display: block;
        }

        .pdf-menu-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
            text-align: left;
            padding: 9px 10px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
        }

        .pdf-menu-item i {
            font-size: 13px;
            margin-top: 2px;
            width: 16px;
            text-align: center;
            flex-shrink: 0;
        }

        .pdf-menu-item span {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .pdf-menu-item strong {
            font-size: 12px;
            color: var(--text1);
            font-weight: 700;
        }

        .pdf-menu-item small {
            font-size: 10.5px;
            color: var(--text3);
        }

        .pdf-menu-item:hover {
            background: var(--surface2);
        }

        .pdf-menu-divider {
            height: 1px;
            background: var(--bdr);
            margin: 4px 2px;
        }

        .rs-search-input::placeholder {
            color: var(--text3);
        }

        /*  Print  */
        @media print {
            .no-print {
                display: none !important;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body {
                background: #fff;
            }

            .sheet {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .letterhead,
            .report-card {
                border: 1px solid #d7dee7 !important;
                box-shadow: none;
                padding: 12px 14px;
                margin-bottom: 12px;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .letterhead {
                page-break-after: avoid;
            }

            .tiles {
                grid-template-columns: repeat(4, 1fr);
            }

            .tile {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            /* One chart per row, never split across a page break */
            .charts-grid {
                display: block;
            }

            .chart-card {
                page-break-inside: avoid;
                break-inside: avoid;
                margin-bottom: 14px;
                border: 1px solid #d7dee7 !important;
                width: 100%;
            }

            .chart-card .chart-holder {
                height: 230px;
                width: 100% !important;
            }

            /* Undo horizontal scroll for print — the employer chart is resized
               via JS (beforeprint handler below) to fit the printable width,
               so nothing is clipped off the visible page. */
            .chart-holder.chart-holder-x-scroll {
                overflow: visible !important;
            }

            .chart-holder.chart-holder-x-scroll .chart-scroll-inner {
                width: 100% !important;
                min-width: 0 !important;
            }

            .table-scroll {
                border: none;
                overflow: visible !important;
            }

            table.data-table {
                font-size: 8.5px;
            }

            table.data-table thead {
                display: table-header-group;
            }

            table.data-table tbody tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }

        [data-theme="dark"] {
            --blue: #3b82f6;
            --blue-lt: #60a5fa;
            --ok: #34d399;
            --amber: #fbbf24;
            --indigo: #818cf8;
            --gold: #facc15;
            --red: #f87171;
            --pink: #f472b6;
            --text1: #e2e8f0;
            --text2: #94a3b8;
            --text3: #64748b;
            --bdr: #2d3748;
            --surface: #1e293b;
            --surface2: #263449;
            --selected-row: rgba(59, 130, 246, .22);
        }

        [data-theme="dark"] body {
            background: #0f172a;
        }

        [data-theme="dark"] .filter-field select,
        [data-theme="dark"] .filter-field input,
        [data-theme="dark"] .date-input-wrap input[type="date"] {
            background: var(--surface2);
            color: var(--text1);
            border-color: var(--bdr);
            color-scheme: dark;
        }

        [data-theme="dark"] .filter-field select:focus,
        [data-theme="dark"] .date-input-wrap input[type="date"]:focus {
            background: #1a2332;
        }

        [data-theme="dark"] .date-input-wrap input[type="date"]:hover {
            background: #1a2332;
            border-color: #3b4658;
        }

        [data-theme="dark"] .date-input-wrap input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }

        [data-theme="dark"] .date-input-wrap.dw-error input[type="date"] {
            background: rgba(248, 113, 113, .12);
            border-color: var(--red);
        }

        [data-theme="dark"] table.data-table tbody tr:nth-child(even) {
            background: #1a2332;
        }

        [data-theme="dark"] table.data-table tbody tr:hover,
        [data-theme="dark"] table.data-table tbody tr:nth-child(even):hover {
            background: #263449;
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
            background: rgba(99, 102, 241, .20);
            color: #a5b4fc;
        }

        [data-theme="dark"] .quick-search {
            background: var(--surface2);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .quick-search input {
            color: var(--text1);
        }

        [data-theme="dark"] .pagination-bar select,
        [data-theme="dark"] .page-btns button {
            background: var(--surface);
            border-color: var(--bdr);
            color: var(--text1);
        }

        [data-theme="dark"] .chart-holder.chart-holder-x-scroll {
            scrollbar-color: #3b4658 #1e293b;
        }

        [data-theme="dark"] .chart-holder.chart-holder-x-scroll::-webkit-scrollbar-track {
            background: #1e293b;
        }

        [data-theme="dark"] .chart-holder.chart-holder-x-scroll::-webkit-scrollbar-thumb {
            background: #3b4658;
        }

        [data-theme="dark"] .table-scroll,
        [data-theme="dark"] .rs-option-list {
            scrollbar-color: #3b4658 #1e293b;
        }

        [data-theme="dark"] .table-scroll::-webkit-scrollbar-track,
        [data-theme="dark"] .rs-option-list::-webkit-scrollbar-track {
            background: #1e293b;
        }

        [data-theme="dark"] .table-scroll::-webkit-scrollbar-thumb,
        [data-theme="dark"] .rs-option-list::-webkit-scrollbar-thumb {
            background: #3b4658;
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

    <!--  TOP BAR  -->
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

    <div class="sheet">

        <div class="letterhead">
            <div class="letterhead-left">
                <img src="../pictures/dmw_logo.png" alt="DMW logo">
                <div>
                    <h1>Applicant Reports</h1>
                    <p><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($admin_name) ?> — Filter, analyze, and export applicant data</p>
                </div>
            </div>
            <div class="letterhead-right">
                <div>Generated: <b><?= htmlspecialchars($generatedAt) ?></b></div>
                <div>Records matched: <b><?= (int) $summary['total'] ?></b></div>
            </div>
        </div>

        <div class="toolbar-actions no-print">
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            <button class="btn btn-green" onclick="exportExcel()"><i class="fas fa-file-excel"></i> Export Excel</button>
            <button class="btn" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
            <div class="pdf-export-wrap no-print">
                <button class="btn btn-red" id="btnPdf" onclick="togglePdfMenu(event)" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-file-pdf"></i> Export PDF <i class="fas fa-chevron-down" style="font-size:9px;margin-left:2px;"></i>
                </button>
                <div class="pdf-export-menu" id="pdfExportMenu">
                    <button type="button" class="pdf-menu-item" onclick="exportPDF('details')">
                        <i class="fas fa-table-list" style="color:var(--blue-lt)"></i>
                        <span>
                            <strong>Applicant Details Only</strong>
                            <small>Summary cards + applicant table + full records</small>
                        </span>
                    </button>
                    <button type="button" class="pdf-menu-item" onclick="exportPDF('charts')">
                        <i class="fas fa-chart-column" style="color:var(--indigo)"></i>
                        <span>
                            <strong>Graphs Only</strong>
                            <small>Visual analytics charts</small>
                        </span>
                    </button>
                    <div class="pdf-menu-divider"></div>
                    <button type="button" class="pdf-menu-item" onclick="exportPDF('both')">
                        <i class="fas fa-file-pdf" style="color:var(--red)"></i>
                        <span>
                            <strong>Both (Complete Report)</strong>
                            <small>Downloads both PDF files</small>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!--  FILTERS  -->
        <form class="report-card no-print" id="filterForm" method="GET" action="applicant-reports.php">
            <h2><i class="fas fa-sliders" style="color:var(--blue-lt)"></i> Filters</h2>
            <p class="sub">Generate a report by date range and any combination of the filters below. All filters apply together to the summary cards, charts, table, and exports.</p>

            <div class="filter-grid">
                <div class="filter-field">
                    <label>Date Range</label>
                    <select name="date_preset" id="datePreset">
                        <option value="" <?= $f_date_preset === '' ? 'selected' : '' ?>>All Time</option>
                        <option value="today" <?= $f_date_preset === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="week" <?= $f_date_preset === 'week' ? 'selected' : '' ?>>This Week</option>
                        <option value="month" <?= $f_date_preset === 'month' ? 'selected' : '' ?>>This Month</option>
                        <option value="year" <?= $f_date_preset === 'year' ? 'selected' : '' ?>>This Year</option>
                        <option value="custom" <?= $f_date_preset === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                <div class="filter-field" style="grid-column: span 2;">
                    <label>Custom Range</label>
                    <div class="custom-range <?= $f_date_preset === 'custom' ? 'show' : '' ?>" id="customRange">
                        <div class="date-input-group">
                            <span class="di-label"><i class="fas fa-calendar-day"></i> From</span>
                            <div class="date-input-wrap" id="fromWrap">
                                <i class="fas fa-calendar di-icon"></i>
                                <input type="date" name="date_from" id="dateFrom" value="<?= htmlspecialchars($f_date_from) ?>" max="<?= htmlspecialchars($f_date_to !== '' && $f_date_to < $today_date ? $f_date_to : $today_date) ?>">
                            </div>
                        </div>
                        <div class="date-input-group">
                            <span class="di-label"><i class="fas fa-calendar-check"></i> To</span>
                            <div class="date-input-wrap" id="toWrap">
                                <i class="fas fa-calendar di-icon"></i>
                                <input type="date" name="date_to" id="dateTo" value="<?= htmlspecialchars($f_date_to) ?>" min="<?= htmlspecialchars($f_date_from ?: '') ?>" max="<?= htmlspecialchars($today_date) ?>">
                            </div>
                        </div>
                        <div class="date-range-hint" id="rangeHint"><i class="fas fa-circle-info"></i> Select both a start and end date.</div>
                    </div>
                </div>

                <div class="filter-field">
                    <label>Job / Position</label>
                    <select name="rsf_id">
                        <option value="0">All Positions</option>
                        <?php foreach ($rsfOptions as $o): ?>
                            <option value="<?= (int) $o['id'] ?>" <?= $f_rsf_id === (int) $o['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($o['position_title'] . ' — ' . $o['employer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>


                <div class="filter-field">
                    <label>Country</label>
                    <select name="country">
                        <option value="">All Countries</option>
                        <?php foreach ($countryOptions as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $f_country === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($validStatuses as $s): ?>
                            <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= statusLabel($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Evaluator</label>
                    <select name="evaluator_id">
                        <option value="0">All Evaluators</option>
                        <?php foreach ($evaluatorOptions as $o): ?>
                            <option value="<?= (int) $o['eid'] ?>" <?= $f_evaluator_id === (int) $o['eid'] ? 'selected' : '' ?>><?= htmlspecialchars($o['full_name'] ?: $o['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Employer</label>
                    <select name="employer">
                        <option value="">All Employers</option>
                        <?php foreach ($employerOptions as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>" <?= $f_employer === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field" style="grid-column: span 2;">
                    <label>Search (Name / Email / Application ID)</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($f_search) ?>" placeholder="e.g. Marcus Mangubat, mgmangubat@ccc.edu.ph, 123">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Generate Report</button>
                <a href="applicant-reports.php" class="btn"><i class="fas fa-rotate-left"></i> Reset Filters</a>
            </div>
        </form>

        <!--  SUMMARY CARDS  -->
        <div class="report-card">
            <h2><i class="fas fa-chart-simple" style="color:var(--blue-lt)"></i> Report Summary</h2>
            <p class="sub">Totals for the applications matching the current filters.</p>
            <div class="tiles">
                <div class="tile" style="border-color:var(--blue-lt)">
                    <div class="tv"><?= $summary['total'] ?></div>
                    <div class="tl">Total Applicants</div>
                </div>
                <div class="tile" style="border-color:var(--gold)">
                    <div class="tv"><?= $summary['pending'] ?></div>
                    <div class="tl">Pending</div>
                </div>
                <div class="tile" style="border-color:var(--indigo)">
                    <div class="tv"><?= $summary['under_evaluation'] ?></div>
                    <div class="tl">Under Evaluation</div>
                </div>
                <div class="tile" style="border-color:var(--amber)">
                    <div class="tv"><?= $summary['for_compliance'] ?></div>
                    <div class="tl">For Compliance</div>
                </div>
                <div class="tile" style="border-color:var(--pink)">
                    <div class="tv"><?= $summary['resubmitted'] ?></div>
                    <div class="tl">Resubmitted</div>
                </div>
                <div class="tile" style="border-color:var(--ok)">
                    <div class="tv"><?= $summary['approved'] ?></div>
                    <div class="tl">Approved</div>
                </div>
                <div class="tile" style="border-color:var(--red)">
                    <div class="tv"><?= $summary['disapproved'] ?></div>
                    <div class="tl">Disapproved</div>
                </div>
                <div class="tile" style="border-color:var(--blue)">
                    <div class="tv"><?= $summary['active'] ?></div>
                    <div class="tl">Active Applications</div>
                </div>
            </div>
        </div>

        <!--  CHARTS  -->
        <div class="report-card">
            <h2><i class="fas fa-chart-column" style="color:var(--indigo)"></i> Visual Analytics</h2>
            <p class="sub">All charts reflect the currently applied filters.</p>
            <div class="charts-grid">
                <div class="chart-card">
                    <h3><i class="fas fa-chart-line" style="color:var(--blue-lt)"></i> Applications Over Time (<?= $granularity ?>)</h3>
                    <div class="chart-holder"><canvas id="chartTrend"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-chart-pie" style="color:var(--indigo)"></i> Status Distribution</h3>
                    <div class="chart-holder"><canvas id="chartStatus"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-globe" style="color:var(--amber)"></i> By Country</h3>
                    <div class="chart-holder"><canvas id="chartCountry"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-briefcase" style="color:var(--gold)"></i> By Position</h3>
                    <div class="chart-holder"><canvas id="chartPosition"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-building" style="color:var(--pink)"></i> By Employer</h3>
                    <p style="font-size:10.5px;color:var(--text3);margin:-8px 0 8px;">
                        <i class="fas fa-arrows-left-right"></i> Scroll horizontally to view full employer names
                    </p>
                    <div class="chart-holder chart-holder-x-scroll" id="employerScrollHolder">
                        <div class="chart-scroll-inner" id="employerScrollInner">
                            <canvas id="chartEmployer"></canvas>
                        </div>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-user-tie" style="color:var(--red)"></i> By Evaluator</h3>
                    <div class="chart-holder"><canvas id="chartEvaluator"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-scale-balanced" style="color:var(--blue)"></i> Approved vs. Disapproved</h3>
                    <div class="chart-holder"><canvas id="chartApprovedVs"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-hourglass-half" style="color:#0891b2"></i> Avg. Processing Time by Evaluator (days)</h3>
                    <div class="chart-holder"><canvas id="chartAvgProcessing"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3><i class="fas fa-users-rectangle" style="color:#64748b"></i> RSF Fill Rate (Applications vs. Slots)</h3>
                    <div class="chart-holder"><canvas id="chartFillRate"></canvas></div>
                </div>
            </div>
        </div>

        <!--  APPLICANT TABLE  -->
        <div class="report-card">
            <h2><i class="fas fa-table-list" style="color:var(--blue-lt)"></i> Applicant Table</h2>
            <p class="sub">One row per application. Click a row to view the full application detail. Click a column header to sort.</p>

            <div class="table-toolbar no-print">
                <div class="quick-search">
                    <i class="fas fa-magnifying-glass" style="color:var(--text3)"></i>
                    <input type="text" id="quickFilter" placeholder="Quick filter current results...">
                </div>
                <div style="font-size:12px;color:var(--text2)">
                    Showing <span id="rangeLabel">0</span> of <span id="totalLabel">0</span> results
                </div>
            </div>

            <div class="table-scroll">
                <table class="data-table" id="dataTable">
                    <thead>
                        <tr id="tableHeadRow"></tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr class="empty-row">
                            <td colspan="9">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar no-print">
                <div>
                    Rows per page:
                    <select id="pageSizeSelect">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="page-btns">
                    <button id="prevPageBtn" onclick="changePage(-1)"><i class="fas fa-chevron-left"></i></button>
                    <span id="pageIndicator" style="align-self:center;padding:0 6px;">Page 1</span>
                    <button id="nextPageBtn" onclick="changePage(1)"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <p class="footer-note">DMW Regional Office IV-A — CALABARZON &nbsp;•&nbsp; Applicant Reports &nbsp;•&nbsp; For official use only</p>
    </div>

    <script>
        /* Wraps a native <select> with a styled trigger + dropdown panel,
           matching this page's own :root variables (--bdr, --blue,
           --blue-lt, --text1/2/3, --surface, --surface2) instead of the
           var(--border)/var(--navy) theme used elsewhere. The original
           <select> stays in the DOM (hidden) with its `name`/`id` intact,
           so filterForm's submit and every existing JS reference
           (e.g. datePreset.addEventListener('change', ...)) keeps
           working unchanged — our trigger dispatches a real 'change'
           event on the hidden select whenever a row is picked.

           opts.emptyValue: the value of the "All ___" option (usually ''
           but 'rsf_id'/'evaluator_id' use '0'). opts.includeEmpty: true
           here for every select on this page, since "All Positions" /
           "All Countries" / etc. are real, selectable reset choices —
           not throwaway placeholders — so they stay visible in the list. */
        function enhanceReportSelect(select, opts) {
            opts = opts || {};
            const emptyValue = opts.emptyValue !== undefined ? opts.emptyValue : '';
            const includeEmpty = opts.includeEmpty !== false;
            const searchable = opts.searchable !== false && select.options.length > 8;
            const inline = !!opts.inline;
            const placeholderOpt = select.querySelector(`option[value="${emptyValue}"]`);
            const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

            const wrapper = document.createElement('div');
            wrapper.style.cssText = inline ? 'position:relative;display:inline-flex;vertical-align:middle;' : 'position:relative;';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.style.display = 'none';
            select.tabIndex = -1;

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.style.cssText = inline ?
                'padding:4px 8px;border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-family:inherit;background:var(--surface);color:var(--text1);display:inline-flex;align-items:center;gap:6px;cursor:pointer;transition:border-color .15s;' :
                'width:100%;padding:8px 10px;border:1px solid var(--bdr);border-radius:8px;font-size:12.5px;font-family:inherit;background:var(--surface2);color:var(--text1);display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;transition:border-color .15s,background .15s;';
            trigger.innerHTML = '<span class="rs-label" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>' +
                '<i class="fas fa-chevron-down" style="font-size:9px;color:var(--text3);transition:transform .15s;flex-shrink:0;"></i>';
            wrapper.appendChild(trigger);

            const panel = document.createElement('div');
            panel.setAttribute('role', 'listbox');
            panel.style.cssText = (inline ?
                    'display:none;position:absolute;top:100%;left:0;min-width:110px;' :
                    'display:none;position:absolute;top:100%;left:0;right:0;') +
                'margin-top:6px;background:var(--surface);border:1px solid var(--bdr);border-radius:10px;box-shadow:0 10px 30px rgba(11,31,75,.16);z-index:80;overflow:hidden;';
            wrapper.appendChild(panel);

            let searchInput = null;
            if (searchable) {
                const searchWrap = document.createElement('div');
                searchWrap.style.cssText = 'padding:8px;border-bottom:1px solid var(--bdr);';
                searchWrap.innerHTML = '<div style="position:relative;">' +
                    '<i class="fas fa-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:10px;color:var(--text3);"></i>' +
                    '<input type="text" class="rs-search-input" placeholder="Search…" style="width:100%;box-sizing:border-box;padding:7px 8px 7px 26px;border:1px solid var(--bdr);border-radius:7px;font-size:11.5px;outline:none;background:var(--surface);color:var(--text1);">' +
                    '</div>';
                panel.appendChild(searchWrap);
                searchInput = searchWrap.querySelector('input');
            }

            const list = document.createElement('div');
            list.className = 'rs-option-list';
            list.style.cssText = 'max-height:240px;overflow-y:auto;padding:4px 0;';
            panel.appendChild(list);

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;font-size:12.5px;color:var(--text1);cursor:pointer;';
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
                    empty.style.cssText = 'padding:16px 12px;text-align:center;font-size:11.5px;color:var(--text3);';
                    empty.textContent = 'No matches found.';
                    list.appendChild(empty);
                }
                markSelected();
            }

            function markSelected() {
                list.querySelectorAll('[role="option"]').forEach(row => {
                    const selected = row.dataset.value === select.value;
                    row.dataset.selected = selected ? '1' : '0';
                    row.style.background = selected ? 'var(--selected-row)' : '';
                    row.style.color = selected ? 'var(--blue)' : 'var(--text1)';
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
                const label = trigger.querySelector('.rs-label');
                if (select.value === emptyValue || !opt) {
                    label.textContent = placeholder;
                    label.style.color = 'var(--text2)';
                    label.style.fontWeight = '400';
                } else {
                    label.textContent = opt.textContent;
                    label.style.color = 'var(--text1)';
                    label.style.fontWeight = '600';
                }
                markSelected();
            }

            function openPanel() {
                document.querySelectorAll('[role="listbox"]').forEach(p => {
                    if (p !== panel) p.style.display = 'none';
                });
                renderList('');
                panel.style.display = 'block';
                trigger.style.borderColor = 'var(--blue-lt)';
                trigger.style.background = 'var(--surface)';
                trigger.setAttribute('aria-expanded', 'true');
                trigger.querySelector('.fa-chevron-down').style.transform = 'rotate(180deg)';
                if (searchInput) {
                    searchInput.value = '';
                    setTimeout(() => searchInput.focus(), 10);
                }
            }

            function closePanel() {
                panel.style.display = 'none';
                trigger.style.borderColor = 'var(--bdr)';
                trigger.style.background = 'var(--surface2)';
                trigger.setAttribute('aria-expanded', 'false');
                trigger.querySelector('.fa-chevron-down').style.transform = '';
            }

            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.style.display === 'block' ? closePanel() : openPanel();
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
        enhanceReportSelect(document.querySelector('select[name="evaluator_id"]'), {
            emptyValue: '0',
            searchable: true
        });
        enhanceReportSelect(document.querySelector('select[name="employer"]'), {
            searchable: true
        });
        enhanceReportSelect(document.getElementById('pageSizeSelect'), {
            searchable: false,
            inline: true
        });
    </script>
    <script>
        /*  DATA FROM SERVER  */
        const REPORT_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const EXPORT_COLUMNS = <?= json_encode($exportColumns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        const TABLE_COLUMNS = <?= json_encode($tableColumns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

        /*  PDF REPORT CONFIG  */
        // Configurable logo path. Point this at a locally-hosted file (e.g. '../assets/dmw-logo.png')
        // if the remote dmw.gov.ph image ever fails to embed due to CORS.
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

        /* PDF style tokens — mirrors the navy government-document design used in
           application-detail.php's Export PDF output, so both reports look
           consistent. */
        const PDF_NAVY = [11, 31, 75];
        const PDF_TEXT1 = [31, 41, 55];
        const PDF_TEXT2 = [75, 85, 99];
        const PDF_TEXT3 = [156, 163, 175];
        const PDF_BORDER = [229, 231, 235];
        const PDF_LBL_BG = [248, 250, 252];

        /* Draws a navy section-title bar, matching .pr-section-title / .section-title
           in application-detail.php. Returns the Y position just below the bar. */
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

        /*  CHART INSTANCE REGISTRY  */
        // Needed by (1) the print handler, which must force chart.resize() before print
        // since Chart.js sizes canvases at creation time and won't auto-redraw for print
        // media otherwise, and (2) the PDF exporter, which embeds these as images.
        const chartInstances = {};
        /*  TABLE RENDER / SORT / PAGINATE / QUICK FILTER  */
        let currentRows = REPORT_ROWS.slice();
        let sortKey = 'submitted_at';
        let sortDir = 'desc';
        let page = 1;
        let pageSize = 25;

        function buildHead() {
            const tr = document.getElementById('tableHeadRow');
            tr.innerHTML = '';
            TABLE_COLUMNS.forEach(col => {
                const th = document.createElement('th');
                th.dataset.key = col.key;
                const span = document.createElement('span');
                span.textContent = col.label;
                th.appendChild(span);
                const ic = document.createElement('i');
                ic.className = 'fas fa-sort sort-ic';
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
            // Extra "Actions" column
            const thAction = document.createElement('th');
            thAction.textContent = 'View';
            tr.appendChild(thAction);
        }

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
                    (r.evaluator_name || '').toLowerCase().includes(q);
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

        function badgeSpan(text, cls) {
            const span = document.createElement('span');
            span.className = 'badge ' + cls;
            span.textContent = text;
            return span;
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
            tbody.innerHTML = '';

            if (!pageRows.length) {
                const tr = document.createElement('tr');
                tr.className = 'empty-row';
                const td = document.createElement('td');
                td.colSpan = TABLE_COLUMNS.length + 1;
                td.textContent = 'No applications found for the selected filters.';
                tr.appendChild(td);
                tbody.appendChild(tr);
            } else {
                pageRows.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.addEventListener('click', () => {
                        window.open('application-detail.php?id=' + encodeURIComponent(r.application_id), '_blank');
                    });
                    TABLE_COLUMNS.forEach(col => {
                        const td = document.createElement('td');
                        if (col.key === 'status_label') {
                            td.appendChild(badgeSpan(r.status_label, 'b-' + r.status));
                        } else if (col.key === 'profile_completion') {
                            td.textContent = r.profile_completion + '%';
                        } else {
                            td.textContent = (r[col.key] === null || r[col.key] === undefined || r[col.key] === '') ? '—' : r[col.key];
                        }
                        tr.appendChild(td);
                    });
                    const tdView = document.createElement('td');
                    const link = document.createElement('span');
                    link.innerHTML = '<i class="fas fa-arrow-up-right-from-square" style="color:var(--blue-lt)"></i>';
                    tdView.appendChild(link);
                    tr.appendChild(tdView);
                    tbody.appendChild(tr);
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
                    ic.className = 'fas sort-ic ' + (sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                    ic.style.opacity = '1';
                } else {
                    ic.className = 'fas fa-sort sort-ic';
                    ic.style.opacity = '.5';
                }
            });
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

        /*  FILTER FORM UX  */
        const datePreset = document.getElementById('datePreset');
        const customRange = document.getElementById('customRange');
        datePreset.addEventListener('change', () => {
            customRange.classList.toggle('show', datePreset.value === 'custom');
            if (datePreset.value === 'custom') validateDateRange();
        });

        /*  From/To live validation & mutual constraints  */
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
            rangeHint.innerHTML = `<i class="fas fa-check-circle" style="color:var(--ok)"></i> ${days} day${days === 1 ? '' : 's'} selected (${dateFrom.value} → ${dateTo.value})`;
            return true;
        }

        ['change', 'input'].forEach(evt => {
            dateFrom.addEventListener(evt, validateDateRange);
            dateTo.addEventListener(evt, validateDateRange);
        });

        dateFrom.addEventListener('change', validateDateRange);
        dateTo.addEventListener('change', validateDateRange);
        if (datePreset.value === 'custom') validateDateRange();

        filterForm.addEventListener('submit', (e) => {
            if (datePreset.value === 'custom' && !validateDateRange()) {
                e.preventDefault();
            }
        });



        /*  EXPORTS  */
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
            a.download = 'Applicant_Report_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(url);
        }

        function exportExcel() {
            const rows = exportRowsFlat();
            const ws = XLSX.utils.json_to_sheet(rows, {
                header: EXPORT_COLUMNS.map(c => c.label)
            });
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Applicant Report');
            XLSX.writeFile(wb, 'Applicant_Report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
        }

        /*  PDF REPORT: HELPERS  */

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
                console.warn('DMW logo could not be embedded in the PDF (likely a CORS restriction on the remote image). The report will still generate without it.', err);
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
            titleText = 'APPLICANT REPORT',
            subtitleText = 'Consolidated applicant and application records — DMW Application Management System') {
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

        /* New — mirrors .pr-intro in application-detail.php */
        function drawIntro(doc, startY, totalRecords, customText = null) {
            const pageWidth = doc.internal.pageSize.getWidth();
            const marginX = 30;
            const text = customText || `This report presents the consolidated applicant and application records currently matching the applied filters. It includes ${totalRecords} application(s) drawn from the DMW Application Management System, summarizing status distribution, visual analytics, and complete per-applicant detail for review, audit, or compliance purposes.`;
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
                nz(r.employer_name), nz(r.status_label), nz(r.evaluator_name), nz(r.latest_decision_at_fmt)
            ]);

            doc.autoTable({
                head: [
                    ['Applicant', 'Position', 'Country', 'Employer', 'Status', 'Evaluator', 'Decision Date']
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
                didParseCell: (data) => {
                    if (data.section === 'body' && data.column.index === 4) {
                        const statusKey = rows[data.row.index].status;
                        data.cell.styles.textColor = STATUS_COLORS[statusKey] || PDF_TEXT2;
                        data.cell.styles.fontStyle = 'bold';
                    }
                }
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
                    label: 'Current Evaluator',
                    value: r.evaluator_name
                },
                {
                    label: 'Latest Decision',
                    value: r.latest_decision_label
                },
                {
                    label: 'Decision Date',
                    value: r.latest_decision_at_fmt
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
            const disclaimer = 'This is an official system-generated report from the DMW Application Management System — Regional Office IV-A (CALABARZON). Information is confidential and intended solely for internal evaluation, compliance review, and official reporting.';
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

        /* PDF REPORT: CHARTS PAGE  */
        const PDF_CHART_CONFIG = [{
                id: 'chartTrend',
                title: 'Applications Over Time'
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
                id: 'chartEvaluator',
                title: 'By Evaluator'
            },
            {
                id: 'chartApprovedVs',
                title: 'Approved vs. Disapproved'
            },
            {
                id: 'chartAvgProcessing',
                title: 'Avg. Processing Time by Evaluator (days)'
            },
            {
                id: 'chartFillRate',
                title: 'RSF Fill Rate (Applications vs. Slots)'
            }
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
        /*  PDF REPORT: MAIN EXPORT  */

        /*  PDF EXPORT MENU TOGGLE  */
        function togglePdfMenu(e) {
            e.stopPropagation();
            const menu = document.getElementById('pdfExportMenu');
            const btn = document.getElementById('btnPdf');
            const willOpen = !menu.classList.contains('open');
            menu.classList.toggle('open', willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        function closePdfMenu() {
            const menu = document.getElementById('pdfExportMenu');
            const btn = document.getElementById('btnPdf');
            menu.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }

        document.addEventListener('click', (e) => {
            const wrap = document.querySelector('.pdf-export-wrap');
            if (wrap && !wrap.contains(e.target)) closePdfMenu();
        });

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

                /*  FILE 1: APPLICANT DETAILS REPORT  */
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
                    doc.save('Applicant_Report_' + dateStr + '.pdf');
                }

                /*  FILE 2: VISUAL ANALYTICS REPORT  */
                if (mode === 'charts' || mode === 'both') {
                    const analyticsDoc = new jsPDF('l', 'pt', 'a4');
                    let aCursorY = drawReportHeader(
                        analyticsDoc, logoData, rows.length, generatedAt, filterSummary,
                        'VISUAL ANALYTICS REPORT',
                        'Charts and graphical summaries — DMW Application Management System'
                    );
                    aCursorY = drawIntro(
                        analyticsDoc, aCursorY, rows.length,
                        `This report presents the visual analytics for the ${rows.length} application(s) currently matching the applied filters, including status distribution, trends, and evaluator performance charts.`
                    );
                    drawChartsPage(analyticsDoc, aCursorY);

                    stampLetterhead(analyticsDoc, logoData);
                    const totalAnalyticsPages = analyticsDoc.internal.getNumberOfPages();
                    for (let i = 1; i <= totalAnalyticsPages; i++) {
                        analyticsDoc.setPage(i);
                        drawFooter(analyticsDoc, i, totalAnalyticsPages, generatedAt);
                    }
                    analyticsDoc.save('Applicant_Report_Analytics_' + dateStr + '.pdf');
                }

            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        /*  CHARTS  */
        const palette = ['#1d4ed8', '#3b82f6', '#10b981', '#d97706', '#6366f1', '#eab308', '#dc2626', '#ec4899', '#0891b2', '#64748b'];

        function applyChartTheme() {
            const dark = document.documentElement.getAttribute('data-theme') === 'dark';
            const textColor = dark ? '#cbd5e1' : '#475569';
            const gridColor = dark ? '#334155' : '#e2e8f0';

            // Still set defaults so any chart created AFTER this point (e.g. a future
            // re-render) picks up the right colors immediately.
            Chart.defaults.color = textColor;
            Chart.defaults.borderColor = gridColor;

            // Chart.js merges defaults into each chart's own options at creation time,
            // so already-created charts never see later default changes. We have to
            // patch each instance's resolved options directly, then update().
            Object.values(chartInstances).forEach(chart => {
                if (!chart || !chart.options) return;

                const legend = chart.options.plugins && chart.options.plugins.legend;
                if (legend && legend.labels) legend.labels.color = textColor;

                if (chart.options.scales) {
                    Object.values(chart.options.scales).forEach(scale => {
                        if (!scale) return;
                        if (scale.ticks) scale.ticks.color = textColor;
                        if (scale.grid) scale.grid.color = gridColor;
                        if (scale.border) scale.border.color = gridColor; // axis line, Chart.js v4
                    });
                }

                chart.update();
            });
        }
        applyChartTheme();
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

            // Measure real rendered width of the longest label using the same font
            // Chart.js will use for ticks, instead of guessing chars * px.
            const ctx = canvas.getContext('2d');
            ctx.font = '11px "DM Sans", system-ui, sans-serif';
            const longestLabelWidth = labels.reduce(
                (max, l) => Math.max(max, ctx.measureText(String(l)).width),
                0
            );

            const baseWidth = holder.clientWidth || 320;
            // longest label width + fixed room for the bar area itself
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
        barChart('chartEvaluator', <?= json_encode(array_column($byEvaluator, 'label')) ?>, <?= json_encode(array_map('intval', array_column($byEvaluator, 'cnt'))) ?>, '#dc2626');
        barChart('chartAvgProcessing',
            <?= json_encode(array_column($avgProcessing, 'label')) ?>,
            <?= json_encode(array_map('floatval', array_column($avgProcessing, 'avg_days'))) ?>,
            '#0891b2'
        );

        chartInstances['chartFillRate'] = new Chart(document.getElementById('chartFillRate'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($fillRate, 'label')) ?>,
                datasets: [{
                        label: 'Applications Received',
                        data: <?= json_encode(array_map('intval', array_column($fillRate, 'application_count'))) ?>,
                        backgroundColor: '#1d4ed8',
                        borderRadius: 4
                    },
                    {
                        label: 'Slots Available',
                        data: <?= json_encode(array_map('intval', array_column($fillRate, 'slots_available'))) ?>,
                        backgroundColor: '#e2e8f0',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
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
        setInterval(updateLiveDateTime, 1000);

        /*  PRINT HANDLING  */
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

    <!--  FLOATING DOCK  -->
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
            <a href="reports.php" class="dk" data-tip="Reports">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>
            <a href="applicant-reports.php" class="dk active" data-tip="Applicant Reports">
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
    <script>
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
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
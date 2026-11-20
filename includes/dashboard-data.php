<?php
require_once __DIR__ . '/app-helpers.php';
// Single source of truth for the evaluator dashboard's filtered queries.
// Used by both evaluator/index.php (initial page load) and
// evaluator/ajax-dashboard-data.php (no-reload filter updates), so the
// two never drift out of sync.

/**
 * Builds WHERE-clause fragments + matching bind params/types for the
 * evaluator dashboard filters, using whatever table aliases a given query
 * uses for rsf_job_orders / clients. Pass '' for $dateCol to skip the
 * month filter for that particular query (e.g. a chart that already spans
 * several months).
 *
 * $filters keys: month, province, job, country (all optional strings;
 * '' or missing = "no filter").
 */
function evaluator_filter_sql(array $filters, string $rsfAlias, string $clientAlias, string $dateCol): array
{
    $conditions = [];
    $params     = [];
    $types      = '';

    if (($filters['job'] ?? '') !== '') {
        $conditions[] = "$rsfAlias.position_title = ?";
        $params[]     = $filters['job'];
        $types       .= 's';
    }
    if (($filters['country'] ?? '') !== '') {
        $conditions[] = "$rsfAlias.worksite_country = ?";
        $params[]     = $filters['country'];
        $types       .= 's';
    }
    if (($filters['province'] ?? '') !== '') {
        $conditions[] = "$clientAlias.province = ?";
        $params[]     = $filters['province'];
        $types       .= 's';
    }
    if (($filters['month'] ?? '') !== '' && $dateCol !== '') {
        $conditions[] = "DATE_FORMAT($dateCol, '%Y-%m') = ?";
        $params[]     = $filters['month'];
        $types       .= 's';
    }

    return [$conditions, $params, $types];
}

function format_turnaround(float $hours): string
{
    if ($hours <= 0) return '—';
    if ($hours < 24) return number_format($hours, 1) . 'h';
    return number_format($hours / 24, 1) . 'd';
}

/**
 * Runs every filtered query the evaluator dashboard needs and returns
 * them as one array.
 */
function evaluator_dashboard_data(mysqli $database, int $evaluator_id, array $filters): array
{
    $result = [
        'stats' => [
            'assign' => 0,
            'under_evaluation' => 0,
            'for_compliance' => 0,
            'approved' => 0,
            'disapproved' => 0,
            'resubmitted' => 0,
            'total' => 0,
        ],
        'available_count' => 0,
        'my_applications' => [],
        'country_data'    => [],
        'eval_trend'      => ['labels' => [], 'approved' => [], 'disapproved' => [], 'compliance' => []],
        'turnaround'      => [
            'overall_hours' => 0,
            'by_decision'   => [
                'approved'       => ['avg_hours' => 0, 'cnt' => 0],
                'disapproved'    => ['avg_hours' => 0, 'cnt' => 0],
                'for_compliance' => ['avg_hours' => 0, 'cnt' => 0],
            ],
        ],
    ];

    if (!$evaluator_id) return $result;

    // ---- Status stats ----
    [$fConds, $fParams, $fTypes] = evaluator_filter_sql($filters, 'r', 'c', 'a.updated_at');
    $where = "a.current_evaluator_id = ?" . ($fConds ? ' AND ' . implode(' AND ', $fConds) : '');
    $stmt = $database->prepare("
        SELECT a.status, COUNT(*) as cnt
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients c ON a.client_id = c.cid
        WHERE $where
        GROUP BY a.status
    ");
    $stmt->bind_param('i' . $fTypes, ...array_merge([$evaluator_id], $fParams));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = $row['status'];
        $cnt = (int)$row['cnt'];
        if (in_array($key, ['assigned', 'assign', 'pending'], true)) {
            $result['stats']['assign'] += $cnt;
        } elseif (array_key_exists($key, $result['stats'])) {
            $result['stats'][$key] += $cnt;
        }
        $result['stats']['total'] += $cnt;
    }
    $stmt->close();

    // ---- Available (unclaimed) pool ----
    [$fConds, $fParams, $fTypes] = evaluator_filter_sql($filters, 'r', 'c', 'a.submitted_at');
    $where = "a.handling_party IS NULL AND a.status IN ('pending','assign','assigned','resubmitted')"
        . ($fConds ? ' AND ' . implode(' AND ', $fConds) : '');
    $stmt = $database->prepare("
        SELECT COUNT(*) AS cnt
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients c ON a.client_id = c.cid
        WHERE $where
    ");
    if ($fTypes !== '') {
        $stmt->bind_param($fTypes, ...$fParams);
    }
    $stmt->execute();
    $result['available_count'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    // ---- My assigned applications list ----
    [$fConds, $fParams, $fTypes] = evaluator_filter_sql($filters, 'r', 'c', 'a.updated_at');
    $where = "a.current_evaluator_id = ?" . ($fConds ? ' AND ' . implode(' AND ', $fConds) : '');
    $stmt = $database->prepare("
        SELECT a.id, a.status, a.updated_at, r.rsf_number, r.position_title,
               r.worksite_country AS country,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name,
               c.profile_picture AS applicant_picture,
               c.province AS applicant_province
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients c ON a.client_id = c.cid
        WHERE $where
        ORDER BY a.updated_at DESC
        LIMIT 10
    ");
    $stmt->bind_param('i' . $fTypes, ...array_merge([$evaluator_id], $fParams));
    $stmt->execute();
    $result['my_applications'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ---- Applications by country ----
    [$fConds, $fParams, $fTypes] = evaluator_filter_sql($filters, 'r', 'c', 'a.updated_at');
    $where = "a.current_evaluator_id = ?" . ($fConds ? ' AND ' . implode(' AND ', $fConds) : '');
    $stmt = $database->prepare("
        SELECT r.worksite_country AS country,
               COUNT(DISTINCT a.id) as total_reviewed,
               COUNT(CASE WHEN e.decision = 'approved' THEN 1 END) as approved
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients c ON a.client_id = c.cid
        LEFT JOIN evaluations e ON e.application_id = a.id AND e.evaluator_id = ?
        WHERE $where
        GROUP BY r.worksite_country
        ORDER BY total_reviewed DESC
        LIMIT 6
    ");
    $stmt->bind_param('ii' . $fTypes, ...array_merge([$evaluator_id, $evaluator_id], $fParams));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $result['country_data'][] = [
            'country'  => $row['country'],
            'total'    => (int)$row['total_reviewed'],
            'approved' => (int)$row['approved'],
        ];
    }
    $stmt->close();
    if (empty($result['country_data'])) {
        $result['country_data'] = [
            ['country' => 'Taiwan',    'total' => 12, 'approved' => 8],
            ['country' => 'Japan',     'total' => 9,  'approved' => 5],
            ['country' => 'Germany',   'total' => 6,  'approved' => 4],
            ['country' => 'Singapore', 'total' => 5,  'approved' => 2],
        ];
    }

    // ---- Evaluation trend (last 6 months; month filter intentionally skipped) ----
    $months = [];
    for ($i = 5; $i >= 0; $i--) $months[] = date('Y-m', strtotime("-$i months"));
    $trend_map = array_fill_keys($months, ['approved' => 0, 'disapproved' => 0, 'for_compliance' => 0]);

    [$fConds, $fParams, $fTypes] = evaluator_filter_sql($filters, 'r', 'c', '');
    $joinSql = $extraWhere = '';
    if ($fConds) {
        $joinSql = "
            INNER JOIN applications a ON a.id = e.application_id
            INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
            LEFT JOIN clients c ON a.client_id = c.cid
        ";
        $extraWhere = ' AND ' . implode(' AND ', $fConds);
    }
    $stmt = $database->prepare("
        SELECT DATE_FORMAT(e.evaluated_at, '%Y-%m') AS ym, e.decision, COUNT(*) AS cnt
        FROM evaluations e
        $joinSql
        WHERE e.evaluator_id = ?
          AND e.evaluated_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          $extraWhere
        GROUP BY ym, e.decision
    ");
    $stmt->bind_param('i' . $fTypes, ...array_merge([$evaluator_id], $fParams));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ym = $row['ym'];
        $dec = $row['decision'];
        if (isset($trend_map[$ym]) && array_key_exists($dec, $trend_map[$ym])) {
            $trend_map[$ym][$dec] = (int)$row['cnt'];
        }
    }
    $stmt->close();
    foreach ($trend_map as $ym => $vals) {
        $result['eval_trend']['labels'][]      = date('M', strtotime($ym . '-01'));
        $result['eval_trend']['approved'][]    = $vals['approved'];
        $result['eval_trend']['disapproved'][] = $vals['disapproved'];
        $result['eval_trend']['compliance'][]  = $vals['for_compliance'];
    }

    // ---- Turnaround time ----
    [$fConds, $fParams, $fTypes] = evaluator_filter_sql($filters, 'r', 'c', 'e.evaluated_at');
    $joinSql = $extraWhere = '';
    if ($fConds) {
        $joinSql = "
            INNER JOIN applications a ON a.id = e.application_id
            INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
            LEFT JOIN clients c ON a.client_id = c.cid
        ";
        $extraWhere = ' AND ' . implode(' AND ', $fConds);
    }
    $stmt = $database->prepare("
        SELECT e.decision,
               TIMESTAMPDIFF(
                   HOUR,
                   (SELECT MAX(a2.assigned_at) FROM assignments a2
                    WHERE a2.application_id = e.application_id
                      AND a2.evaluator_id   = e.evaluator_id
                      AND a2.assigned_at   <= e.evaluated_at),
                   e.evaluated_at
               ) AS hours_taken
        FROM evaluations e
        $joinSql
        WHERE e.evaluator_id = ?
          AND e.decision IN ('approved','disapproved','for_compliance')
          $extraWhere
    ");
    $stmt->bind_param('i' . $fTypes, ...array_merge([$evaluator_id], $fParams));
    $stmt->execute();
    $res = $stmt->get_result();
    $sums = ['approved' => 0, 'disapproved' => 0, 'for_compliance' => 0];
    $all_hours = [];
    while ($row = $res->fetch_assoc()) {
        $dec = $row['decision'];
        $hours = $row['hours_taken'];
        if ($hours === null || $hours < 0) continue;
        if (isset($sums[$dec])) {
            $sums[$dec] += $hours;
            $result['turnaround']['by_decision'][$dec]['cnt']++;
        }
        $all_hours[] = $hours;
    }
    $stmt->close();
    foreach ($sums as $dec => $sum) {
        $cnt = $result['turnaround']['by_decision'][$dec]['cnt'];
        $result['turnaround']['by_decision'][$dec]['avg_hours'] = $cnt > 0 ? round($sum / $cnt, 1) : 0;
    }
    if (!empty($all_hours)) {
        $result['turnaround']['overall_hours'] = round(array_sum($all_hours) / count($all_hours), 1);
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────
// Evaluator dashboard extras: "Open for Evaluation" queue + workload analytics
// ─────────────────────────────────────────────────────────────
const EVX_INCOMING_LIMIT = 8;
const EVX_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

function evx_fetch_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function evx_clean_filters(array $src): array
{
    $get = static fn(string $k, int $max): string =>
    is_string($src[$k] ?? null) ? mb_substr(trim($src[$k]), 0, $max) : '';
    $month = $get('month', 7);
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = '';
    return [
        'month'    => $month,
        'province' => $get('province', 100),
        'job'      => $get('job', 150),
        'country'  => $get('country', 100),
    ];
}

// Open queue = pending and unclaimed
function evx_open_where(array $f): array
{
    $sql = " WHERE a.status = 'pending' AND a.current_evaluator_id IS NULL";
    $types = '';
    $params = [];
    if ($f['month'] !== '') {
        $sql .= " AND DATE_FORMAT(a.submitted_at, '%Y-%m') = ?";
        $types .= 's';
        $params[] = $f['month'];
    }
    if ($f['province'] !== '') {
        $sql .= " AND c.province = ?";
        $types .= 's';
        $params[] = $f['province'];
    }
    if ($f['job'] !== '') {
        $sql .= " AND r.position_title = ?";
        $types .= 's';
        $params[] = $f['job'];
    }
    if ($f['country'] !== '') {
        $sql .= " AND r.worksite_country = ?";
        $types .= 's';
        $params[] = $f['country'];
    }
    return [$sql, $types, $params];
}

function evx_format_hours(float $hours): string
{
    $h = max(0, (int)floor($hours));
    if ($h < 1)  return '<1h';
    if ($h < 24) return $h . 'h';
    $d = intdiv($h, 24);
    $r = $h % 24;
    return ($d >= 3 || $r === 0) ? $d . 'd' : $d . 'd ' . $r . 'h';
}

// Visual age tiers only (not an SLA)
function evx_age_tier(int $hours): string
{
    if ($hours < 24)  return 'new';
    if ($hours < 72)  return 'normal';
    if ($hours < 168) return 'aging';
    return 'stale';
}

function evx_time_ago(int $mins): string
{
    if ($mins < 1)     return 'Just now';
    if ($mins < 60)    return $mins . 'm ago';
    if ($mins < 1440)  return intdiv($mins, 60) . 'h ago';
    if ($mins < 10080) return intdiv($mins, 1440) . 'd ago';
    return intdiv($mins, 10080) . 'w ago';
}

function evx_incoming_data(mysqli $db, array $f): array
{
    $from = "FROM applications a
             INNER JOIN clients c ON c.cid = a.client_id
             INNER JOIN rsf_job_orders r ON r.id = a.rsf_id";
    [$where, $types, $params] = evx_open_where($f);

    $agg = evx_fetch_all($db, "
        SELECT COUNT(*) AS total,
               COALESCE(SUM(h < 24), 0)              AS b1,
               COALESCE(SUM(h >= 24 AND h < 72), 0)  AS b2,
               COALESCE(SUM(h >= 72 AND h < 168), 0) AS b3,
               COALESCE(SUM(h >= 168), 0)            AS b4,
               COALESCE(AVG(h), 0)                   AS avg_h,
               COALESCE(MAX(h), 0)                   AS max_h
        FROM (SELECT TIMESTAMPDIFF(HOUR, a.submitted_at, NOW()) AS h $from $where) t
    ", $types, $params)[0] ?? [];

    $limit = EVX_INCOMING_LIMIT;
    $rows = evx_fetch_all($db, "
        SELECT a.id, c.first_name, c.last_name, c.province, c.profile_picture,
               r.rsf_number, r.position_title, r.worksite_country, r.deadline,
               DATEDIFF(r.deadline, CURDATE()) AS days_to_deadline,
               TIMESTAMPDIFF(HOUR, a.submitted_at, NOW()) AS waiting_hours,
               (SELECT COUNT(*) FROM rsf_required_documents d
                 WHERE d.rsf_id = a.rsf_id AND d.is_required = 1) AS req_total,
               (SELECT COUNT(DISTINCT ad.required_doc_id) FROM application_documents ad
                 INNER JOIN rsf_required_documents d2
                         ON d2.id = ad.required_doc_id AND d2.is_required = 1
                 WHERE ad.application_id = a.id) AS req_done
        $from $where
        ORDER BY " . sql_deadline_priority('r.deadline') . ", a.submitted_at ASC, a.id ASC
        LIMIT $limit
    ", $types, $params);

    $items = array_map(static function ($r) {
        $h = max(0, (int)$r['waiting_hours']);
        return [
            'id'              => (int)$r['id'],
            'applicant_name'  => trim($r['first_name'] . ' ' . $r['last_name']),
            'province'        => $r['province'],
            'picture_url'     => !empty($r['profile_picture']) ? '../' . $r['profile_picture'] : null,
            'rsf_number'      => $r['rsf_number'],
            'position_title'  => $r['position_title'],
            'country'         => $r['worksite_country'],
            'waiting_hours'   => $h,
            'waiting_display' => evx_format_hours($h),
            'days_to_deadline'=> $r['days_to_deadline'] !== null ? (int) $r['days_to_deadline'] : null,
            'closing_soon'    => $r['days_to_deadline'] !== null && (int) $r['days_to_deadline'] <= JOB_CLOSING_SOON_DAYS,
            'tier'            => evx_age_tier($h),
            'docs_total'      => (int)$r['req_total'],
            'docs_done'       => (int)$r['req_done'],
        ];
    }, $rows);

    $dest = evx_fetch_all($db, "
        SELECT COALESCE(NULLIF(r.worksite_country, ''), 'Unspecified') AS country, COUNT(*) AS cnt
        $from $where
        GROUP BY country
        ORDER BY cnt DESC, country ASC
        LIMIT 5
    ", $types, $params);

    $total = (int)($agg['total'] ?? 0);
    return [
        'total'           => $total,
        'buckets'         => [(int)($agg['b1'] ?? 0), (int)($agg['b2'] ?? 0), (int)($agg['b3'] ?? 0), (int)($agg['b4'] ?? 0)],
        'avg_display'     => $total ? evx_format_hours((float)$agg['avg_h']) : '—',
        'oldest_display'  => $total ? evx_format_hours((float)$agg['max_h']) : '—',
        'destinations'    => array_map(static fn($d) => ['country' => $d['country'], 'cnt' => (int)$d['cnt']], $dest),
        'items'           => $items,
        'filtered'        => (bool)array_filter($f),
    ];
}

function evx_workload_data(mysqli $db, int $evaluator_id): array
{
    $out = [
        'active_tasks' => 0,
        'with_admin' => 0,
        'approved' => 0,
        'disapproved' => 0,
        'decided_today' => 0,
        'decided_week' => 0,
        'approval_rate' => null
    ];
    if ($evaluator_id <= 0) return $out;

    $w = evx_fetch_all($db, "
        SELECT COALESCE(SUM(handling_party = 'evaluator'), 0) AS active_tasks,
               COALESCE(SUM(handling_party = 'admin' AND status IN ('for_compliance','resubmitted')), 0) AS with_admin
        FROM applications
        WHERE current_evaluator_id = ?
    ", 'i', [$evaluator_id])[0] ?? [];

    // Evaluator's own initial decisions; DISTINCT so compliance loops don't inflate counts
    $d = evx_fetch_all($db, "
        SELECT COUNT(DISTINCT CASE WHEN decision = 'approved'    THEN application_id END) AS approved,
               COUNT(DISTINCT CASE WHEN decision = 'disapproved' THEN application_id END) AS disapproved,
               COUNT(DISTINCT CASE WHEN DATE(evaluated_at) = CURDATE() THEN application_id END) AS today,
               COUNT(DISTINCT CASE WHEN evaluated_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN application_id END) AS week
        FROM evaluations
        WHERE evaluator_id = ? AND evaluation_type = 'initial'
          AND decision IN ('approved','disapproved')
    ", 'i', [$evaluator_id])[0] ?? [];

    $out['active_tasks']  = (int)($w['active_tasks'] ?? 0);
    $out['with_admin']    = (int)($w['with_admin'] ?? 0);
    $out['approved']      = (int)($d['approved'] ?? 0);
    $out['disapproved']   = (int)($d['disapproved'] ?? 0);
    $out['decided_today'] = (int)($d['today'] ?? 0);
    $out['decided_week']  = (int)($d['week'] ?? 0);
    $decided = $out['approved'] + $out['disapproved'];
    $out['approval_rate'] = $decided > 0 ? (int)round($out['approved'] / $decided * 100) : null;
    return $out;
}

function evx_attention_data(mysqli $db, int $evaluator_id): array
{
    $out = ['total_active' => 0, 'avg_completion' => null, 'items' => []];
    if ($evaluator_id <= 0) return $out;

    $rows = evx_fetch_all($db, "
        SELECT a.id,
               CONCAT_WS(' ', c.first_name, c.last_name) AS applicant_name,
               r.rsf_number, r.position_title,
               TIMESTAMPDIFF(HOUR, COALESCE(a.locked_at, a.updated_at), NOW()) AS held_hours,
               (SELECT COUNT(*) FROM rsf_required_documents d
                 WHERE d.rsf_id = a.rsf_id AND d.is_required = 1) AS req_total,
               (SELECT COUNT(DISTINCT ad.required_doc_id) FROM application_documents ad
                 INNER JOIN rsf_required_documents d2
                         ON d2.id = ad.required_doc_id AND d2.is_required = 1
                 WHERE ad.application_id = a.id) AS req_done,
               (SELECT COUNT(*) FROM application_documents ad2
                 WHERE ad2.application_id = a.id) AS uploaded_total
        FROM applications a
        INNER JOIN clients c ON c.cid = a.client_id
        INNER JOIN rsf_job_orders r ON r.id = a.rsf_id
        WHERE a.current_evaluator_id = ? AND a.handling_party = 'evaluator'
    ", 'i', [$evaluator_id]);

    $items = [];
    $sum = 0;
    $n = 0;
    foreach ($rows as $r) {
        $total = (int)$r['req_total'];
        $done  = min((int)$r['req_done'], $total);
        $pct   = $total > 0 ? (int)round($done / $total * 100) : null;
        if ($pct !== null) {
            $sum += $pct;
            $n++;
        }
        $items[] = [
            'id' => (int)$r['id'],
            'applicant_name' => $r['applicant_name'],
            'rsf_number' => $r['rsf_number'],
            'position_title' => $r['position_title'],
            'req_total' => $total,
            'req_done' => $done,
            'uploaded_total' => (int)$r['uploaded_total'],
            'held_display' => evx_format_hours(max(0, (int)$r['held_hours'])),
            'held_hours' => (int)$r['held_hours'],
            'pct' => $pct,
        ];
    }
    // Most incomplete first, then longest held
    usort($items, static function ($x, $y) {
        $px = $x['pct'] ?? 101;
        $py = $y['pct'] ?? 101;
        return ($px <=> $py) ?: ($y['held_hours'] <=> $x['held_hours']);
    });

    $out['total_active']   = count($items);
    $out['avg_completion'] = $n > 0 ? (int)round($sum / $n) : null;
    $out['items']          = array_slice($items, 0, 6);
    return $out;
}

function evx_activity_meta(?string $from, ?string $to): array
{
    if ($from === 'pending' && $to === 'under_evaluation') {
        return ['Claimed application', 'fa-hand', 'bg-blue-50 text-blue-500'];
    }
    return match ($to) {
        'approved'         => ['Approved application',    'fa-circle-check',         'bg-emerald-50 text-emerald-600'],
        'disapproved'      => ['Disapproved application', 'fa-circle-xmark',         'bg-red-100 text-red-800'],
        'for_compliance'   => ['Sent for compliance',     'fa-triangle-exclamation', 'bg-amber-50 text-amber-600'],
        'under_evaluation' => ['Back under evaluation',   'fa-magnifying-glass',     'bg-indigo-50 text-indigo-600'],
        default            => ['Status updated',          'fa-rotate',               'bg-slate-100 text-slate-600'],
    };
}

function evx_activity_data(mysqli $db, string $email, int $limit = 8): array
{
    $rows = evx_fetch_all($db, "
        SELECT sh.application_id, sh.from_status, sh.to_status, sh.reason,
               TIMESTAMPDIFF(MINUTE, sh.changed_at, NOW()) AS mins_ago,
               CONCAT_WS(' ', c.first_name, c.last_name) AS applicant_name,
               r.position_title
        FROM status_history sh
        INNER JOIN applications a ON a.id = sh.application_id
        INNER JOIN clients c ON c.cid = a.client_id
        INNER JOIN rsf_job_orders r ON r.id = a.rsf_id
        WHERE sh.changed_by = ?
        ORDER BY sh.changed_at DESC, sh.id DESC
        LIMIT $limit
    ", 's', [$email]);

    return array_map(static function ($r) {
        [$label, $icon, $tone] = evx_activity_meta($r['from_status'], $r['to_status']);
        return [
            'application_id' => (int)$r['application_id'],
            'label' => $label,
            'icon' => $icon,
            'tone' => $tone,
            'applicant_name' => $r['applicant_name'],
            'position_title' => $r['position_title'],
            'ago' => evx_time_ago(max(0, (int)$r['mins_ago'])),
        ];
    }, $rows);
}

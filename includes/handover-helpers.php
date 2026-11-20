<?php
// includes/handover-helpers.php
// Admin-side leave/handover helpers. Reuses the functions already defined in
// app-helpers.php (isEvaluatorAvailable, getOpenCasesForEvaluator,
// evaluator_open_status_sql, handoverApplication, ...). Nothing is redefined here.

require_once __DIR__ . '/app-helpers.php';

function handover_get_evaluator(mysqli $db, int $evaluator_id): ?array
{
    $stmt = $db->prepare("SELECT e.eid, e.full_name, e.email, w.is_active
                          FROM evaluators e JOIN webuser w ON w.email = e.email
                          WHERE e.eid = ? LIMIT 1");
    $stmt->bind_param('i', $evaluator_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Cases a request covers: all open cases, or the still-pending selected items.
// Both branches return the same columns (id, status, handling_party, applicant_name, ...).
function handover_request_cases(mysqli $db, array $req): array
{
    $eid = (int)$req['evaluator_id'];
    if ($req['scope'] === 'all') {
        return getOpenCasesForEvaluator($db, $eid);
    }
    $rid = (int)$req['id'];
    $stmt = $db->prepare("SELECT a.id, a.status, a.handling_party, r.position_title, r.rsf_number,
                                 CONCAT(c.first_name, ' ', c.last_name) AS applicant_name
                          FROM reassignment_request_items i
                          JOIN applications a ON a.id = i.application_id
                          JOIN rsf_job_orders r ON r.id = a.rsf_id
                          LEFT JOIN clients c ON c.cid = a.client_id
                          WHERE i.request_id = ? AND i.outcome = 'pending'
                            AND a.current_evaluator_id = ?
                            AND a.status IN (" . evaluator_open_status_sql() . ")
                          ORDER BY a.updated_at ASC");
    $stmt->bind_param('ii', $rid, $eid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// For "all" requests: make sure every current open case has an item row.
function handover_sync_items(mysqli $db, int $request_id, int $evaluator_id): void
{
    $stmt = $db->prepare("INSERT INTO reassignment_request_items (request_id, application_id)
                          SELECT ?, a.id FROM applications a
                          WHERE a.current_evaluator_id = ?
                            AND a.status IN (" . evaluator_open_status_sql() . ")
                            AND NOT EXISTS (SELECT 1 FROM reassignment_request_items i
                                            WHERE i.request_id = ? AND i.application_id = a.id)");
    $stmt->bind_param('iii', $request_id, $evaluator_id, $request_id);
    $stmt->execute();
    $stmt->close();
}

// '' -> null (open-ended); valid Y-m-d today or later -> string; anything else -> false.
function handover_parse_return_date(mixed $value): string|null|false
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value || $value < date('Y-m-d')) {
        return false;
    }
    return $value;
}

// Creates an active leave record. Returns false if the evaluator is already unavailable.
function handover_create_unavailability(mysqli $db, int $evaluator_id, string $reason_category, ?string $return_date, string $admin_email): bool
{
    if (!isEvaluatorAvailable($db, $evaluator_id)) {
        return false;
    }
    $end_at = $return_date ? $return_date . ' 00:00:00' : null;
    $stmt = $db->prepare("INSERT INTO evaluator_unavailability
                          (evaluator_id, reason_category, start_at, end_at, status, set_by)
                          VALUES (?, ?, NOW(), ?, 'active', ?)");
    $stmt->bind_param('isss', $evaluator_id, $reason_category, $end_at, $admin_email);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Generic notification insert (any usertype, optional application).
function handover_notify(mysqli $db, string $email, string $usertype, ?int $application_id, string $title, string $message, string $type): void
{
    $stmt = $db->prepare("INSERT INTO notifications (user_email, usertype, application_id, title, message, type)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssisss', $email, $usertype, $application_id, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

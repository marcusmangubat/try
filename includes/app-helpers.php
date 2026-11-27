<?php

/**
 * Application workflow helpers
 * Requires: $database (mysqli), included after db.php
 */

/** Same window the admin job-order list labels "Closing soon". */
const JOB_CLOSING_SOON_DAYS = 7;

/**
 * ORDER BY prefix. Jobs whose deadline is today, already past, or within
 * JOB_CLOSING_SOON_DAYS sort first (soonest date first). Everything else
 * keeps the order the caller appends.
 *
 * $deadlineCol is a trusted SQL column name, not user input.
 * $onlyWhen limits the boost (for example to applications that are still open)
 * so a finished case does not jump the list only because its job date is old.
 */
function sql_deadline_priority(string $deadlineCol, ?string $onlyWhen = null): string
{
    $days = (int) JOB_CLOSING_SOON_DAYS;
    $window = "{$deadlineCol} IS NOT NULL AND {$deadlineCol} <= DATE_ADD(CURDATE(), INTERVAL {$days} DAY)";
    if ($onlyWhen !== null && $onlyWhen !== '') {
        $window = "({$onlyWhen}) AND {$window}";
    }
    return "CASE WHEN {$window} THEN 0 ELSE 1 END ASC, CASE WHEN {$window} THEN {$deadlineCol} END ASC";
}


/**
 * Urgency badge for a job order's deadline (HTML). Shared by the admin and
 * evaluator pages. Hidden for finished cases (approved / disapproved / draft).
 */
function deadline_badge(?string $deadline, string $status): string
{
    if (!$deadline || in_array($status, ['approved', 'disapproved', 'draft'], true)) return '';
    $days = (int) floor((strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400);

    if ($days < 0) {
        $ago = abs($days);
        $txt = $ago === 1 ? 'Deadline passed 1 day ago' : "Deadline passed {$ago} days ago";
        $cls = 'bg-red-100 text-red-700 [[data-theme=dark]_&]:bg-red-500/15 [[data-theme=dark]_&]:text-red-300';
        $ico = 'fa-circle-exclamation';
    } elseif ($days <= 3) {
        $txt = $days === 0 ? 'Due today' : "Due in {$days} day" . ($days > 1 ? 's' : '');
        $cls = 'bg-red-100 text-red-700 [[data-theme=dark]_&]:bg-red-500/15 [[data-theme=dark]_&]:text-red-300';
        $ico = 'fa-fire';
    } elseif ($days <= 7) {
        $txt = "Due in {$days} days";
        $cls = 'bg-amber-100 text-amber-800 [[data-theme=dark]_&]:bg-amber-500/15 [[data-theme=dark]_&]:text-amber-300';
        $ico = 'fa-clock';
    } else {
        $txt = "{$days} days left";
        $cls = 'bg-slate-100 text-slate-600 [[data-theme=dark]_&]:bg-slate-500/15 [[data-theme=dark]_&]:text-slate-300';
        $ico = 'fa-calendar-day';
    }
    $title = htmlspecialchars('Job order deadline: ' . date('M d, Y', strtotime($deadline)), ENT_QUOTES);
    return "<span title=\"{$title}\" class=\"mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {$cls}\"><i class=\"fas {$ico} text-[9px]\"></i>" . htmlspecialchars($txt) . "</span>";
}

/**
 * Whether the client may change answers, documents, or use resubmit (server-side gate).
 * Editable: unassign (submitted, not yet assigned), for_compliance, resubmitted, and in-progress pending.
 * Legacy DB values unassigned / assigned are accepted for compatibility.
 *
 * @param string|null $status applications.status
 */
function canEditApplication(?string $status): bool
{
    $s = strtolower(trim((string) $status));
    if ($s === 'unassigned') {
        $s = 'unassign';
    }
    $editable = ['unassign', 'for_compliance', 'resubmitted', 'pending'];
    return in_array($s, $editable, true);
}

/**
 * Update application status and log to status_history
 * @param mysqli $db
 * @param int $application_id
 * @param string $new_status
 * @param string $changed_by (webuser.email)
 * @param string|null $reason
 * @return bool
 */
function application_status_change(mysqli $db, int $application_id, string $new_status, string $changed_by, ?string $reason = null): bool
{
    $application_id = (int) $application_id;
    $new_status = trim($new_status);
    $changed_by = trim($changed_by);
    if (!$application_id || !$new_status || !$changed_by) return false;

    // Get current status
    $stmt = $db->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $from_status = $row['status'];
    if ($from_status === $new_status) return true; // No change

    $takesSlot = ($new_status === 'approved' && $from_status !== 'approved');
    $rsf_id = 0;

    // Reserve a slot first. The WHERE clause makes this atomic: two simultaneous
    // approvals cannot both take the last slot. NULL slots_available = no limit.
    if ($takesSlot) {
        $stmt = $db->prepare('
            SELECT r.id, r.slots_available
            FROM applications a
            JOIN rsf_job_orders r ON r.id = a.rsf_id
            WHERE a.id = ? LIMIT 1
        ');
        $stmt->bind_param('i', $application_id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($job) {
            $rsf_id = (int) $job['id'];
            if ($job['slots_available'] !== null) {
                $stmt = $db->prepare('UPDATE rsf_job_orders SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0');
                $stmt->bind_param('i', $rsf_id);
                $stmt->execute();
                $reserved = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$reserved) return false; // no slots left
            }
        }
    }

    // Update applications
    $stmt = $db->prepare("UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $new_status, $application_id);
    if (!$stmt->execute()) {
        $stmt->close();
        // Give the reserved slot back
        if ($takesSlot && $rsf_id) {
            $stmt = $db->prepare('UPDATE rsf_job_orders SET slots_available = slots_available + 1 WHERE id = ? AND slots_available IS NOT NULL');
            $stmt->bind_param('i', $rsf_id);
            $stmt->execute();
            $stmt->close();
        }
        return false;
    }
    $stmt->close();

    if ($takesSlot && $rsf_id) {
        notify_unaccommodated_when_slots_full($db, $rsf_id);
    }

    // Insert status_history
    $stmt = $db->prepare("INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $application_id, $from_status, $new_status, $changed_by, $reason);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Statuses considered "unclaimed" — submitted but no evaluator has started
 * working the case yet. Includes legacy values already used elsewhere in
 * the codebase (canEditApplication(), applications.php stats) for compatibility.
 */
const APPLICATION_CLAIMABLE_STATUSES = ['pending', 'assign', 'assigned', 'resubmitted'];

/**
 * Whether $evaluator_id currently has exclusive control of this application.
 * This is the single source of truth for evaluator-side access control —
 * check this before allowing a view or a POST action, not just the
 * `assignments` table (which is now a historical/audit record, not the lock).
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $evaluator_id
 * @return bool
 */
function evaluatorOwnsApplication(mysqli $db, int $application_id, int $evaluator_id): bool
{
    $application_id = (int) $application_id;
    $evaluator_id = (int) $evaluator_id;
    if (!$application_id || !$evaluator_id) return false;

    $stmt = $db->prepare("
        SELECT 1 FROM applications
        WHERE id = ? AND current_evaluator_id = ? AND handling_party = 'evaluator'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $owns = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $owns;
}

/**
 * Atomically claim an unclaimed application for one evaluator.
 * Locks the row with SELECT ... FOR UPDATE inside a transaction so two
 * evaluators opening the same application at the same instant cannot both
 * win the claim — the second one is told it was already taken.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $evaluator_id
 * @param string $evaluator_email
 * @return array{ok:bool, reason?:string, holder_name?:string|null, status?:string, already_owned?:bool}
 */
function claimApplication(mysqli $db, int $application_id, int $evaluator_id, string $evaluator_email): array
{
    $application_id = (int) $application_id;
    $evaluator_id = (int) $evaluator_id;
    if (!$application_id || !$evaluator_id) {
        return ['ok' => false, 'reason' => 'invalid'];
    }

    $db->begin_transaction();

    $stmt = $db->prepare("
        SELECT a.status, a.handling_party, a.current_evaluator_id, e.full_name AS holder_name
        FROM applications a
        LEFT JOIN evaluators e ON a.current_evaluator_id = e.eid
        WHERE a.id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $db->rollback();
        return ['ok' => false, 'reason' => 'not_found'];
    }

    // Already owned by this same evaluator — nothing to do, just confirm.
    if ($row['handling_party'] === 'evaluator' && (int) $row['current_evaluator_id'] === $evaluator_id) {
        $db->commit();
        return ['ok' => true, 'already_owned' => true];
    }

    // A resubmission belongs to the evaluator already on the case. Hand it
    // back without changing the status away from resubmitted.
    if ($row['status'] === 'resubmitted' && (int) $row['current_evaluator_id'] === $evaluator_id) {
        $stmt = $db->prepare("
            UPDATE applications
            SET handling_party = 'evaluator', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $application_id);
        $stmt->execute();
        $stmt->close();
        $db->commit();
        return ['ok' => true, 'already_owned' => true];
    }

    $isClaimable = $row['handling_party'] === null
        && in_array($row['status'], APPLICATION_CLAIMABLE_STATUSES, true);

    if (!$isClaimable) {
        $db->rollback();
        return [
            'ok' => false,
            'reason' => 'already_claimed',
            'holder_name' => $row['holder_name'],
            'status' => $row['status'],
        ];
    }

    // On leave or deactivated evaluators cannot pick up new work.
    if (!isEvaluatorAvailable($db, $evaluator_id)) {
        $db->rollback();
        return ['ok' => false, 'reason' => 'unavailable'];
    }

    $from_status = $row['status'];
    // A resubmission stays Resubmitted so it remains on that evaluator tab.
    $next_status = $from_status === 'resubmitted' ? 'resubmitted' : 'under_evaluation';

    $stmt = $db->prepare("
        UPDATE applications
        SET status = ?,
            current_evaluator_id = ?,
            handling_party = 'evaluator',
            locked_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sii", $next_status, $evaluator_id, $application_id);
    $stmt->execute();
    $stmt->close();

    if ($from_status !== $next_status) {
        $stmt = $db->prepare("
            INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
            VALUES (?, ?, 'under_evaluation', ?, 'Evaluator claimed application')
        ");
        $stmt->bind_param("iss", $application_id, $from_status, $evaluator_email);
        $stmt->execute();
        $stmt->close();
    }

    // Close out any stale active assignment left by a different evaluator.
    $stmt = $db->prepare("
        UPDATE assignments SET status = 'reassigned'
        WHERE application_id = ? AND status = 'active' AND evaluator_id != ?
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("SELECT 1 FROM assignments WHERE application_id = ? AND evaluator_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $hasActive = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$hasActive) {
        $stmt = $db->prepare("INSERT INTO assignments (application_id, evaluator_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
        $stmt->bind_param("ii", $application_id, $evaluator_id);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();
    return ['ok' => true];
}

/**
 * Evaluator sends the case to Admin for compliance follow-up.
 * current_evaluator_id is deliberately left unchanged so Admin can route
 * the case back to this exact evaluator later.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $evaluator_id
 * @param string $evaluator_email
 * @param string|null $reason
 * @return bool
 */
function sendToCompliance(mysqli $db, int $application_id, int $evaluator_id, string $evaluator_email, ?string $reason = null): bool
{
    $application_id = (int) $application_id;
    $evaluator_id = (int) $evaluator_id;
    if (!$application_id || !$evaluator_id) return false;

    $stmt = $db->prepare("
        SELECT status FROM applications
        WHERE id = ? AND current_evaluator_id = ? AND handling_party = 'evaluator'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    $from_status = $row['status'];

    $stmt = $db->prepare("
        UPDATE applications
        SET status = 'for_compliance', handling_party = 'admin', updated_at = NOW()
        WHERE id = ? AND current_evaluator_id = ? AND handling_party = 'evaluator'
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ok) return false;

    $stmt = $db->prepare("
        INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
        VALUES (?, ?, 'for_compliance', ?, ?)
    ");
    $stmt->bind_param("isss", $application_id, $from_status, $evaluator_email, $reason);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Send a resubmitted application straight back to the evaluator on record.
 * Status stays resubmitted. When $application_id is omitted, every matching
 * case is released (covers applications resubmitted before this handoff).
 *
 * @param mysqli $db
 * @param int|null $application_id
 */
function releaseResubmissionsToEvaluator($db, $application_id = null)
{
    // Only hand a resubmission back to an evaluator who can actually work it.
    // Otherwise it stays unowned (handling_party empty) and shows in the shared
    // Open for Evaluation queue and in the admin's Resubmitted list.
    $sql = "
        UPDATE applications a
        JOIN evaluators e ON e.eid = a.current_evaluator_id
        JOIN webuser w ON w.email = e.email
        SET a.handling_party = 'evaluator'
        WHERE a.status = 'resubmitted'
          AND (a.handling_party IS NULL OR a.handling_party <> 'evaluator')
          AND w.is_active = 1
          AND NOT " . SQL_EVALUATOR_ON_LEAVE;

    if ($application_id !== null) {
        $application_id = (int) $application_id;
        if (!$application_id) return;
        $stmt = $db->prepare($sql . " AND a.id = ?");
        $stmt->bind_param('i', $application_id);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $db->query($sql);
}

/**
 * ADMIN ACTION 2: Admin evaluates the case directly instead of sending it
 * back to the evaluator. Decision is recorded under the Admin's identity.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $admin_id
 * @param string $admin_email
 * @param string $decision 'approved' or 'disapproved'
 * @param string|null $comments
 * @return bool
 */
function adminEvaluateDirectly(mysqli $db, int $application_id, int $admin_id, string $admin_email, string $decision, ?string $comments = null): bool
{
    $application_id = (int) $application_id;
    $admin_id = (int) $admin_id;
    $decision = trim((string) $decision);
    if (!$application_id || !$admin_id || !in_array($decision, ['approved', 'disapproved'], true)) {
        return false;
    }

    $stmt = $db->prepare("SELECT status, handling_party FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['status'] !== 'resubmitted') {
        return false;
    }

    $stmt = $db->prepare("
        INSERT INTO evaluations (application_id, admin_id, evaluation_type, decision, comments)
        VALUES (?, ?, 'admin', ?, ?)
    ");
    $stmt->bind_param("iiss", $application_id, $admin_id, $decision, $comments);
    $stmt->execute();
    $stmt->close();

    if (!application_status_change($db, $application_id, $decision, $admin_email, "Admin decision: $decision" . ($comments ? " — $comments" : ''))) {
        return false;
    }

    if ($comments !== null) {
        $stmt = $db->prepare("UPDATE applications SET notes = ? WHERE id = ?");
        $stmt->bind_param("si", $comments, $application_id);
        $stmt->execute();
        $stmt->close();
    }

    // Case is resolved — release the lock and close out the assignment record.
    $stmt = $db->prepare("UPDATE applications SET handling_party = NULL WHERE id = ?");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE assignments SET status = 'completed' WHERE application_id = ? AND status = 'active'");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * ADMIN ACTION (supporting): reassign a case to a different evaluator —
 * covers "...until the evaluation process is completed or reassigned."
 * Works from for_compliance or under_evaluation.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $new_evaluator_id
 * @param string $admin_email
 * @return bool
 */
function adminReassignApplication(mysqli $db, int $application_id, int $new_evaluator_id, string $admin_email): bool
{
    $application_id = (int) $application_id;
    $new_evaluator_id = (int) $new_evaluator_id;
    if (!$application_id || !$new_evaluator_id) return false;

    $stmt = $db->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['status'] !== 'resubmitted') return false;
    $from_status = $row['status'];

    $stmt = $db->prepare("
        UPDATE applications
        SET status = 'under_evaluation', current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $new_evaluator_id, $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE assignments SET status = 'reassigned' WHERE application_id = ? AND status = 'active'");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO assignments (application_id, evaluator_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
    $stmt->bind_param("ii", $application_id, $new_evaluator_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
        VALUES (?, ?, 'under_evaluation', ?, 'Reassigned by admin')
    ");
    $stmt->bind_param("iss", $application_id, $from_status, $admin_email);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Create a client notification for a specific application.
 * Works with notifications table schema:
 * (user_email, usertype, application_id, title, message, type, is_read, read_at, created_at)
 *
 * @param mysqli $db
 * @param int $application_id
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @return bool
 */
function notify_client_for_application(mysqli $db, int $application_id, string $title, string $message, ?string $type = 'application_status'): bool
{
    $application_id = (int) $application_id;
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if (!$application_id || $title === '' || $message === '') return false;

    $stmt = $db->prepare("
        SELECT c.email
        FROM applications a
        JOIN clients c ON a.client_id = c.cid
        WHERE a.id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['email'])) return false;

    $user_email = (string) $row['email'];
    $usertype = 'client';
    $stmt = $db->prepare("
        INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * When a job order has no slots left, tell every submitted applicant who was
 * not approved. Application status is left unchanged. Each applicant is
 * notified once (in-app + email); a later fill of the same job order does
 * not send the notice again.
 */
function notify_unaccommodated_when_slots_full(mysqli $db, int $rsf_id): void
{
    $rsf_id = (int) $rsf_id;
    if ($rsf_id <= 0) {
        return;
    }

    try {
        $stmt = $db->prepare("
            SELECT rsf_number, position_title, employer_name, worksite_country, slots_available
            FROM rsf_job_orders
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $rsf_id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // NULL means the job order does not track a slot limit.
        if (!$job || $job['slots_available'] === null || (int) $job['slots_available'] > 0) {
            return;
        }

        $stmt = $db->prepare("
            SELECT a.id, a.submitted_at, c.email, c.first_name, c.middle_name, c.last_name, c.suffix
            FROM applications a
            JOIN clients c ON a.client_id = c.cid
            WHERE a.rsf_id = ?
              AND a.status NOT IN ('draft', 'approved', 'disapproved')
              AND c.email IS NOT NULL AND c.email <> ''
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $rsf_id);
        $stmt->execute();
        $applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$applicants) {
            return;
        }

        $position = trim((string) ($job['position_title'] ?? ''));
        $employer = trim((string) ($job['employer_name'] ?? ''));
        $country  = trim((string) ($job['worksite_country'] ?? ''));
        $where = $position !== '' ? $position : 'the position you applied for';
        if ($employer !== '') {
            $where .= ' at ' . $employer;
        }
        if ($country !== '') {
            $where .= ' (' . $country . ')';
        }

        $title = 'Job Order Slots Fully Occupied';
        $message = 'Unfortunately, the job order you applied for (' . $where . ') has reached its maximum slot capacity. '
            . 'Your application cannot be accommodated because all available slots are now occupied. '
            . 'Your application record and its current status are unchanged. '
            . 'You may wait for new job opportunities or apply to other available positions.';

        $dup = $db->prepare("
            SELECT 1 FROM notifications
            WHERE application_id = ? AND usertype = 'client' AND type = 'slots_full'
            LIMIT 1
        ");
        if (!$dup) {
            return;
        }

        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/mail_templates.php';

        foreach ($applicants as $app) {
            $appId = (int) $app['id'];
            $dup->bind_param('i', $appId);
            $dup->execute();
            $dupResult = $dup->get_result();
            $already = $dupResult ? $dupResult->fetch_row() : null;
            if ($dupResult) {
                $dupResult->free();
            }
            if ($already) {
                continue;
            }

            if (!notify_client_for_application($db, $appId, $title, $message, 'slots_full')) {
                continue;
            }

            $fullName = trim(
                ($app['first_name'] ?? '') . ' ' .
                    (!empty($app['middle_name']) ? $app['middle_name'] . ' ' : '') .
                    ($app['last_name'] ?? '') .
                    (!empty($app['suffix']) ? ' ' . $app['suffix'] : '')
            );
            $submittedAt = $app['submitted_at'] ?: date('Y-m-d H:i:s');
            $refNo = 'APP-' . date('Y', strtotime($submittedAt)) . '-' . str_pad((string) $appId, 6, '0', STR_PAD_LEFT);

            $html = dmw_email_body_slots_full([
                'applicant_name'   => $fullName !== '' ? $fullName : 'Applicant',
                'ref_no'           => $refNo,
                'position_title'   => $position,
                'employer_name'    => $employer,
                'worksite_country' => $country,
                'rsf_number'       => $job['rsf_number'] ?? '',
                'submitted_at'     => $submittedAt,
            ]);
            send_html_mail(
                $db,
                (string) $app['email'],
                $fullName !== '' ? $fullName : null,
                "DMW AMS — Job Order Slots Fully Occupied ({$refNo})",
                $html
            );
        }
        $dup->close();
    } catch (Throwable $e) {
        error_log('DMW slots-full notice failed for job order #' . $rsf_id . ': ' . $e->getMessage());
    }
}

/**
 * Create an evaluator notification for a specific application.
 * Notifies the assigned evaluator about application updates.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @return bool
 */
function notify_evaluator_for_application(mysqli $db, int $application_id, string $title, string $message, ?string $type = 'application_update'): bool
{
    $application_id = (int) $application_id;
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if (!$application_id || $title === '' || $message === '') return false;

    // Get the assigned evaluator's email
    $stmt = $db->prepare("
        SELECT e.email
        FROM assignments a
        JOIN evaluators e ON a.evaluator_id = e.eid
        WHERE a.application_id = ? AND a.status = 'active'
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['email'])) return false;

    $user_email = (string) $row['email'];
    $usertype = 'evaluator';
    $stmt = $db->prepare("
        INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Create an admin notification for system-wide events.
 * Notifies all admins about important system events.
 *
 * @param mysqli $db
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @param int|null $application_id Optional application context
 * @return bool
 */
function notify_admins(mysqli $db, string $title, string $message, ?string $type = 'system', ?int $application_id = null): bool
{
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if ($title === '' || $message === '') return false;

    // Get all admin emails
    $stmt = $db->prepare("SELECT email FROM admins");
    if (!$stmt) return false;
    $stmt->execute();
    $result = $stmt->get_result();
    $admins = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($admins)) return false;

    $usertype = 'admin';
    $application_id = $application_id ? (int) $application_id : null;

    $success = true;
    foreach ($admins as $admin) {
        $user_email = (string) $admin['email'];
        $stmt = $db->prepare("
            INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        if (!$stmt) {
            $success = false;
            continue;
        }
        $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) $success = false;
    }

    return $success;
}

/**
 * Get an applicant's display name for a given application, for use in
 * evaluator/admin-facing notification messages.
 *
 * @param mysqli $db
 * @param int $application_id
 * @return string
 */
function get_applicant_name(mysqli $db, int $application_id): string
{
    $application_id = (int) $application_id;
    $name = 'the applicant';
    $stmt = $db->prepare("
        SELECT CONCAT(c.first_name, ' ', c.last_name) AS applicant_name
        FROM applications a
        JOIN clients c ON a.client_id = c.cid
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($row['applicant_name'])) {
        $name = trim($row['applicant_name']);
    }
    return $name;
}

/**
 * Create an evaluator notification for system-wide events.
 * Notifies ALL evaluators about important system events (e.g. a brand new
 * application entering the pool, before anyone has claimed it). Use
 * notify_evaluator_for_application() instead when a single evaluator
 * already owns the case via an active assignment.
 *
 * @param mysqli $db
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @param int|null $application_id Optional application context
 * @return bool
 */
function notify_evaluators(mysqli $db, string $title, string $message, ?string $type = 'system', ?int $application_id = null): bool
{
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if ($title === '' || $message === '') return false;

    // Get emails of evaluators who are active and not on leave
    $stmt = $db->prepare("
        SELECT e.email
        FROM evaluators e
        JOIN webuser w ON w.email = e.email
        WHERE w.is_active = 1
          AND NOT " . SQL_EVALUATOR_ON_LEAVE . "
    ");
    if (!$stmt) return false;
    $stmt->execute();
    $result = $stmt->get_result();
    $evaluators = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($evaluators)) return false;

    $usertype = 'evaluator';
    $application_id = $application_id ? (int) $application_id : null;

    $success = true;
    foreach ($evaluators as $evaluator) {
        $user_email = (string) $evaluator['email'];
        $stmt = $db->prepare("
            INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        if (!$stmt) {
            $success = false;
            continue;
        }
        $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) $success = false;
    }

    return $success;
}

/** Pull previously-saved answers for a draft application, keyed by question_id. */
function app_load_saved_answers(mysqli $db, int $app_id): array
{
    $map = [];
    $stmt = $db->prepare("SELECT question_id, answer FROM application_answers WHERE application_id = ?");
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $map[(int) $r['question_id']] = $r['answer'];
    }
    $stmt->close();
    return $map;
}

/** Pull previously-uploaded documents for a draft application, in the same
 *  shape the wizard already uses in $_SESSION['wizard_uploads']. */
function app_load_saved_uploads(mysqli $db, int $app_id): array
{
    $out = [];
    $stmt = $db->prepare("SELECT document_type, required_doc_id, file_name, file_path FROM application_documents WHERE application_id = ?");
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rid = (int) ($r['required_doc_id'] ?? 0);
        $key = $rid > 0 ? 'doc_' . $rid : 'type_' . strtolower((string) $r['document_type']);
        $out[$key] = [
            'file_name'       => $r['file_name'],
            'file_path'       => $r['file_path'],
            'required_doc_id' => $rid,
            'document_type'   => $r['document_type'],
            'document_name'   => $r['document_type'],
        ];
    }
    $stmt->close();
    return $out;
}

/** True once every REQUIRED document slot has a saved upload. */
function app_uploads_complete(array $uploads, array $requiredDocs): bool
{
    foreach ($requiredDocs as $doc) {
        if (empty($doc['is_required'])) continue;
        $key = ((int) ($doc['id'] ?? 0) > 0) ? 'doc_' . (int) $doc['id'] : 'type_' . strtolower((string) ($doc['document_type'] ?? ''));
        if (empty($uploads[$key]['file_path'])) return false;
    }
    return true;
}

/* ═══════════════════════════════════════════════════════════
   EVALUATOR AVAILABILITY & WORKLOAD HANDOVER
   ═══════════════════════════════════════════════════════════ */

/** Statuses that count as an evaluator's "open" cases. */
const EVALUATOR_OPEN_STATUSES = ['assign', 'assigned', 'under_evaluation', 'for_compliance', 'resubmitted'];

/** SQL fragment: evaluator row aliased `e` is currently on an active leave. */
const SQL_EVALUATOR_ON_LEAVE = "
    EXISTS (
        SELECT 1 FROM evaluator_unavailability u
        WHERE u.evaluator_id = e.eid
          AND u.status = 'active'
          AND u.start_at <= NOW()
          AND (u.end_at IS NULL OR u.end_at > NOW())
    )";

function evaluator_open_status_sql(): string
{
    return "'" . implode("','", EVALUATOR_OPEN_STATUSES) . "'";
}

/**
 * Available = account active AND not on an active leave right now.
 */
function isEvaluatorAvailable(mysqli $db, int $evaluator_id): bool
{
    $evaluator_id = (int) $evaluator_id;
    if (!$evaluator_id) return false;

    $stmt = $db->prepare(
        "SELECT 1
         FROM evaluators e
         JOIN webuser w ON w.email = e.email
         WHERE e.eid = ? AND w.is_active = 1
           AND NOT " . SQL_EVALUATOR_ON_LEAVE . "
         LIMIT 1"
    );
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Open cases owned by an evaluator (used by the request form and admin preview).
 */
function getOpenCasesForEvaluator(mysqli $db, int $evaluator_id): array
{
    $evaluator_id = (int) $evaluator_id;
    $stmt = $db->prepare("
        SELECT a.id, a.status, a.handling_party, a.updated_at,
               r.position_title, r.rsf_number,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients c ON a.client_id = c.cid
        WHERE a.current_evaluator_id = ?
          AND a.status IN (" . evaluator_open_status_sql() . ")
        ORDER BY a.updated_at ASC
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Evaluators who can receive cases, least-busy first
 * (tie-break: whoever was assigned a case longest ago).
 */
function getAvailableEvaluatorsWithWorkload(mysqli $db, int $exclude_evaluator_id = 0): array
{
    $exclude = (int) $exclude_evaluator_id;
    $stmt = $db->prepare("
        SELECT e.eid, e.full_name, e.email,
               (SELECT COUNT(*) FROM applications a
                 WHERE a.current_evaluator_id = e.eid
                   AND a.status IN (" . evaluator_open_status_sql() . ")) AS open_cases,
               (SELECT MAX(asg.assigned_at) FROM assignments asg
                 WHERE asg.evaluator_id = e.eid) AS last_assigned
        FROM evaluators e
        JOIN webuser w ON w.email = e.email
        WHERE w.is_active = 1
          AND e.eid <> ?
          AND NOT " . SQL_EVALUATOR_ON_LEAVE . "
        ORDER BY open_cases ASC, last_assigned ASC, e.eid ASC
    ");
    $stmt->bind_param("i", $exclude);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Propose a least-workload split. Running counts are updated after each
 * case so one evaluator doesn't receive the whole batch.
 *
 * @param int[] $application_ids
 * @return array<int,int|null> application_id => proposed evaluator eid (null if nobody is available)
 */
function proposeHandoverSplit(mysqli $db, array $application_ids, int $source_evaluator_id): array
{
    $pool = getAvailableEvaluatorsWithWorkload($db, $source_evaluator_id);
    $plan = [];
    foreach ($application_ids as $app_id) {
        $app_id = (int) $app_id;
        if (!$pool) {
            $plan[$app_id] = null;
            continue;
        }
        $best = 0;
        foreach ($pool as $i => $ev) {
            if ((int) $ev['open_cases'] < (int) $pool[$best]['open_cases']) $best = $i;
        }
        $plan[$app_id] = (int) $pool[$best]['eid'];
        $pool[$best]['open_cases']++;
    }
    return $plan;
}

/**
 * Move ONE open case from one evaluator to another, atomically.
 * Status is preserved (a for_compliance case stays with the admin/applicant
 * flow); only ownership changes. Skips safely if the case changed meanwhile.
 *
 * @return array{ok:bool, reason?:string}
 */
function handoverApplication(
    mysqli $db,
    int $application_id,
    int $from_evaluator_id,
    int $to_evaluator_id,
    string $admin_email,
    string $reason
): array {
    $application_id    = (int) $application_id;
    $from_evaluator_id = (int) $from_evaluator_id;
    $to_evaluator_id   = (int) $to_evaluator_id;
    if (!$application_id || !$from_evaluator_id || !$to_evaluator_id || $from_evaluator_id === $to_evaluator_id) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    if (!isEvaluatorAvailable($db, $to_evaluator_id)) {
        return ['ok' => false, 'reason' => 'target_unavailable'];
    }

    try {
        $db->begin_transaction();

        $stmt = $db->prepare("SELECT status, handling_party, current_evaluator_id FROM applications WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $application_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            !$row
            || (int) $row['current_evaluator_id'] !== $from_evaluator_id
            || !in_array($row['status'], EVALUATOR_OPEN_STATUSES, true)
        ) {
            $db->rollback();
            return ['ok' => false, 'reason' => 'changed'];
        }

        $status = $row['status'];
        // for_compliance stays with Admin; everything else goes to the new evaluator's queue.
        $party = ($status === 'for_compliance') ? $row['handling_party'] : 'evaluator';

        $stmt = $db->prepare("
            UPDATE applications
            SET current_evaluator_id = ?, handling_party = ?, locked_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("isi", $to_evaluator_id, $party, $application_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE assignments SET status = 'reassigned' WHERE application_id = ? AND status = 'active'");
        $stmt->bind_param("i", $application_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO assignments (application_id, evaluator_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
        $stmt->bind_param("ii", $application_id, $to_evaluator_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("
            INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $application_id, $status, $status, $admin_email, $reason);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("SELECT c.email FROM applications a JOIN clients c ON a.client_id = c.cid WHERE a.id = ? LIMIT 1");
        $stmt->bind_param("i", $application_id);
        $stmt->execute();
        $client_email = $stmt->get_result()->fetch_assoc()['email'] ?? null;
        $stmt->close();

        $db->commit();
        return ['ok' => true, 'client_email' => $client_email];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('handoverApplication failed: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
}

/** Notify one evaluator by eid (for summary messages). */
function notify_evaluator_by_id(
    mysqli $db,
    int $evaluator_id,
    string $title,
    string $message,
    string $type = 'application_update',
    ?int $application_id = null
): bool {
    $evaluator_id = (int) $evaluator_id;
    $stmt = $db->prepare("SELECT email FROM evaluators WHERE eid = ? LIMIT 1");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $email = (string) $row['email'];
    $usertype = 'evaluator';
    $application_id = $application_id ? (int) $application_id : null;
    $stmt = $db->prepare("
        INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    $stmt->bind_param("ssisss", $email, $usertype, $application_id, $title, $message, $type);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* ═══════════════════════════════════════════════════════════
   REASSIGNMENT REQUESTS (evaluator side)
   ═══════════════════════════════════════════════════════════ */

const EVALUATOR_LEAVE_REASONS = [
    'emergency'  => 'Personal / family emergency',
    'sick_leave' => 'Sick leave',
    'vacation'   => 'Vacation / planned leave',
    'training'   => 'Training / official duty',
    'other'      => 'Other',
];

/** The evaluator's current active leave row, or null. */
function getActiveLeave(mysqli $db, int $evaluator_id): ?array
{
    $evaluator_id = (int) $evaluator_id;
    $stmt = $db->prepare("
        SELECT id, reason_category, start_at, end_at
        FROM evaluator_unavailability
        WHERE evaluator_id = ? AND status = 'active' AND start_at <= NOW()
          AND (end_at IS NULL OR end_at > NOW())
        ORDER BY start_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** The evaluator's pending reassignment request, or null. */
function getPendingReassignmentRequest(mysqli $db, int $evaluator_id): ?array
{
    $evaluator_id = (int) $evaluator_id;
    $stmt = $db->prepare("
                SELECT r.id, r.scope, r.reason_category, r.reason_other, r.expected_return, r.requested_at,
               (SELECT COUNT(*) FROM reassignment_request_items i WHERE i.request_id = r.id) AS item_count
        FROM reassignment_requests r
        WHERE r.evaluator_id = ? AND r.status = 'pending'
        ORDER BY r.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Create a reassignment request plus one item per application.
 * 'all' snapshots every open case (may be zero: the evaluator is only
 * asking to be marked unavailable). 'selected' needs at least one case.
 *
 * @param int[] $selected_ids
 * @return array{ok:bool, reason?:string, request_id?:int, count?:int}
 */
function createReassignmentRequest(
    mysqli $db,
    int $evaluator_id,
    string $scope,
    string $reason,
    ?string $expected_return,
    array $selected_ids,
    ?string $reason_other = null
): array {
    $evaluator_id = (int) $evaluator_id;
    if (!$evaluator_id || !in_array($scope, ['all', 'selected'], true) || !isset(EVALUATOR_LEAVE_REASONS[$reason])) {
        return ['ok' => false, 'reason' => 'invalid'];
    }
    $reason_other = trim((string) $reason_other);
    if ($reason === 'other') {
        if ($reason_other === '' || mb_strlen($reason_other) > 255) {
            return ['ok' => false, 'reason' => 'invalid'];
        }
    } else {
        $reason_other = null;
    }
    $reason_other = $reason_other === '' ? null : $reason_other;
    if (getPendingReassignmentRequest($db, $evaluator_id)) {
        return ['ok' => false, 'reason' => 'pending_exists'];
    }

    $open_ids = array_map('intval', array_column(getOpenCasesForEvaluator($db, $evaluator_id), 'id'));
    $ids = $scope === 'all'
        ? $open_ids
        : array_values(array_intersect($open_ids, array_map('intval', $selected_ids)));

    if ($scope === 'selected' && !$ids) {
        return ['ok' => false, 'reason' => 'no_cases'];
    }

    try {
        $db->begin_transaction();

        $stmt = $db->prepare("
            INSERT INTO reassignment_requests (evaluator_id, scope, reason_category, reason_other, expected_return)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $evaluator_id, $scope, $reason, $reason_other, $expected_return);
        $stmt->execute();
        $request_id = (int) $db->insert_id;
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO reassignment_request_items (request_id, application_id) VALUES (?, ?)");
        foreach ($ids as $app_id) {
            $stmt->bind_param("ii", $request_id, $app_id);
            $stmt->execute();
        }
        $stmt->close();

        $db->commit();
        return ['ok' => true, 'request_id' => $request_id, 'count' => count($ids)];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('createReassignmentRequest failed: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
}

/** Evaluator withdraws their own pending request. */
function cancelReassignmentRequest(mysqli $db, int $request_id, int $evaluator_id): bool
{
    $request_id = (int) $request_id;
    $evaluator_id = (int) $evaluator_id;
    $stmt = $db->prepare("
        UPDATE reassignment_requests SET status = 'cancelled'
        WHERE id = ? AND evaluator_id = ? AND status = 'pending'
    ");
    $stmt->bind_param("ii", $request_id, $evaluator_id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

/** "I'm back": end the evaluator's active leave. */
function endActiveLeave(mysqli $db, int $evaluator_id, string $by_email): bool
{
    $evaluator_id = (int) $evaluator_id;
    $stmt = $db->prepare("
        UPDATE evaluator_unavailability
        SET status = 'ended', ended_by = ?, ended_at = NOW()
        WHERE evaluator_id = ? AND status = 'active'
    ");
    $stmt->bind_param("si", $by_email, $evaluator_id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

/** Days in for_compliance before the applicant is reminded. */
const COMPLIANCE_REMINDER_DAY = 2;

/** Days in for_compliance before the final reminder and staff escalation. */
const COMPLIANCE_ESCALATE_DAY = 3;

/** Days in for_compliance before admins are asked to decide. */
const COMPLIANCE_DECISION_DAY = 7;

/**
 * for_compliance applications that have had no document upload since the
 * status started. days_waiting is whole days since that start.
 *
 * @return array<int, array{id:int, current_evaluator_id:?int, started_at:string, days_waiting:int}>
 */
function getStaleComplianceApplications(mysqli $db): array
{
    $sql = "
        SELECT a.id,
               a.current_evaluator_id,
               h.started_at,
               DATEDIFF(CURDATE(), DATE(h.started_at)) AS days_waiting
        FROM applications a
        JOIN (
            SELECT application_id, MAX(changed_at) AS started_at
            FROM status_history
            WHERE to_status = 'for_compliance'
            GROUP BY application_id
        ) h ON h.application_id = a.id
        WHERE a.status = 'for_compliance'
          AND NOT EXISTS (
              SELECT 1
              FROM application_documents d
              WHERE d.application_id = a.id
                AND d.uploaded_at > h.started_at
          )
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('getStaleComplianceApplications failed: ' . $db->error);
        return [];
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'                   => (int) $row['id'],
            'current_evaluator_id' => $row['current_evaluator_id'] !== null ? (int) $row['current_evaluator_id'] : null,
            'started_at'           => (string) $row['started_at'],
            'days_waiting'         => (int) $row['days_waiting'],
        ];
    }
    $stmt->close();
    return $rows;
}

/** True when this notice was already stored for the current compliance spell. */
function complianceNoticeSent(mysqli $db, int $app_id, string $type, string $started_at): bool
{
    $app_id = (int) $app_id;
    $type = trim($type);
    if (!$app_id || $type === '' || $started_at === '') {
        return false;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM notifications
        WHERE application_id = ? AND type = ? AND created_at >= ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iss', $app_id, $type, $started_at);
    $stmt->execute();
    $sent = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $sent;
}

/** System row in activity_logs. Never throws. */
function logComplianceAction(mysqli $db, string $action, string $description, ?int $applicationId = null): void
{
    try {
        $email = null;
        $usertype = 'system';
        $module = 'application';
        $ip = null;
        $ua = null;
        $desc = mb_substr($description, 0, 2000);
        $stmt = $db->prepare(
            "INSERT INTO activity_logs
             (user_email, usertype, module, action, description, application_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('sssssiss', $email, $usertype, $module, $action, $desc, $applicationId, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('logComplianceAction failed: ' . $e->getMessage());
    }
}

/**
 * Applicant name, email, and job label for a compliance email.
 *
 * @return array{email:string, name:string, position:string, rsf_number:string}|null
 */
function complianceApplicantMailTarget(mysqli $db, int $app_id): ?array
{
    $stmt = $db->prepare("
        SELECT c.email, c.first_name, c.last_name, r.position_title, r.rsf_number
        FROM applications a
        JOIN clients c ON c.cid = a.client_id
        JOIN rsf_job_orders r ON r.id = a.rsf_id
        WHERE a.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['email'])) {
        return null;
    }

    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return [
        'email'      => (string) $row['email'],
        'name'       => $name !== '' ? $name : 'Applicant',
        'position'   => trim((string) ($row['position_title'] ?? '')),
        'rsf_number' => trim((string) ($row['rsf_number'] ?? '')),
    ];
}

/** HTML body for a compliance reminder. Sent with send_html_mail(). */
function complianceReminderEmailHtml(string $name, string $message, string $position, string $rsf): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $detail = trim($position . ($rsf !== '' ? ' (' . $rsf . ')' : ''));
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    $jobLine = $detail !== ''
        ? "<p style='font-size:13px;color:#374151;'>Position: <strong>{$safeDetail}</strong></p>"
        : '';

    return "
    <div style='font-family:Arial,sans-serif;max-width:640px;margin:0 auto;color:#1f2937;'>
        <div style='background:#0b1f4b;padding:22px 26px;border-radius:12px 12px 0 0;'>
            <p style='margin:0;color:#e5e7eb;font-size:11px;letter-spacing:.06em;text-transform:uppercase;'>Department of Migrant Workers</p>
            <h1 style='margin:6px 0 0;color:#ffffff;font-size:18px;'>Compliance follow-up</h1>
        </div>
        <div style='background:#ffffff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;padding:22px 26px;'>
            <p style='font-size:14px;line-height:1.6;'>Dear <strong>{$safeName}</strong>,</p>
            <p style='font-size:14px;line-height:1.6;'>{$safeMsg}</p>
            {$jobLine}
            <p style='font-size:13px;line-height:1.6;color:#374151;'>Please sign in to the applicant portal, open the application, and submit the corrections requested by the evaluator. Your application status has not been changed.</p>
            <p style='margin-top:22px;font-size:11px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:12px;'>This is a system-generated message from the DMW Application Management System.</p>
        </div>
    </div>";
}

/**
 * Daily follow-up for applications still in for_compliance with no new upload.
 * Does not change application status and does not disapprove anyone.
 * A missed day is still sent once days_waiting reaches the threshold; the
 * notifications row prevents a second copy of the same notice.
 *
 * @return array{checked:int, compliance_reminder:int, compliance_final:int, compliance_overdue:int, compliance_decision:int, emails_sent:int, emails_failed:int}
 */
function runComplianceFollowUp(mysqli $db): array
{
    $counts = [
        'checked'              => 0,
        'compliance_reminder'  => 0,
        'compliance_final'     => 0,
        'compliance_overdue'   => 0,
        'compliance_decision'  => 0,
        'emails_sent'          => 0,
        'emails_failed'        => 0,
    ];

    require_once __DIR__ . '/mailer.php';

    foreach (getStaleComplianceApplications($db) as $app) {
        $counts['checked']++;
        $appId = (int) $app['id'];
        $days = (int) $app['days_waiting'];
        $startedAt = (string) $app['started_at'];

        try {
            if ($days >= COMPLIANCE_REMINDER_DAY && !complianceNoticeSent($db, $appId, 'compliance_reminder', $startedAt)) {
                $title = 'Compliance reminder';
                $message = 'Your application has been in For Compliance for ' . $days . ' day' . ($days === 1 ? '' : 's')
                    . ' and no updated document has been received. Please sign in, review the evaluator\'s notes, and submit the required corrections.';
                if (notify_client_for_application($db, $appId, $title, $message, 'compliance_reminder')) {
                    $counts['compliance_reminder']++;
                    logComplianceAction($db, 'compliance_reminder', "Day {$days} compliance reminder sent for application #{$appId}.", $appId);
                    $target = complianceApplicantMailTarget($db, $appId);
                    if ($target) {
                        $ok = send_html_mail(
                            $db,
                            $target['email'],
                            $target['name'],
                            'DMW AMS — Compliance reminder',
                            complianceReminderEmailHtml($target['name'], $message, $target['position'], $target['rsf_number'])
                        );
                        if ($ok) {
                            $counts['emails_sent']++;
                        } else {
                            $counts['emails_failed']++;
                            error_log("Compliance reminder email failed for application #{$appId}.");
                            logComplianceAction($db, 'compliance_email_failed', "Day {$days} reminder email failed for application #{$appId}.", $appId);
                        }
                    }
                }
            }

            if ($days >= COMPLIANCE_ESCALATE_DAY && !complianceNoticeSent($db, $appId, 'compliance_final', $startedAt)) {
                $title = 'Final compliance reminder';
                $message = 'This is a final reminder. Your application has been in For Compliance for ' . $days
                    . ' days without a new document upload. Please submit the required updates as soon as possible. Your application status has not been changed.';
                if (notify_client_for_application($db, $appId, $title, $message, 'compliance_final')) {
                    $counts['compliance_final']++;
                    logComplianceAction($db, 'compliance_final', "Day {$days} final compliance reminder sent for application #{$appId}.", $appId);
                    $target = complianceApplicantMailTarget($db, $appId);
                    if ($target) {
                        $ok = send_html_mail(
                            $db,
                            $target['email'],
                            $target['name'],
                            'DMW AMS — Final compliance reminder',
                            complianceReminderEmailHtml($target['name'], $message, $target['position'], $target['rsf_number'])
                        );
                        if ($ok) {
                            $counts['emails_sent']++;
                        } else {
                            $counts['emails_failed']++;
                            error_log("Compliance final email failed for application #{$appId}.");
                            logComplianceAction($db, 'compliance_email_failed', "Day {$days} final reminder email failed for application #{$appId}.", $appId);
                        }
                    }
                }
            }

            if ($days >= COMPLIANCE_ESCALATE_DAY && !complianceNoticeSent($db, $appId, 'compliance_overdue', $startedAt)) {
                $title = 'Compliance overdue';
                $message = "Application #{$appId} has been in For Compliance for {$days} days without a new document upload. Please follow up with the applicant. The application status was not changed.";
                $adminsOk = notify_admins($db, $title, $message, 'compliance_overdue', $appId);
                $evalOk = notify_evaluator_for_application($db, $appId, $title, $message, 'compliance_overdue');
                if ($adminsOk || $evalOk) {
                    $counts['compliance_overdue']++;
                    logComplianceAction($db, 'compliance_overdue', "Day {$days} overdue notice sent for application #{$appId}.", $appId);
                }
                if (!$evalOk && !empty($app['current_evaluator_id'])) {
                    logComplianceAction($db, 'compliance_evaluator_notice_skipped', "No active assignment to notify for application #{$appId}.", $appId);
                }
            }

            if ($days >= COMPLIANCE_DECISION_DAY && !complianceNoticeSent($db, $appId, 'compliance_decision', $startedAt)) {
                $title = 'Compliance decision needed';
                $message = "Application #{$appId} has remained in For Compliance for {$days} days without a new document upload. Please review it and record a decision. The system did not change the status or disapprove the application.";
                if (notify_admins($db, $title, $message, 'compliance_decision', $appId)) {
                    $counts['compliance_decision']++;
                    logComplianceAction($db, 'compliance_decision', "Day {$days} decision notice sent for application #{$appId}.", $appId);
                }
            }
        } catch (Throwable $e) {
            error_log("runComplianceFollowUp failed for application #{$appId}: " . $e->getMessage());
            logComplianceAction($db, 'compliance_followup_error', $e->getMessage(), $appId);
        }
    }

    return $counts;
}

// Remaining slots for the job order an application belongs to.
// slots_available is already decremented on each approval (see application_status_change),
// so it IS the remaining count. NULL = no limit.
function getApplicationSlotInfo(mysqli $db, int $app_id): ?array
{
    $stmt = $db->prepare("
        SELECT r.slots_available
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    return ['remaining' => $row['slots_available'] === null ? null : (int) $row['slots_available']];
}

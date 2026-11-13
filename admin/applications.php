<?php
//Admin: Applications - list, filter, assign evaluators
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';
require_once '../includes/status_email.php';
require_once '../includes/activity_logger.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$admin_email = $_SESSION['user_email'];
$admin_id = null;
$admin_name = 'Admin';
$stmt_ad = $database->prepare("SELECT aid, full_name FROM admins WHERE email = ? LIMIT 1");
$stmt_ad->bind_param("s", $admin_email);
$stmt_ad->execute();
$ad_row = $stmt_ad->get_result()->fetch_assoc();
$stmt_ad->close();
if ($ad_row) {
    $admin_id   = (int) ($ad_row['aid'] ?? 0);
    $admin_name = $ad_row['full_name'] ?? 'Admin';
}
if (empty($_SESSION['verify_csrf'])) {
    $_SESSION['verify_csrf'] = bin2hex(random_bytes(32));
}
$verify_csrf_token = $_SESSION['verify_csrf'];

//  Stream the certificate PDF (opened from the "View Certificate PDF"
//    button after a successful verify). Re-checks ref_no + code again
//    server-side — same rule as the verify handler — so this endpoint
//    can't be used to pull up a certificate without knowing the code. 
if (isset($_GET['action']) && $_GET['action'] === 'view_certificate') {
    $vRefNo = trim($_GET['ref_no'] ?? '');
    $vCode  = trim($_GET['code'] ?? '');

    if (preg_match('/^APP-(\d{4})-(\d{6})$/i', $vRefNo, $m)) {
        $vAppId = (int) ltrim($m[2], '0');
        $vData  = dmw_build_status_data($database, $vAppId, 'approved');

        if (
            $vData
            && strcasecmp($vData['ref_no'], $vRefNo) === 0
            && hash_equals($vData['verify_code'], strtoupper($vCode))
        ) {
            $pdf = dmw_render_certificate_pdf($vData);
            $pdf->add_info('Title', $vData['applicant_name'] . ' - ' . $vData['ref_no']);
            $pdf->stream("DMW_Certificate_{$vData['ref_no']}.pdf", ['Attachment' => false]);
            exit;
        }
    }

    http_response_code(404);
    exit('Certificate not found or the reference/code is invalid.');
}

$status_filter = $_GET['status'] ?? '';
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$error = '';
$success = '';

//  Certificate verification (AJAX from the "Verify Cert" modal) 
// Handled first and exits immediately — completely independent of the
// applications-list logic below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_cert') {
    header('Content-Type: application/json');

    $result     = null;
    $certData   = null;
    $statusNote = null;

    if (!hash_equals($_SESSION['verify_csrf'], $_POST['csrf_token'] ?? '')) {
        $result = 'invalid_format';
    } else {
        $qrText     = trim($_POST['qr_text'] ?? '');
        $refNoInput = trim($_POST['ref_no'] ?? '');
        $codeInput  = trim($_POST['verify_code'] ?? '');

        if ($qrText !== '' && preg_match('/Ref:\s*(\S+)\s+Code:\s*(\S+)/i', $qrText, $m)) {
            $refNoInput = $m[1];
            $codeInput  = $m[2];
        }

        if (!preg_match('/^APP-(\d{4})-(\d{6})$/i', $refNoInput, $refMatch)) {
            $result = 'invalid_format';
        } else {
            $app_id = (int) ltrim($refMatch[2], '0');
            if ($app_id === 0) {
                $result = 'invalid_format';
            } else {
                $certData = dmw_build_status_data($database, $app_id, 'approved');

                if ($certData === null) {
                    $result = 'not_found';
                } else {
                    $refMatches  = strcasecmp($certData['ref_no'], $refNoInput) === 0;
                    $codeMatches = hash_equals($certData['verify_code'], strtoupper($codeInput));

                    if ($refMatches && $codeMatches) {
                        $result = 'verified';
                        if ($certData['status'] !== 'approved') {
                            $statusNote = "Note: this application's current status is now \""
                                . ucwords(str_replace('_', ' ', $certData['status']))
                                . "\" — the certificate reflects its state at the time it was issued.";
                        }
                    } else {
                        $result = 'mismatch';
                    }
                }
            }
        }
    }

    // Build the result card markup (same look as before).
    ob_start();
    if ($result === 'verified'): ?>
        <div class="result-card rc-ok fade-in d2" style="margin-top:16px">
            <div class="result-head">
                <i class="fa-solid fa-circle-check" style="color:#059669;font-size:19px"></i>
                <span class="result-title">Certificate Verified</span>
            </div>
            <p style="font-size:12.5px;color:#065f46">This reference number and code match a genuine, unaltered certificate issued by this system.</p>
            <a href="applications.php?action=view_certificate&ref_no=<?php echo urlencode($certData['ref_no']); ?>&code=<?php echo urlencode($certData['verify_code']); ?>"
                class="js-cert-pdf-link"
                style="display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#059669;color:#fff;font-weight:700;font-size:12.5px;padding:10px 16px;border-radius:10px;text-decoration:none;margin-top:10px;">
                <i class="fa-solid fa-file-pdf"></i> View PDF
            </a>
            <?php if ($statusNote): ?>
                <div class="result-note">
                    <i class="fa-solid fa-triangle-exclamation" style="margin-right:4px"></i>
                    <?php echo htmlspecialchars($statusNote); ?>
                </div>
            <?php endif; ?>

            <table class="cert-table">
                <tr>
                    <td>Applicant</td>
                    <td><?php echo htmlspecialchars($certData['applicant_name']); ?></td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td><?php echo htmlspecialchars($certData['applicant_email']); ?></td>
                </tr>
                <tr>
                    <td>Position</td>
                    <td><?php echo htmlspecialchars($certData['position_title']); ?></td>
                </tr>
                <tr>
                    <td>Employer</td>
                    <td><?php echo htmlspecialchars($certData['employer_name']); ?></td>
                </tr>
                <tr>
                    <td>RSF Number</td>
                    <td class="mono"><?php echo htmlspecialchars($certData['rsf_number']); ?></td>
                </tr>
                <tr>
                    <td>Evaluated By</td>
                    <td><?php echo htmlspecialchars($certData['evaluator_name'] ?: ($certData['admin_name'] . ' (Admin)')); ?></td>
                </tr>
                <tr>
                    <td>Date of Evaluation</td>
                    <td><?php echo date('F d, Y g:i a', strtotime($certData['evaluated_at'])); ?></td>
                </tr>
                <tr>
                    <td>Reference No.</td>
                    <td class="mono"><?php echo htmlspecialchars($certData['ref_no']); ?></td>
                </tr>
            </table>
        </div>
    <?php elseif ($result === 'mismatch'): ?>
        <div class="result-card rc-bad fade-in d2" style="margin-top:16px;text-align:center">
            <i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:26px;margin-bottom:6px"></i>
            <div class="result-title" style="margin-bottom:4px">Verification Failed</div>
            <p style="font-size:12.5px;color:#991b1b">The reference number and code do not match. This document may be altered or fraudulent — do not accept it as valid.</p>
        </div>
    <?php elseif ($result === 'not_found'): ?>
        <div class="result-card rc-warn fade-in d2" style="margin-top:16px;text-align:center">
            <i class="fa-solid fa-circle-question" style="color:#d97706;font-size:26px;margin-bottom:6px"></i>
            <div class="result-title" style="margin-bottom:4px">No Matching Record</div>
            <p style="font-size:12.5px;color:#92400e">No approved application matches this reference number.</p>
        </div>
    <?php elseif ($result === 'invalid_format'): ?>
        <div class="result-card rc-neutral fade-in d2" style="margin-top:16px;text-align:center">
            <i class="fa-solid fa-circle-exclamation" style="color:var(--text3,#94a3b8);font-size:26px;margin-bottom:6px"></i>
            <div class="result-title" style="margin-bottom:4px">Invalid Input</div>
            <p style="font-size:12.5px;color:var(--text2,#475569)">Check the reference number format (APP-YYYY-NNNNNN) and try again.</p>
        </div>
<?php endif;
    $resultHtml = ob_get_clean();

    echo json_encode(['result' => $result, 'html' => $resultHtml]);
    exit;
}

// Search box  matches Applicant Name, Gmail (client email), RSF #, Country,
// and Handling (who currently has it: Admin/Evaluator, or the evaluator's name).
$search_filter = trim($_GET['q'] ?? '');

//  Subbar filters: Month & Country (same behavior as evaluator/applications.php)
// Month filters applications by their submission date (YYYY-MM).
$month_filter = trim($_GET['month'] ?? '');
$month_filter = preg_match('/^\d{4}-\d{2}$/', $month_filter) ? $month_filter : '';

// Country filters by the RSF job order's worksite country.
$country_filter = trim($_GET['country'] ?? '');

/**
 * Builds a reusable " AND ..." WHERE fragment (+ matching bind types/params)
 * for the Month / Country subbar filters. Assumes applications is aliased
 * `a` and rsf_job_orders is aliased/joined as `r` in the calling query.
 */
function buildAdminAppFilters(string $month, string $country): array
{
    $where  = '';
    $types  = '';
    $params = [];

    if ($month !== '') {
        $start = $month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $where   .= " AND a.submitted_at BETWEEN ? AND ?";
        $types   .= 'ss';
        $params[] = $start;
        $params[] = $end;
    }
    if ($country !== '') {
        $where   .= " AND r.worksite_country = ?";
        $types   .= 's';
        $params[] = $country;
    }

    return [$where, $types, $params];
}

/** Query string for KPI / pagination links, preserving status + month + country + search. */
function build_admin_apps_qs(?string $status, string $month, string $country, string $search = ''): string
{
    $params = [];
    if ($status)         $params['status']  = $status;
    if ($month !== '')   $params['month']   = $month;
    if ($country !== '') $params['country'] = $country;
    if ($search !== '')  $params['q']       = $search;
    return $params ? '?' . http_build_query($params) : '';
}

/** True if the evaluator exists and their webuser account is active. */
function isActiveEvaluator(mysqli $database, int $evaluator_id): bool
{
    if ($evaluator_id <= 0) return false;
    $stmt = $database->prepare("
        SELECT 1
        FROM evaluators e
        JOIN webuser w ON w.email = e.email
        WHERE e.eid = ? AND w.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

$excludeDraft = " AND COALESCE(a.status, '') <> 'draft'";
[$filterWhere, $filterTypes, $filterParams] = buildAdminAppFilters($month_filter, $country_filter);

// Distinct countries (from job orders that actually have applications) for
// the Country subbar dropdown.
$countries = [];
$countries_res = $database->query("
    SELECT DISTINCT r.worksite_country
    FROM rsf_job_orders r
    JOIN applications a ON a.rsf_id = r.id
    WHERE r.worksite_country IS NOT NULL AND r.worksite_country <> ''
      AND COALESCE(a.status, '') <> 'draft'
    ORDER BY r.worksite_country ASC
");
if ($countries_res) {
    while ($row = $countries_res->fetch_assoc()) {
        $countries[] = $row['worksite_country'];
    }
}

// Assign evaluator (POST) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    $app_id = (int) ($_POST['application_id'] ?? 0);
    $evaluator_id = (int) ($_POST['evaluator_id'] ?? 0);
    if ($app_id && $evaluator_id && !isActiveEvaluator($database, $evaluator_id)) {
        $error = 'The selected evaluator\'s account is deactivated. Please choose an active evaluator.';
    } elseif ($app_id && $evaluator_id) {
        $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $app_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $st = $row ? (string) ($row['status'] ?? '') : '';
        $assignable = in_array($st, ['unassign', 'unassigned', 'resubmitted'], true);
        if ($row && $assignable) {
            $stmt_asn = $database->prepare("SELECT id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
            $stmt_asn->bind_param("i", $app_id);
            $stmt_asn->execute();
            $has_active = $stmt_asn->get_result()->num_rows > 0;
            $stmt_asn->close();

            if ($has_active && $st !== 'resubmitted') {
                $error = 'This application already has an active evaluator assignment.';
            } elseif ($has_active && $st === 'resubmitted') {
                $stmt_cur = $database->prepare("SELECT evaluator_id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
                $stmt_cur->bind_param("i", $app_id);
                $stmt_cur->execute();
                $asn_row = $stmt_cur->get_result()->fetch_assoc();
                $stmt_cur->close();
                $cur_ev = (int) ($asn_row['evaluator_id'] ?? 0);
                if ($cur_ev !== $evaluator_id) {
                    $error = 'This resubmitted application is already assigned. Choose the same evaluator to return it to the review queue.';
                } elseif (application_status_change($database, $app_id, 'assign', $admin_email, 'Returned to evaluator after client resubmission')) {
                    $stmt_lock = $database->prepare("
                        UPDATE applications
                        SET current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_lock->bind_param("ii", $evaluator_id, $app_id);
                    $stmt_lock->execute();
                    $stmt_lock->close();

                    notify_client_for_application(
                        $database,
                        $app_id,
                        'Application Assigned',
                        'Your resubmitted application has been assigned back to an evaluator for review.'
                    );
                    notify_evaluator_for_application(
                        $database,
                        $app_id,
                        'Application Resubmitted',
                        get_applicant_name($database, $app_id) . "'s application has been resubmitted by the client and returned to your queue."
                    );
                    $success = "Application #$app_id set back to assigned for evaluation.";
                } else {
                    $error = 'Failed to update application status.';
                }
            } else {
                $stmt = $database->prepare("INSERT INTO assignments (application_id, evaluator_id, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("ii", $app_id, $evaluator_id);
                if ($stmt->execute()) {
                    $stmt->close();
                    $ev_stmt = $database->prepare("SELECT full_name FROM evaluators WHERE eid = ?");
                    $ev_stmt->bind_param("i", $evaluator_id);
                    $ev_stmt->execute();
                    $ev_name = $ev_stmt->get_result()->fetch_assoc()['full_name'] ?? 'Evaluator';
                    $ev_stmt->close();
                    application_status_change($database, $app_id, 'assign', $admin_email, "Assigned to evaluator: $ev_name");

                    $stmt_lock = $database->prepare("
                        UPDATE applications
                        SET current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_lock->bind_param("ii", $evaluator_id, $app_id);
                    $stmt_lock->execute();
                    $stmt_lock->close();

                    notify_client_for_application(
                        $database,
                        $app_id,
                        'Application Assigned',
                        "Your application has been assigned to evaluator {$ev_name}."
                    );
                    notify_evaluator_for_application(
                        $database,
                        $app_id,
                        'New Application Assigned',
                        get_applicant_name($database, $app_id) . "'s application has been assigned to you for evaluation."
                    );
                    $success = "Application #$app_id assigned successfully.";
                } else {
                    $stmt->close();
                    $error = 'Assignment failed.';
                }
            }
        } elseif (!$row) {
            $error = 'Application not found.';
        } else {
            $error = 'Application must be unassign or resubmitted to assign.';
        }
    } else {
        $error = 'Invalid application or evaluator.';
    }
}

// NOTE: "Evaluate Directly" is no longer handled on this page. Clicking the
// button in the table now redirects the admin to view-application.php,
// which already has the full Evaluate Directly panel (Approve/Disapprove
// with comments) wired up.

// Admin reassigns a for_compliance (or in-progress) case to a different evaluator.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign') {
    $app_id = (int) ($_POST['application_id'] ?? 0);
    $new_evaluator_id = (int) ($_POST['evaluator_id'] ?? 0);
    if ($app_id && $new_evaluator_id && !isActiveEvaluator($database, $new_evaluator_id)) {
        $error = 'The selected evaluator\'s account is deactivated. Please choose an active evaluator.';
    } elseif ($app_id && $new_evaluator_id) {
        if (adminReassignApplication($database, $app_id, $new_evaluator_id, $admin_email)) {
            $ev_stmt = $database->prepare("SELECT full_name FROM evaluators WHERE eid = ?");
            $ev_stmt->bind_param("i", $new_evaluator_id);
            $ev_stmt->execute();
            $ev_name = $ev_stmt->get_result()->fetch_assoc()['full_name'] ?? 'Evaluator';
            $ev_stmt->close();

            $applicant_name = get_applicant_name($database, $app_id);

            log_activity(
                $database,
                $admin_email,
                'admin',
                'application',
                'reassign',
                "Reassigned {$applicant_name}'s application to {$ev_name}",
                $app_id
            );

            notify_evaluator_for_application(
                $database,
                $app_id,
                'Application Reassigned to You',
                "{$applicant_name}'s application has been reassigned to you by Admin. Please review the application details."
            );
            notify_client_for_application(
                $database,
                $app_id,
                'Application Reassigned',
                "Your application has been reassigned to evaluator {$ev_name}."
            );
            $success = "{$applicant_name}'s application has been reassigned to {$ev_name}.";
            $success_type = 'reassign';
        } else {
            $error = 'Reassignment failed.';
        }
    } else {
        $error = 'Invalid application or evaluator.';
    }
}
// Pagination settings
$per_page = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$view_all = isset($_GET['view_all']) && $_GET['view_all'] === '1';

// Fetch applications with optional status filter
$applications = [];
$sql = "
    SELECT a.id, a.status, a.submitted_at, a.updated_at,
           a.current_evaluator_id, a.handling_party, a.locked_at, a.notes,
           r.rsf_number, r.position_title, r.employer_name, r.worksite_country,
           CONCAT(c.first_name, ' ', c.last_name) as applicant_name, c.email as applicant_email,
           c.profile_picture as applicant_picture,
           ev.full_name as current_evaluator_name,
           asn.reassign_count
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN clients c ON a.client_id = c.cid
    LEFT JOIN evaluators ev ON a.current_evaluator_id = ev.eid
    LEFT JOIN (
        SELECT application_id, COUNT(*) AS reassign_count
        FROM assignments
        GROUP BY application_id
    ) asn ON asn.application_id = a.id
    WHERE 1=1
";
// countSql needs the same joins as $sql (minus the reassign_count subquery,
// which isn't needed just to count rows) so the search filter below can
// match on name/email/rsf/country/handling for the total-pages calculation too.
$countSql = "
    SELECT COUNT(*) as total
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN clients c ON a.client_id = c.cid
    LEFT JOIN evaluators ev ON a.current_evaluator_id = ev.eid
    WHERE 1=1
";
$params = [];
$types = "";
$valid_single = ['pending', 'resubmitted', 'under_evaluation', 'for_compliance', 'approved', 'disapproved'];
if ($status_filter === 'unassign' || $status_filter === 'unassigned') {
    $sql .= " AND a.status IN ('unassign', 'unassigned')";
    $countSql .= " AND a.status IN ('unassign', 'unassigned')";
} elseif ($status_filter === 'assign' || $status_filter === 'assigned') {
    $sql .= " AND a.status IN ('assign', 'assigned')";
    $countSql .= " AND a.status IN ('assign', 'assigned')";
} elseif ($status_filter && in_array($status_filter, $valid_single, true)) {
    $sql .= " AND a.status = ?";
    $countSql .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql      .= $excludeDraft . $filterWhere;
$countSql .= $excludeDraft . $filterWhere;
$params    = array_merge($params, $filterParams);
$types    .= $filterTypes;

// Admin search box — Applicant Name, Gmail, RSF, Country, Handling.
if ($search_filter !== '') {
    $searchCond = " AND (
        CONCAT(c.first_name, ' ', c.last_name) LIKE ?
        OR c.email LIKE ?
        OR r.rsf_number LIKE ?
        OR r.worksite_country LIKE ?
        OR a.handling_party LIKE ?
        OR ev.full_name LIKE ?
    )";
    $sql .= $searchCond;
    $countSql .= $searchCond;
    $like = '%' . $search_filter . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= "ssssss";
}

// Total count for this filter, used to compute page count
$total_filtered = 0;
if ($params) {
    $stmt_count = $database->prepare($countSql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_filtered = (int) ($stmt_count->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt_count->close();
} else {
    $res_count = $database->query($countSql);
    if ($res_count) $total_filtered = (int) ($res_count->fetch_assoc()['total'] ?? 0);
}
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$sql .= " ORDER BY a.updated_at DESC";
if (!$view_all) {
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
}

$stmt = $database->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Evaluators for dropdown — active accounts only
$evaluators = [];
$res = $database->query("
    SELECT e.eid, e.full_name, e.email
    FROM evaluators e
    JOIN webuser w ON w.email = e.email
    WHERE w.is_active = 1
    ORDER BY e.full_name
");
if ($res) $evaluators = $res->fetch_all(MYSQLI_ASSOC);

// Stats (unassign / assign roll up legacy spellings). When Month/Country
// filters are active, counts match the filtered list (join job orders).
$stats = ['pending' => 0, 'unassign' => 0, 'resubmitted' => 0, 'assign' => 0, 'under_evaluation' => 0, 'for_compliance' => 0, 'approved' => 0, 'disapproved' => 0];
if ($filterTypes !== '') {
    $statsSql = "
        SELECT a.status, COUNT(*) as cnt
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE 1=1 {$excludeDraft} {$filterWhere}
        GROUP BY a.status
    ";
    $stmt_stats = $database->prepare($statsSql);
    $stmt_stats->bind_param($filterTypes, ...$filterParams);
    $stmt_stats->execute();
    $res = $stmt_stats->get_result();
} else {
    $res = $database->query("
        SELECT a.status, COUNT(*) as cnt
        FROM applications a
        WHERE COALESCE(a.status, '') <> 'draft'
        GROUP BY a.status
    ");
}
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $s = $r['status'];
        $c = (int) $r['cnt'];
        if ($s === 'unassigned' || $s === 'unassign') {
            $stats['unassign'] += $c;
        } elseif ($s === 'assigned' || $s === 'assign') {
            $stats['assign'] += $c;
        } elseif (isset($stats[$s])) {
            $stats[$s] += $c;
        }
    }
}
if (isset($stmt_stats)) {
    $stmt_stats->close();
}

// KPI card definitions (built from the $stats roll-up above, no extra query)
$total_all = array_sum($stats);

$kpi_defs = [
    ['key' => '',                 'label' => 'All Applications', 'icon' => 'fa-list',               'color' => '#60a5fa', 'count' => $total_all],
    ['key' => 'pending',          'label' => 'Pending',          'icon' => 'fa-clock',              'color' => '#3b82f6', 'count' => $stats['pending']],
    /*['key' => 'unassign',         'label' => 'Unassign',         'icon' => 'fa-user-slash',         'color' => '#f59e0b', 'count' => $stats['unassign']],*/
    ['key' => 'resubmitted',      'label' => 'Resubmitted',      'icon' => 'fa-rotate-right',       'color' => '#f59e0b', 'count' => $stats['resubmitted']],
    ['key' => 'under_evaluation', 'label' => 'Under Evaluation', 'icon' => 'fa-search',             'color' => '#a78bfa', 'count' => $stats['under_evaluation']],
    ['key' => 'for_compliance',   'label' => 'For Compliance',   'icon' => 'fa-triangle-exclamation', 'color' => '#fb923c', 'count' => $stats['for_compliance']],
    ['key' => 'approved',         'label' => 'Approved',         'icon' => 'fa-check-circle',       'color' => '#10b981', 'count' => $stats['approved']],
    ['key' => 'disapproved',      'label' => 'Disapproved',      'icon' => 'fa-times-circle',       'color' => '#ef4444', 'count' => $stats['disapproved']],
];
?>

<?php ob_start(); ?>
<?php if (empty($applications)): ?>
    <tr class="empty-row">
        <td colspan="6" class="px-6 py-16 text-center text-[var(--text3)]">
            <i class="fas fa-inbox text-4xl text-[var(--border)] block mb-3"></i>
            <span class="text-sm font-bold text-[var(--text2)] block">No applications found</span>
            <span class="text-xs">
                <?php if ($search_filter !== ''): ?>
                    No results for "<?= htmlspecialchars($search_filter) ?>"<?= $status_filter ? ' in "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?>. Try a different name, Gmail, RSF #, country, or handling.
                <?php else: ?>
                    Try a different filter above.
                <?php endif; ?>
            </span>
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($applications as $i => $app): ?>
        <?php
        $statusClass = 'bg-gray-100 text-gray-700';
        $statusIcon  = 'fa-circle';
        if ($app['status'] === 'approved') {
            $statusClass = 'bg-emerald-100 text-emerald-700';
            $statusIcon  = 'fa-check-circle';
        } elseif (in_array($app['status'], ['under_evaluation', 'assign', 'assigned'])) {
            $statusClass = 'bg-indigo-100 text-indigo-700';
            $statusIcon  = $app['status'] === 'under_evaluation' ? 'fa-search' : 'fa-user-check';
        } elseif ($app['status'] === 'for_compliance') {
            $statusClass = 'bg-orange-100 text-orange-700';
            $statusIcon  = 'fa-triangle-exclamation';
        } elseif ($app['status'] === 'disapproved') {
            $statusClass = 'bg-red-100 text-red-700';
            $statusIcon  = 'fa-times-circle';
        } elseif (in_array($app['status'], ['unassign', 'unassigned', 'resubmitted'])) {
            $statusClass = 'bg-amber-100 text-amber-700';
            $statusIcon  = 'fa-rotate-right';
        } elseif ($app['status'] === 'pending') {
            $statusClass = 'bg-blue-100 text-blue-700';
            $statusIcon  = 'fa-clock';
        }
        $isStale  = !empty($app['locked_at'])
            && in_array($app['status'], ['under_evaluation', 'for_compliance'], true)
            && ((time() - strtotime($app['locked_at'])) / 3600) > 48;

        $app_has_pic = !empty($app['applicant_picture']);
        $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
        $ap_parts = explode(' ', trim($app['applicant_name'] ?? ''));
        $ap_avi = strtoupper(count($ap_parts) >= 2
            ? substr($ap_parts[0], 0, 1) . substr(end($ap_parts), 0, 1)
            : substr($app['applicant_name'] ?? 'NA', 0, 2));
        $is_unowned = empty($app['current_evaluator_name'])
            && in_array($app['status'], ['pending', 'unassign', 'unassigned'], true);
        ?>
        <tr class="app-row <?= $isStale ? 'row-stale' : '' ?>">
            <!-- Applicant -->
            <td class="c-app">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="app-avatar"
                        <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                        <?= $app_has_pic ? '' : htmlspecialchars($ap_avi) ?>
                    </div>
                    <div class="min-w-0">
                        <a href="view-application.php?id=<?= (int) $app['id'] ?>" class="app-name hover:underline block truncate">
                            <?= htmlspecialchars($app['applicant_name'] ?? '—') ?>
                        </a>
                        <div class="app-sub truncate"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                    </div>
                </div>
            </td>

            <!-- Position / RSF -->
            <td class="c-pos">
                <div class="app-title truncate"><?= htmlspecialchars($app['position_title']) ?></div>
                <div class="app-sub truncate"><?= htmlspecialchars($app['rsf_number']) ?></div>
                <div class="app-sub truncate">
                    <?= htmlspecialchars($app['employer_name']) ?><?= !empty($app['worksite_country']) ? ' · ' . htmlspecialchars($app['worksite_country']) : '' ?>
                </div>
            </td>

            <!-- Status -->
            <td class="c-status">
                <span class="status-badge inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10.5px] font-bold uppercase tracking-wide whitespace-nowrap <?= $statusClass ?>"
                    <?php if (!empty($app['notes'])): ?>title="<?= htmlspecialchars($app['notes'], ENT_QUOTES) ?>" <?php endif; ?>>
                    <i class="fas <?= $statusIcon ?> text-[9px]"></i><?= htmlspecialchars(str_replace('_', ' ', ucfirst($app['status']))) ?>
                </span>
            </td>

            <!-- Handling -->
            <td class="c-hand">
                <?php if (!empty($app['current_evaluator_name'])): ?>
                    <div class="app-title truncate" style="color:var(--navy);"><?= htmlspecialchars($app['current_evaluator_name']) ?></div>
                    <div class="app-sub">
                        <?= $app['handling_party'] === 'admin' ? 'With Admin' : 'With Evaluator' ?>
                        <?php if (!empty($app['reassign_count']) && (int) $app['reassign_count'] > 1): ?>
                            · reassigned <?= (int) $app['reassign_count'] - 1 ?>x
                        <?php endif; ?>
                    </div>
                    <?php
                    if (!empty($app['locked_at']) && in_array($app['status'], ['under_evaluation', 'for_compliance'], true)) {
                        $locked_hours = (time() - strtotime($app['locked_at'])) / 3600;
                        if ($locked_hours > 48) {
                            echo '<div class="text-amber-600 font-semibold mt-0.5 text-xs"><i class="fas fa-triangle-exclamation"></i> Stale since '
                                . date('M d', strtotime($app['locked_at'])) . '</div>';
                        }
                    }
                    ?>
                <?php elseif ($is_unowned): ?>
                    <div class="app-title" style="color:#dc2626;">Unassigned</div>
                    <div class="app-sub">Awaiting evaluator</div>
                <?php else: ?>
                    <span class="app-sub">—</span>
                <?php endif; ?>
            </td>

            <!-- Dates -->
            <td class="c-date">
                <div class="app-sub"><span class="date-lbl">Submitted</span> <?= date('M d, Y', strtotime($app['submitted_at'])) ?></div>
                <div class="app-sub"><span class="date-lbl">Updated</span> <?= date('M d, Y', strtotime($app['updated_at'])) ?></div>
            </td>

            <!-- Action -->
            <td class="c-act">
                <?php if (in_array($app['status'], ['unassign', 'unassigned'], true) && !empty($evaluators)): ?>
                    <div class="act-stack">
                        <form method="POST" class="flex items-center gap-2 flex-wrap">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                            <select name="evaluator_id" required
                                class="border border-[var(--border)] bg-[var(--card)] text-[var(--text1)] rounded-lg px-2.5 py-2 text-xs focus:outline-none focus:border-[var(--blue-lt)] w-full sm:w-auto sm:max-w-[150px]">
                                <option value="">Select evaluator...</option>
                                <?php foreach ($evaluators as $ev): ?>
                                    <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit"
                                class="bg-[var(--blue)] hover:bg-[#1e40af] text-white px-4 py-2 rounded-lg text-xs font-semibold transition whitespace-nowrap">
                                <i class="fas fa-user-plus"></i> Assign
                            </button>
                        </form>
                        <a href="view-application.php?id=<?= (int) $app['id'] ?>" class="act-link">
                            <i class="fas fa-eye"></i> View details
                        </a>
                    </div>
                <?php elseif (in_array($app['status'], ['resubmitted'], true)): ?>
                    <div class="act-stack">
                        <a href="view-application.php?id=<?= (int) $app['id'] ?>"
                            class="direct-evaluate-link text-white px-4 py-2 rounded-lg text-xs font-semibold transition inline-flex items-center justify-center gap-2 whitespace-nowrap">
                            <i class="fas fa-gavel"></i> Evaluate Directly
                        </a>

                        <?php if (!empty($evaluators)): ?>
                            <details class="relative">
                                <summary class="act-link cursor-pointer list-none">
                                    <i class="fas fa-user-pen"></i> Reassign
                                </summary>
                                <form method="POST" class="mt-2 flex items-center gap-2">
                                    <input type="hidden" name="action" value="reassign">
                                    <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                    <select name="evaluator_id" required oninvalid="this.setCustomValidity('Please select an evaluator from the list.')" oninput="this.setCustomValidity('')"
                                        class="border border-[var(--border)] bg-[var(--card)] text-[var(--text1)] rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:border-[var(--blue-lt)] max-w-[150px]">
                                        <option value="">Select evaluator...</option>
                                        <?php foreach ($evaluators as $ev): ?>
                                            <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit"
                                        class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition whitespace-nowrap">
                                        Go
                                    </button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="act-stack">
                        <a href="view-application.php?id=<?= (int) $app['id'] ?>" class="act-btn-ghost">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
<?php $tbody_html = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if ($total_pages > 1 || $view_all): ?>
    <?php
    $qs = ($status_filter ? '&status=' . urlencode($status_filter) : '')
        . ($month_filter !== '' ? '&month=' . urlencode($month_filter) : '')
        . ($country_filter !== '' ? '&country=' . urlencode($country_filter) : '')
        . ($search_filter !== '' ? '&q=' . urlencode($search_filter) : '');

    $show_from = $total_filtered > 0 ? ($view_all ? 1 : $offset + 1) : 0;
    $show_to   = $view_all ? $total_filtered : ($offset + count($applications));

    // Compact page list: first, last, current ±1, with ellipses between gaps
    $page_items = [];
    for ($p = 1; $p <= $total_pages; $p++) {
        if ($p === 1 || $p === $total_pages || abs($p - $page) <= 1) {
            $page_items[] = $p;
        } elseif (end($page_items) !== '…') {
            $page_items[] = '…';
        }
    }
    ?>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 sm:px-6 py-4 border-t border-[var(--border)] text-sm">
        <span class="text-[var(--text3)] text-xs order-2 sm:order-1">
            <?php if ($view_all): ?>
                Showing all <?= $total_filtered ?> application<?= $total_filtered === 1 ? '' : 's' ?>
            <?php else: ?>
                Showing <?= $show_from ?>–<?= $show_to ?> of <?= $total_filtered ?> · Page <?= $page ?> of <?= $total_pages ?>
            <?php endif; ?>
        </span>
        <div class="flex items-center gap-1.5 flex-wrap justify-center order-1 sm:order-2">
            <?php if (!$view_all): ?>
                <?php if ($page > 1): ?>
                    <a href="applications.php?page=<?= $page - 1 ?><?= $qs ?>" class="pg-btn" aria-label="Previous page"><i class="fas fa-chevron-left text-[10px]"></i></a>
                <?php endif; ?>
                <?php foreach ($page_items as $p): ?>
                    <?php if ($p === '…'): ?>
                        <span class="px-1 text-[var(--text3)]">…</span>
                    <?php else: ?>
                        <a href="applications.php?page=<?= $p ?><?= $qs ?>"
                            class="pg-btn <?= $p === $page ? 'pg-active' : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>>
                            <?= $p ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="applications.php?page=<?= $page + 1 ?><?= $qs ?>" class="pg-btn" aria-label="Next page"><i class="fas fa-chevron-right text-[10px]"></i></a>
                <?php endif; ?>
                <a href="applications.php?view_all=1<?= $qs ?>" class="pg-btn px-3 whitespace-nowrap ml-1">
                    <i class="fas fa-list mr-1"></i> View All
                </a>
            <?php else: ?>
                <a href="applications.php?page=1<?= $qs ?>" class="pg-btn px-3 whitespace-nowrap">
                    <i class="fas fa-table-list mr-1"></i> Back to Paginated View
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php $pagination_html = ob_get_clean(); ?>

<?php
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'tbody' => $tbody_html,
        'pagination' => $pagination_html,
        'total' => $total_filtered,
        'page' => $page,
        'total_pages' => $total_pages,
        'heading' => ($status_filter ? ucfirst(str_replace('_', ' ', $status_filter)) . ' Applications' : 'All Applications')
            . ($search_filter !== '' ? ' · matching "' . $search_filter . '"' : ''),
        'success' => $success,
        'error' => $error,
        'success_type' => $success_type ?? null,
        'stats' => array_merge($stats, ['all' => $total_all]),
    ]);
    exit;
}

// Presentation-only figures for the summary cards (derived from $stats, no extra query)
$sum_action   = $stats['pending'] + $stats['resubmitted'];
$sum_approved_pct    = $total_all > 0 ? (int) round($stats['approved'] / $total_all * 100) : 0;
$sum_disapproved_pct = $total_all > 0 ? (int) round($stats['disapproved'] / $total_all * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applications – DMW RO IV-A Admin</title>
    <?php include '../includes/theme-init.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <style>
        body {
            font-family: 'DM Sans', sans-serif;
        }

        .font-display {
            font-family: 'DM Serif Display', serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        .direct-evaluate-link {
            background: #0b1f4b;
        }

        .direct-evaluate-link:hover {
            background: #132a63;
        }

        /* Short fade used by the verify-result card */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            opacity: 0;
            animation: fadeInUp .3s ease forwards;
        }

        @media (prefers-reduced-motion: reduce) {
            .fade-in {
                animation: none;
                opacity: 1;
            }
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* ===== Summary cards ===== */
        .sum-card {
            background: var(--card, #fff);
            border: 1px solid var(--border, #e2e8f0);
            border-left-width: 4px;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .sum-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text3, #94a3b8);
        }

        .sum-value {
            font-size: 26px;
            line-height: 1.15;
            font-weight: 800;
            color: var(--text1, #0f172a);
            margin-top: 2px;
        }

        .sum-note {
            font-size: 11.5px;
            color: var(--text3, #94a3b8);
        }

        /* ===== Status tabs ===== */
        .app-tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 13px 14px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text2, #475569);
            white-space: nowrap;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: color .15s, background .15s;
        }

        .app-tab:hover {
            color: var(--text1, #0f172a);
            background: var(--surface, #f4f6fb);
        }

        .app-tab.active {
            color: var(--navy, #0b1f4b);
            border-bottom-color: var(--navy, #0b1f4b);
            font-weight: 700;
        }

        .tab-count {
            background: var(--surface, #f4f6fb);
            color: var(--text2, #475569);
            border-radius: 999px;
            padding: 1px 8px;
            font-size: 11px;
            font-weight: 700;
            min-width: 22px;
            text-align: center;
        }

        .app-tab.active .tab-count {
            background: var(--navy, #0b1f4b);
            color: #fff;
        }

        /* ===== Table ===== */
        .apps-table {
            width: 100%;
            border-collapse: collapse;
        }

        .apps-table th {
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3, #94a3b8);
            background: var(--surface, #f4f6fb);
            border-bottom: 1px solid var(--border, #e2e8f0);
            white-space: nowrap;
        }

        .apps-table td {
            padding: 16px;
            vertical-align: middle;
        }

        .app-row {
            transition: background .12s;
        }

        .app-row:hover {
            background: var(--surface, #f4f6fb);
        }

        .row-stale {
            box-shadow: inset 3px 0 0 #f59e0b;
        }

        .app-avatar {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--indigo, #4f46e5);
            font-size: 12px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .app-name {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--navy, #0b1f4b);
        }

        .app-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1, #0f172a);
        }

        .app-sub {
            font-size: 11.5px;
            color: var(--text3, #94a3b8);
            line-height: 1.5;
        }

        .date-lbl {
            display: inline-block;
            min-width: 62px;
            color: var(--text3, #94a3b8);
            font-weight: 600;
        }

        .act-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }

        .act-link {
            font-size: 12px;
            font-weight: 600;
            color: var(--text2, #475569);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px;
            border-radius: 6px;
        }

        .act-link:hover {
            color: var(--navy, #0b1f4b);
            background: var(--surface, #f4f6fb);
        }

        details>summary::-webkit-details-marker {
            display: none;
        }

        .act-btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border, #e2e8f0);
            color: var(--text1, #0f172a);
            background: var(--card, #fff);
            text-decoration: none;
            transition: background .12s;
        }

        .act-btn-ghost:hover {
            background: var(--surface, #f4f6fb);
        }

        /* ===== Pagination ===== */
        .pg-btn {
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text2, #475569);
            background: var(--surface, #f4f6fb);
            border: 1px solid var(--border, #e2e8f0);
        }

        .pg-btn:hover {
            background: var(--border, #e2e8f0);
        }

        .pg-btn.pg-active {
            background: var(--blue, #1d4ed8);
            border-color: var(--blue, #1d4ed8);
            color: #fff;
        }

        /* Desktop / tablet: fixed column widths, horizontal scroll if tight */
        @media (min-width: 768px) {
            .apps-table {
                table-layout: fixed;
                min-width: 980px;
            }

            .c-status {
                text-align: center;
            }

            .c-act {
                text-align: right;
            }
        }

        /* Mobile: each row becomes a stacked card */
        @media (max-width: 767px) {
            .apps-table thead {
                display: none;
            }

            .apps-table,
            .apps-table tbody {
                display: block;
            }

            .app-row {
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 10px 12px;
                padding: 16px;
                align-items: start;
            }

            .app-row td {
                display: block;
                padding: 0;
            }

            .app-row .c-app {
                grid-column: 1;
            }

            .app-row .c-status {
                grid-column: 2;
                grid-row: 1;
            }

            .app-row .c-pos,
            .app-row .c-act {
                grid-column: 1 / -1;
            }

            .app-row .c-hand {
                grid-column: 1;
            }

            .app-row .c-date {
                grid-column: 2;
                text-align: right;
            }

            .app-row .c-date .date-lbl {
                min-width: 0;
                margin-right: 4px;
            }

            .act-stack {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
                justify-content: flex-start;
                padding-top: 10px;
                border-top: 1px dashed var(--border, #e2e8f0);
            }

            .empty-row {
                display: block;
            }

            .empty-row td {
                display: block;
            }
        }

        /* ===== Verify certificate modal ===== */
        .verify-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 31, 75, .45);
            z-index: 100000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .verify-modal-overlay.open {
            display: flex;
        }

        .swal2-container {
            z-index: 200000 !important;
        }

        .verify-modal {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(11, 31, 75, .3);
        }

        .verify-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .verify-modal-title {
            font-weight: 800;
            font-size: 15px;
            color: var(--navy, #0b1f4b);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .verify-modal-close {
            background: var(--surface, #f4f6fb);
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text3, #94a3b8);
        }

        .verify-modal-close:hover {
            background: #e2e8f0;
            color: var(--text1, #0f172a);
        }

        .verify-field {
            margin-bottom: 16px;
        }

        .verify-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3, #94a3b8);
            margin-bottom: 6px;
        }

        .verify-input {
            width: 100%;
            border: 1.5px solid var(--border, #e2e8f0);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text1, #0f172a);
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
        }

        .verify-input:focus {
            outline: none;
            border-color: var(--blue, #1d4ed8);
            box-shadow: 0 0 0 3px rgba(29, 78, 216, .12);
        }

        .verify-input.mono {
            font-family: 'DM Mono', ui-monospace, monospace;
        }

        .verify-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 18px 0;
            color: var(--text3, #94a3b8);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .verify-divider::before,
        .verify-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border, #e2e8f0);
        }

        .verify-hint {
            font-size: 11px;
            color: var(--text3, #94a3b8);
            margin-top: 6px;
        }

        .verify-submit {
            width: 100%;
            background: var(--navy, #0b1f4b);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            padding: 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: background .15s;
        }

        .verify-submit:hover {
            background: #132a63;
        }

        .verify-submit:disabled {
            opacity: .7;
            cursor: not-allowed;
        }

        .result-card {
            border-radius: 14px;
            padding: 22px;
            border: 1.5px solid;
        }

        .result-card.rc-ok {
            background: #f0fdf9;
            border-color: #a7f3d0;
        }

        .result-card.rc-bad {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .result-card.rc-warn {
            background: #fffbeb;
            border-color: #fde68a;
        }

        .result-card.rc-neutral {
            background: #f8fafc;
            border-color: var(--border, #e2e8f0);
        }

        .result-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .result-title {
            font-weight: 800;
            font-size: 15px;
        }

        .rc-ok .result-title {
            color: #047857;
        }

        .rc-bad .result-title {
            color: #b91c1c;
        }

        .rc-warn .result-title {
            color: #b45309;
        }

        .rc-neutral .result-title {
            color: var(--text2, #475569);
        }

        .result-note {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            font-size: 11.5px;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 12px 0;
        }

        .cert-table {
            width: 100%;
            font-size: 12.5px;
            margin-top: 14px;
            border-collapse: collapse;
        }

        .cert-table tr {
            border-bottom: 1px solid rgba(5, 150, 105, .12);
        }

        .cert-table tr:last-child {
            border-bottom: none;
        }

        .cert-table td {
            padding: 8px 4px;
            vertical-align: top;
        }

        .cert-table td:first-child {
            width: 42%;
            font-weight: 600;
            color: #047857;
        }

        .cert-table td:last-child {
            color: var(--text1, #0f172a);
        }

        .cert-table td.mono {
            font-family: 'DM Mono', ui-monospace, monospace;
        }

        /*  DARK MODE  */
        [data-theme="dark"] .verify-modal-overlay {
            background: rgba(0, 0, 0, .65);
        }

        [data-theme="dark"] .verify-modal {
            background: var(--card, #111827);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .55);
        }

        [data-theme="dark"] .verify-modal-title {
            color: var(--text1, #f1f5f9);
        }

        [data-theme="dark"] .verify-modal-close {
            background: var(--surface, #1e293b);
            color: var(--text3, #64748b);
        }

        [data-theme="dark"] .verify-modal-close:hover {
            background: var(--border, #334155);
            color: var(--text1, #f1f5f9);
        }

        [data-theme="dark"] .verify-label {
            color: var(--text3, #94a3b8);
        }

        [data-theme="dark"] .verify-input {
            background: var(--surface, #1e293b);
            border-color: var(--border, #334155);
            color: var(--text1, #f1f5f9);
        }

        [data-theme="dark"] .verify-input::placeholder {
            color: var(--text3, #64748b);
        }

        [data-theme="dark"] .verify-input:focus {
            border-color: var(--blue-lt, #3b82f6);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .25);
        }

        [data-theme="dark"] .verify-divider {
            color: var(--text3, #64748b);
        }

        [data-theme="dark"] .verify-divider::before,
        [data-theme="dark"] .verify-divider::after {
            background: var(--border, #334155);
        }

        [data-theme="dark"] .verify-hint {
            color: var(--text3, #64748b);
        }

        [data-theme="dark"] .verify-submit {
            background: var(--blue, #2563eb);
        }

        [data-theme="dark"] .verify-submit:hover {
            background: #1d4ed8;
        }

        [data-theme="dark"] .result-card.rc-ok {
            background: rgba(16, 185, 129, .08);
            border-color: rgba(16, 185, 129, .35);
        }

        [data-theme="dark"] .result-card.rc-bad {
            background: rgba(239, 68, 68, .08);
            border-color: rgba(239, 68, 68, .35);
        }

        [data-theme="dark"] .result-card.rc-warn {
            background: rgba(245, 158, 11, .08);
            border-color: rgba(245, 158, 11, .35);
        }

        [data-theme="dark"] .result-card.rc-neutral {
            background: var(--surface, #1e293b);
            border-color: var(--border, #334155);
        }

        [data-theme="dark"] .rc-ok .result-title {
            color: #34d399;
        }

        [data-theme="dark"] .rc-bad .result-title {
            color: #f87171;
        }

        [data-theme="dark"] .rc-warn .result-title {
            color: #fbbf24;
        }

        [data-theme="dark"] .rc-neutral .result-title {
            color: var(--text2, #cbd5e1);
        }

        [data-theme="dark"] .result-card.rc-ok p {
            color: #a7f3d0;
        }

        [data-theme="dark"] .result-card.rc-bad p {
            color: #fecaca;
        }

        [data-theme="dark"] .result-card.rc-warn p {
            color: #fde68a;
        }

        [data-theme="dark"] .result-card.rc-neutral p {
            color: var(--text2, #cbd5e1);
        }

        [data-theme="dark"] .result-note {
            background: rgba(245, 158, 11, .1);
            border-color: rgba(245, 158, 11, .3);
            color: #fbbf24;
        }

        [data-theme="dark"] .cert-table tr {
            border-bottom-color: rgba(16, 185, 129, .2);
        }

        [data-theme="dark"] .cert-table td:first-child {
            color: #34d399;
        }

        [data-theme="dark"] .cert-table td:last-child {
            color: var(--text1, #f1f5f9);
        }

        [data-theme="dark"] {
            --hover-row: rgba(148, 163, 184, .12);
            --selected-row: rgba(59, 130, 246, .18);
        }

        [data-theme="dark"] .status-badge {
            background: transparent !important;
        }

        [data-theme="dark"] select[name="evaluator_id"] {
            background: var(--surface, #1e293b) !important;
            color: var(--text1, #f1f5f9) !important;
            border-color: var(--border, #334155) !important;
        }

        [data-theme="dark"] select[name="evaluator_id"] option {
            background: var(--surface, #1e293b);
            color: var(--text1, #f1f5f9);
        }

        [data-theme="dark"] .app-avatar {
            background: rgba(99, 102, 241, .18);
            color: #a5b4fc;
        }

        [data-theme="dark"] .app-name,
        [data-theme="dark"] .app-tab.active {
            color: #93c5fd;
        }

        [data-theme="dark"] .app-tab.active {
            border-bottom-color: #60a5fa;
        }

        [data-theme="dark"] .app-tab.active .tab-count {
            background: #60a5fa;
            color: #0b1f4b;
        }

        [data-theme="dark"] .pg-btn.pg-active {
            background: var(--blue, #2563eb);
            color: #fff;
        }

        /* Dark-mode scrollbars */
        html[data-theme="dark"],
        html[data-theme="dark"] body,
        html[data-theme="dark"] * {
            scrollbar-color: #475569 #111827;
            scrollbar-width: thin;
        }

        html[data-theme="dark"]::-webkit-scrollbar,
        html[data-theme="dark"] body::-webkit-scrollbar,
        html[data-theme="dark"] *::-webkit-scrollbar {
            width: 9px;
            height: 9px;
        }

        html[data-theme="dark"]::-webkit-scrollbar-track,
        html[data-theme="dark"] body::-webkit-scrollbar-track,
        html[data-theme="dark"] *::-webkit-scrollbar-track {
            background: #111827;
        }

        html[data-theme="dark"]::-webkit-scrollbar-thumb,
        html[data-theme="dark"] body::-webkit-scrollbar-thumb,
        html[data-theme="dark"] *::-webkit-scrollbar-thumb {
            background: #475569;
            border: 2px solid #111827;
            border-radius: 999px;
        }

        html[data-theme="dark"]::-webkit-scrollbar-thumb:hover,
        html[data-theme="dark"] body::-webkit-scrollbar-thumb:hover,
        html[data-theme="dark"] *::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body class="bg-[var(--bg,#f4f6fb)]">

    <!-- TOP BAR (unchanged) -->
    <header class="topbar">
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
            <div class="tb-btn"><i class="fas fa-bell"></i><span class="notif-dot"></span></div>
            <?php include __DIR__ . '/avatar-menu.php'; ?>
        </div>
    </header>

    <!-- PAGE HEADER -->
    <div class="subbar">
        <div class="subbar-top flex items-center justify-between gap-4 flex-wrap">
            <div class="subbar-greeting">
                <h2>Applications Management</h2>
                <p><span id="totalCount"><?= $total_filtered ?></span> Total Applications &nbsp;|&nbsp; Page <span id="currentPage"><?= $page ?></span> of <span id="totalPages"><?= $total_pages ?></span> &nbsp;|&nbsp;
                    <?= $status_filter ? '• ' . ucfirst(str_replace('_', ' ', $status_filter)) : '• All Applications' ?></p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" onclick="openVerifyModal()" class="filter-pill flex-shrink-0" style="text-decoration:none;cursor:pointer;border:none">
                    <i class="fa-solid fa-shield-halved" style="color:#059669"></i>
                    <span style="font-weight:600;font-size:11px">Verify Certificate</span>
                </button>
                <a href="dashboard.php" class="filter-pill flex-shrink-0" style="text-decoration:none">
                    <i class="fas fa-house" style="color:var(--blue-lt)"></i>
                    <span style="font-weight:600;font-size:11px">Back to Dashboard</span>
                </a>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <?php if ($error): ?>
            <div class="flex items-center gap-2.5 bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-xl mb-5 text-sm">
                <i class="fas fa-circle-exclamation text-red-500"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- SUMMARY CARDS -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            <div class="sum-card" style="border-left-color:#f59e0b">
                <div class="sum-label">Needs action</div>
                <div class="sum-value" id="sum-action"><?= $sum_action ?></div>
                <div class="sum-note">Pending + resubmitted</div>
            </div>
            <div class="sum-card" style="border-left-color:#a78bfa">
                <div class="sum-label">Under evaluation</div>
                <div class="sum-value" id="sum-eval"><?= $stats['under_evaluation'] ?></div>
                <div class="sum-note">With evaluators</div>
            </div>
            <div class="sum-card" style="border-left-color:#10b981">
                <div class="sum-label">Approved</div>
                <div class="sum-value" id="sum-approved"><?= $stats['approved'] ?></div>
                <div class="sum-note"><span id="sum-approved-pct"><?= $sum_approved_pct ?></span>% of all applications</div>
            </div>
            <div class="sum-card" style="border-left-color:#ef4444">
                <div class="sum-label">Disapproved</div>
                <div class="sum-value" id="sum-disapproved"><?= $stats['disapproved'] ?></div>
                <div class="sum-note"><span id="sum-disapproved-pct"><?= $sum_disapproved_pct ?></span>% of all applications</div>
            </div>
        </div>

        <!-- APPLICATIONS CARD -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">

            <!-- Heading + search + filters -->
            <div class="flex flex-col gap-3 p-4 sm:p-5">
                <div class="card-title">
                    <span class="ctdot" style="background:var(--indigo)"></span>
                    <span id="cardHeading"><?= $status_filter ? ucfirst(str_replace('_', ' ', $status_filter)) . ' Applications' : 'All Applications' ?></span>
                </div>

                <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                    <div class="relative flex-1">
                        <i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-[13px] text-[var(--text3)]"></i>
                        <input
                            type="text"
                            id="app-search-input"
                            value="<?= htmlspecialchars($search_filter) ?>"
                            placeholder="Search by applicant name, Gmail, RSF #, country, or handling…"
                            autocomplete="off"
                            aria-label="Search applications"
                            class="w-full rounded-xl border border-[var(--border)] bg-[var(--card)] py-2.5 pl-11 pr-10 text-[13px] font-medium text-[var(--text1)] outline-none transition focus:border-[var(--navy)] focus:ring-1 focus:ring-[var(--navy)]">
                        <button type="button" id="app-search-clear"
                            class="absolute right-3.5 top-1/2 -translate-y-1/2 text-[12px] text-[var(--text3)] hover:text-[var(--text1)]"
                            title="Clear search" aria-label="Clear search" style="<?= $search_filter === '' ? 'display:none' : '' ?>">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="filter-scroll flex items-center gap-2 flex-wrap">
                        <label class="filter-pill">
                            <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                            <select id="filter-month">
                                <option value="">All Time</option>
                                <?php
                                $cur_year = (int) date('Y');
                                for ($m = 1; $m <= 12; $m++) {
                                    $val = $cur_year . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                                    $lbl = date('M Y', mktime(0, 0, 0, $m, 1, $cur_year));
                                    $sel = ($val === $month_filter) ? 'selected' : '';
                                    echo "<option value=\"$val\" $sel>$lbl</option>\n";
                                }
                                ?>
                            </select>
                        </label>
                        <label class="filter-pill">
                            <i class="fas fa-globe" style="color:var(--indigo)"></i>
                            <select id="filter-country">
                                <option value="">All Destinations</option>
                                <?php foreach ($countries as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= $c === $country_filter ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Status tabs (same links as the former KPI cards) -->
            <nav class="flex overflow-x-auto no-scrollbar border-b border-[var(--border)] px-2 sm:px-4" aria-label="Filter by status">
                <?php foreach ($kpi_defs as $kpi):
                    $is_active = ($kpi['key'] === '' && $status_filter === '')
                        || ($kpi['key'] !== '' && $status_filter === $kpi['key'])
                        || ($kpi['key'] === 'unassign' && in_array($status_filter, ['unassign', 'unassigned'], true));
                    $kpi_qs = build_admin_apps_qs(
                        $kpi['key'] !== '' ? $kpi['key'] : null,
                        $month_filter,
                        $country_filter,
                        $search_filter
                    );
                ?>
                    <a href="applications.php<?= $kpi_qs ?>" class="app-tab <?= $is_active ? 'active' : '' ?>" <?= $is_active ? 'aria-current="page"' : '' ?>>
                        <i class="fas <?= $kpi['icon'] ?> text-[11px]" style="color:<?= $kpi['color'] ?>"></i>
                        <?= $kpi['label'] ?>
                        <span class="tab-count" id="kpi-count-<?= $kpi['key'] !== '' ? $kpi['key'] : 'all' ?>"><?= $kpi['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Table -->
            <div class="overflow-x-auto no-scrollbar">
                <table class="apps-table">
                    <colgroup>
                        <col style="width:22%">
                        <col style="width:22%">
                        <col style="width:11%">
                        <col style="width:15%">
                        <col style="width:14%">
                        <col style="width:16%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Position / RSF</th>
                            <th class="!text-center">Status</th>
                            <th>Handling</th>
                            <th>Submitted / Updated</th>
                            <th class="!text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="appsTbody" class="divide-y divide-[var(--border)]">
                        <?= $tbody_html ?>
                    </tbody>
                </table>
            </div>
            <div id="paginationWrap">
                <?= $pagination_html ?>
            </div>
        </div>

        <!-- Verify Certificate Modal -->
        <div id="verifyModalOverlay" class="verify-modal-overlay" onclick="if(event.target===this) closeVerifyModal()">
            <div class="verify-modal">
                <div class="verify-modal-head">
                    <div class="verify-modal-title">
                        <i class="fa-solid fa-shield-halved" style="color:var(--blue,#1d4ed8)"></i>
                        Verify Certificate
                    </div>
                    <button type="button" class="verify-modal-close" onclick="closeVerifyModal()" aria-label="Close">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>

                <form id="verifyModalForm">
                    <input type="hidden" name="action" value="verify_cert">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($verify_csrf_token) ?>">

                    <div class="verify-field">
                        <label class="verify-label">Reference No.</label>
                        <input type="text" name="ref_no" id="verifyRefNo" placeholder="APP-2026-000060" class="verify-input mono">
                    </div>

                    <div class="verify-field">
                        <label class="verify-label">Verification Code</label>
                        <input type="text" name="verify_code" id="verifyCode" placeholder="16-character code from the certificate" class="verify-input mono" style="text-transform:uppercase">
                    </div>

                    <div class="verify-divider">or</div>

                    <div class="verify-field" style="margin-bottom:16px">
                        <label class="verify-label">Paste QR Text</label>
                        <input type="text" name="qr_text" placeholder="Ref:APP-2026-000060 Code:XXXXXXXXXXXXXXXX" class="verify-input mono">
                        <p class="verify-hint">Scan the QR on the certificate with any camera app, then paste the result here.</p>
                    </div>

                    <button type="submit" class="verify-submit" id="verifySubmitBtn">
                        <i class="fa-solid fa-shield-halved" style="margin-right:6px"></i> Verify Certificate
                    </button>
                </form>

                <div id="verifyModalResult"></div>
            </div>
        </div>

        <!-- FLOATING DOCK (unchanged) -->
        <div class="dock-wrap">
            <nav class="dock">
                <a href="dashboard.php" class="dk" data-tip="Dashboard">
                    <i class="fas fa-house"></i>
                    <span class="dk-lbl">Home</span>
                </a>
                <div class="dk active" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                    <?php
                    // Dynamic badge count
                    $badge_stmt = $database->prepare("
                SELECT COUNT(*) as total 
                FROM applications 
                WHERE status IN ('pending', 'for_compliance', 'resubmitted')
            ");
                    $badge_stmt->execute();
                    $badge_result = $badge_stmt->get_result()->fetch_assoc();
                    $active_apps = $badge_result['total'] ?? 0;
                    ?>
                    <span class="dk-badge"><?= $active_apps ?></span>
                    <i class="fas fa-file-lines"></i>
                    <span class="dk-lbl">Apps</span>
                    <div class="flyout active" id="appsMenu">
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

        <script>
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

            document.addEventListener('click', function(e) {
                const link = e.target.closest('.js-cert-pdf-link');
                if (!link) return;

                e.preventDefault();
                const url = link.getAttribute('href');

                Swal.fire({
                    title: 'Opening Document…',
                    text: 'Please wait while the certificate PDF loads.',
                    icon: 'info',
                    timer: 1200,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                }).then(() => {
                    window.open(url, '_blank', 'noopener');
                });
            });

            function openVerifyModal() {
                const overlay = document.getElementById('verifyModalOverlay');
                const form = document.getElementById('verifyModalForm');
                form.reset();
                document.getElementById('verifyModalResult').innerHTML = '';
                overlay.classList.add('open');
                document.body.style.overflow = 'hidden';
            }

            function closeVerifyModal() {
                document.getElementById('verifyModalOverlay').classList.remove('open');
                document.body.style.overflow = '';
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeVerifyModal();
            });

            document.getElementById('verifyModalForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = document.getElementById('verifySubmitBtn');
                const resultBox = document.getElementById('verifyModalResult');
                const originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px"></i> Verifying…';

                try {
                    const res = await fetch('applications.php', {
                        method: 'POST',
                        body: new FormData(this)
                    });
                    const data = await res.json();
                    resultBox.innerHTML = data.html;
                } catch (err) {
                    resultBox.innerHTML = '<div class="result-card rc-neutral" style="margin-top:16px;text-align:center"><div class="result-title">Something went wrong</div><p style="font-size:12.5px;color:var(--text2,#475569)">Please try again.</p></div>';
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            });

            function updateKpiCounts(stats) {
                if (!stats) return;
                Object.keys(stats).forEach(key => {
                    const el = document.getElementById('kpi-count-' + key);
                    if (el) el.textContent = stats[key];
                });

                // Summary cards (derived from the same stats payload)
                const set = (id, v) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = v;
                };
                const total = Number(stats.all) || 0;
                const pct = n => total > 0 ? Math.round((Number(n) || 0) / total * 100) : 0;
                set('sum-action', (Number(stats.pending) || 0) + (Number(stats.resubmitted) || 0));
                set('sum-eval', stats.under_evaluation);
                set('sum-approved', stats.approved);
                set('sum-disapproved', stats.disapproved);
                set('sum-approved-pct', pct(stats.approved));
                set('sum-disapproved-pct', pct(stats.disapproved));
            }

            document.addEventListener('submit', async function(e) {
                const form = e.target.closest('form');
                if (!form) return;
                const actionInput = form.querySelector('input[name="action"]');
                if (!actionInput || actionInput.value !== 'reassign') return;

                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                const params = new URLSearchParams(window.location.search);
                params.set('ajax', '1');

                try {
                    const res = await fetch('applications.php?' + params.toString(), {
                        method: 'POST',
                        body: new FormData(form)
                    });
                    const data = await res.json();

                    document.getElementById('appsTbody').innerHTML = data.tbody;
                    document.getElementById('paginationWrap').innerHTML = data.pagination;
                    enhanceAllReassignSelects();
                    document.getElementById('totalCount').textContent = data.total;
                    document.getElementById('currentPage').textContent = data.page;
                    document.getElementById('totalPages').textContent = data.total_pages;
                    document.getElementById('cardHeading').textContent = data.heading;
                    updateKpiCounts(data.stats);


                    if (data.success_type === 'reassign' && data.success) {
                        Swal.fire({
                            title: 'Reassigned!',
                            text: data.success,
                            icon: 'success',
                            confirmButtonColor: '#d97706'
                        });
                    } else if (data.error) {
                        Swal.fire({
                            title: 'Reassignment failed',
                            text: data.error,
                            icon: 'error',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                } catch (err) {
                    Swal.fire({
                        title: 'Something went wrong',
                        text: 'Please try again.',
                        icon: 'error'
                    });
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        </script>
        <script type="module" src="../js/main.js"></script>
        <script>
            // No-refresh pagination: fetches just the table rows + pagination
            // strip via the ?ajax=1 JSON branch above, and swaps them in place.
            async function loadAppsPage(url) {
                const target = new URL(url, window.location.href);
                target.searchParams.set('ajax', '1');

                const tbody = document.getElementById('appsTbody');
                tbody.style.opacity = '0.5';

                try {
                    const res = await fetch(target.toString());
                    const data = await res.json();

                    document.getElementById('appsTbody').innerHTML = data.tbody;
                    document.getElementById('paginationWrap').innerHTML = data.pagination;
                    enhanceAllReassignSelects();
                    document.getElementById('totalCount').textContent = data.total;
                    document.getElementById('currentPage').textContent = data.page;
                    document.getElementById('totalPages').textContent = data.total_pages;
                    document.getElementById('cardHeading').textContent = data.heading;


                    const cleanUrl = new URL(url, window.location.href);
                    cleanUrl.searchParams.delete('ajax');
                    history.pushState({}, '', cleanUrl);
                } catch (e) {
                    window.location.href = url; // fallback to a normal navigation on failure
                } finally {
                    tbody.style.opacity = '1';
                }
            }
            document.addEventListener('click', function(e) {
                const link = e.target.closest('#paginationWrap a');
                if (link) {
                    e.preventDefault();
                    loadAppsPage(link.getAttribute('href'));
                }
            });

            window.addEventListener('popstate', function() {
                loadAppsPage(window.location.href);
            });

            // Live search — same no-refresh path as pagination, debounced as
            // the admin types. Preserves the current status filter, always
            // resets to page 1 since the result set changes.
            const searchInput = document.getElementById('app-search-input');
            const searchClearBtn = document.getElementById('app-search-clear');
            let searchDebounce = null;

            function currentStatusFilter() {
                return new URL(window.location.href).searchParams.get('status') || '';
            }

            function currentMonthFilter() {
                return document.getElementById('filter-month')?.value ||
                    new URL(window.location.href).searchParams.get('month') || '';
            }

            function currentCountryFilter() {
                return document.getElementById('filter-country')?.value ||
                    new URL(window.location.href).searchParams.get('country') || '';
            }

            function runAppsSearch() {
                const term = searchInput.value.trim();
                searchClearBtn.style.display = term ? '' : 'none';

                const params = new URLSearchParams();
                const status = currentStatusFilter();
                const month = currentMonthFilter();
                const country = currentCountryFilter();
                if (status) params.set('status', status);
                if (term) params.set('q', term);
                if (month) params.set('month', month);
                if (country) params.set('country', country);
                params.set('page', '1');

                loadAppsPage('applications.php?' + params.toString());
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    searchClearBtn.style.display = searchInput.value ? '' : 'none';
                    clearTimeout(searchDebounce);
                    searchDebounce = setTimeout(runAppsSearch, 300);
                });
            }

            if (searchClearBtn) {
                searchClearBtn.addEventListener('click', () => {
                    searchInput.value = '';
                    searchClearBtn.style.display = 'none';
                    clearTimeout(searchDebounce);
                    runAppsSearch();
                });
            }

            function navigateWithSubbarFilters() {
                const params = new URLSearchParams();
                const status = currentStatusFilter();
                const term = searchInput ? searchInput.value.trim() : '';
                if (status) params.set('status', status);
                if (term) params.set('q', term);

                const month = document.getElementById('filter-month')?.value;
                const country = document.getElementById('filter-country')?.value;
                if (month) params.set('month', month);
                if (country) params.set('country', country);

                window.location.href = 'applications.php' + (params.toString() ? '?' + params.toString() : '');
            }

            document.getElementById('filter-month')?.addEventListener('change', navigateWithSubbarFilters);
            document.getElementById('filter-country')?.addEventListener('change', navigateWithSubbarFilters);

            /* Pill-style dropdowns for Month & Country — same pattern as
               evaluator/applications.php. Native <select> stays hidden so
               the change listeners above keep working. */
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
                panel.style.cssText = 'position:fixed;background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:8px;box-shadow:0 10px 30px rgba(11,31,75,.16);z-index:999;overflow:hidden;padding:4px 0;max-height:240px;overflow-y:auto;width:224px;';
                panel.classList.add('hidden');
                document.body.appendChild(panel);

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 12px;font-size:12px;color:var(--text1,#0f172a);cursor:pointer;';
                    row.dataset.value = optionEl.value;
                    row.addEventListener('mouseenter', () => row.style.background = 'var(--hover-row, var(--surface2,#f8fafc))');
                    row.addEventListener('mouseleave', () => row.style.background = row.dataset.selected === '1' ? 'var(--selected-row, #eff6ff)' : '');
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
                        row.style.background = selected ? 'var(--selected-row, #eff6ff)' : '';
                        row.style.color = selected ? 'var(--blue,#1d4ed8)' : 'var(--text1,#0f172a)';
                        row.style.fontWeight = selected ? '700' : '400';
                        let check = row.querySelector('.fa-check');
                        if (selected && !check) {
                            const icon = document.createElement('i');
                            icon.className = 'fas fa-check';
                            icon.style.cssText = 'font-size:10px;color:var(--blue-lt,#3b82f6);flex-shrink:0;';
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
                        if (p !== panel) {
                            p.classList.add('hidden');
                            if (p.style.display === 'block') p.style.display = 'none';
                        }
                    });
                    renderList();
                    positionPanel();
                    panel.style.display = '';
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
        </script>
        <script>
            function cleanupReassignPanels() {
                // Old panels were appended to <body>, not inside #appsTbody, so
                // replacing the tbody's innerHTML does NOT remove them — they'd be
                // left floating forever unless cleared explicitly.
                document.querySelectorAll('.rs-panel').forEach(p => p.remove());
            }

            function enhanceReassignSelect(select) {
                if (!select || select.dataset.enhanced === '1') return;
                select.dataset.enhanced = '1';

                const form = select.closest('form');
                const placeholderOpt = select.querySelector('option[value=""]');
                const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

                const wrapper = document.createElement('div');
                wrapper.style.cssText = 'position:relative;flex:1;min-width:180px;';
                select.parentNode.insertBefore(wrapper, select);
                wrapper.appendChild(select);
                select.style.display = 'none';
                select.tabIndex = -1;
                select.removeAttribute('required');

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.style.cssText = 'width:100%;border:1px solid var(--border);background:var(--surface,#fff);border-radius:10px;padding:9px 12px;font-size:12.5px;color:var(--text1);display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;transition:border-color .15s;';
                trigger.innerHTML = '<span class="rs-trigger-label" style="color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>' +
                    '<i class="fas fa-chevron-down" style="font-size:10px;color:var(--text3);transition:transform .15s;flex-shrink:0;"></i>';
                wrapper.appendChild(trigger);

                const panel = document.createElement('div');
                panel.className = 'rs-panel';
                panel.setAttribute('role', 'listbox');
                panel.style.cssText = 'display:none;position:fixed;background:var(--surface,#fff);border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 30px rgba(11,31,75,.2);z-index:99999;overflow:hidden;padding:4px 0;max-height:220px;overflow-y:auto;';
                document.body.appendChild(panel);

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;font-size:12.5px;color:var(--text1);cursor:pointer;';
                    row.dataset.value = optionEl.value;
                    row.addEventListener('mouseenter', () => row.style.background = 'var(--hover-row, #f8fafc)');
                    row.addEventListener('mouseleave', () => row.style.background = row.dataset.selected === '1' ? 'var(--selected-row, #eff6ff)' : '');
                    const label = document.createElement('span');
                    label.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                    label.textContent = optionEl.textContent;
                    row.appendChild(label);
                    row.addEventListener('click', () => {
                        select.value = (select.value === optionEl.value) ? '' : optionEl.value;
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
                    Array.from(select.options).forEach(opt => {
                        if (opt.value === '') return;
                        panel.appendChild(buildRow(opt));
                    });
                    markSelected();
                }

                function markSelected() {
                    panel.querySelectorAll('[role="option"]').forEach(row => {
                        const selected = row.dataset.value === select.value;
                        row.dataset.selected = selected ? '1' : '0';
                        row.style.background = selected ? 'var(--selected-row, #eff6ff)' : '';
                        row.style.color = selected ? 'var(--blue)' : 'var(--text1)';
                        row.style.fontWeight = selected ? '700' : '400';
                        let check = row.querySelector('.fa-check');
                        if (selected && !check) {
                            const icon = document.createElement('i');
                            icon.className = 'fas fa-check';
                            icon.style.cssText = 'font-size:10px;color:var(--blue);flex-shrink:0;';
                            row.appendChild(icon);
                        } else if (!selected && check) {
                            check.remove();
                        }
                    });
                }

                function refresh() {
                    const opt = select.options[select.selectedIndex];
                    const label = trigger.querySelector('.rs-trigger-label');
                    if (select.value === '' || !opt) {
                        label.textContent = placeholder;
                        label.style.color = 'var(--text3)';
                        label.style.fontWeight = '400';
                    } else {
                        label.textContent = opt.textContent;
                        label.style.color = 'var(--text1)';
                        label.style.fontWeight = '600';
                    }
                    trigger.style.borderColor = 'var(--border)';
                    markSelected();
                }

                function positionPanel() {
                    const rect = trigger.getBoundingClientRect();
                    panel.style.width = rect.width + 'px';
                    panel.style.top = (rect.bottom + 6) + 'px';
                    let left = rect.left;
                    const panelWidth = rect.width;
                    const maxLeft = window.innerWidth - panelWidth - 8;
                    if (left > maxLeft) left = Math.max(8, maxLeft);
                    panel.style.left = left + 'px';
                }

                function onReposition() {
                    if (panel.style.display === 'block') positionPanel();
                }

                function openPanel() {
                    document.querySelectorAll('.rs-panel').forEach(p => {
                        if (p !== panel) p.style.display = 'none';
                    });
                    renderList();
                    positionPanel();
                    panel.style.display = 'block';
                    trigger.style.borderColor = 'var(--blue-lt, #3b82f6)';
                    trigger.setAttribute('aria-expanded', 'true');
                    trigger.querySelector('.fa-chevron-down').style.transform = 'rotate(180deg)';
                    window.addEventListener('scroll', onReposition, true);
                    window.addEventListener('resize', onReposition);
                }

                function closePanel() {
                    panel.style.display = 'none';
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.querySelector('.fa-chevron-down').style.transform = '';
                    window.removeEventListener('scroll', onReposition, true);
                    window.removeEventListener('resize', onReposition);
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

                refresh();

                if (form) {
                    form.addEventListener('submit', (e) => {
                        if (select.value === '') {
                            e.preventDefault();
                            trigger.style.borderColor = '#dc2626';
                            openPanel();
                            Swal.fire({
                                title: 'Select an evaluator',
                                text: 'Please choose an evaluator to reassign this case to.',
                                icon: 'warning',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    });
                }
            }

            // Only enhance selects that belong to a reassign form (action=reassign),
            // leaving the plain "Assign" select (for unassign-status rows) alone —
            // scoped by form action rather than a hardcoded selector so it works
            // regardless of DOM order or how many resubmitted rows exist.
            function enhanceAllReassignSelects() {
                cleanupReassignPanels();
                document.querySelectorAll('#appsTbody form').forEach(form => {
                    const actionInput = form.querySelector('input[name="action"]');
                    if (actionInput && actionInput.value === 'reassign') {
                        enhanceReassignSelect(form.querySelector('select[name="evaluator_id"]'));
                    }
                });
            }

            document.addEventListener('click', () => {
                document.querySelectorAll('.rs-panel').forEach(p => p.style.display = 'none');
            });

            enhanceAllReassignSelects();
        </script>
        <script src="../js/theme.js"></script>
        <script src="../js/avatar-menu.js"></script>
</body>

</html>
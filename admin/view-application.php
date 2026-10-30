<?php
// admin/view-application.php – View application, browse documents & comments,
// and handle the for_compliance workflow (Reopen / Evaluate Directly / Reassign)
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';
require_once '../includes/matching.php';
require_once '../includes/status_email.php';
require_once '../includes/activity_logger.php';


if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$app_id = (int) ($_GET['id'] ?? 0);
if (!$app_id) {
    header('Location: applications.php');
    exit;
}
$applicant_name = 'the applicant';
$stmt_an = $database->prepare("
    SELECT CONCAT(c.first_name, ' ', c.last_name) AS applicant_name
    FROM applications a
    JOIN clients c ON a.client_id = c.cid
    WHERE a.id = ?
    LIMIT 1
");
$stmt_an->bind_param("i", $app_id);
$stmt_an->execute();
$an_row = $stmt_an->get_result()->fetch_assoc();
$stmt_an->close();
if (!empty($an_row['applicant_name'])) {
    $applicant_name = trim($an_row['applicant_name']);
}

$admin_email = $_SESSION['user_email'];

// Get admin aid + name
$admin_name = 'Admin';
$admin_id   = null;
$stmt_ad = $database->prepare("SELECT aid, full_name FROM admins WHERE email = ? LIMIT 1");
$stmt_ad->bind_param("s", $admin_email);
$stmt_ad->execute();
$ad_result = $stmt_ad->get_result();
if ($ad_result->num_rows === 1) {
    $row = $ad_result->fetch_assoc();
    $admin_id   = (int) $row['aid'];
    $admin_name = $row['full_name'] ?? 'Admin';
}
$stmt_ad->close();

// Avatar initials
$parts    = explode(' ', trim($admin_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($admin_name, 0, 2));

$page_error   = '';
$page_success = '';

// ── Add a document comment (admin) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_doc_comment') {
    $is_ajax     = ($_POST['ajax'] ?? '') === '1';
    $document_id = (int) ($_POST['document_id'] ?? 0);
    $doc_comment = trim($_POST['doc_comment'] ?? '');

    $fail = function (string $msg) use ($is_ajax, &$page_error) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        $page_error = $msg;
    };

    if ($document_id <= 0 || $doc_comment === '') {
        $fail('Please enter a comment before submitting.');
    } else {
        $stmt = $database->prepare("SELECT 1 FROM application_documents WHERE id = ? AND application_id = ? LIMIT 1");
        $stmt->bind_param("ii", $document_id, $app_id);
        $stmt->execute();
        $doc_exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$doc_exists) {
            $fail('Selected document was not found for this application.');
        } else {
            $stmt = $database->prepare("INSERT INTO document_comments (document_id, commented_by, comment) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $document_id, $admin_email, $doc_comment);
            if ($stmt->execute()) {
                $stmt->close();
                log_activity(
                    $database,
                    $admin_email,
                    'admin',
                    'document',
                    'comment',
                    "Commented on document #{$document_id} of {$applicant_name}'s application",
                    $app_id
                );
                notify_client_for_application(
                    $database,
                    $app_id,
                    'New Document Comment',
                    'Admin added a comment to one of your uploaded documents. Please review your application details.',
                    'document_comment'
                );
                notify_evaluator_for_application(
                    $database,
                    $app_id,
                    'New Document Comment from Admin',
                    "Admin commented on a document for {$applicant_name}'s application. Please review the application details.",
                );

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success'         => true,
                        'document_id'     => $document_id,
                        'commenter_name'  => $admin_name,
                        'commenter_role'  => 'admin',
                        'comment'         => nl2br(htmlspecialchars($doc_comment)),
                        'created_at'      => date('M d, Y g:i a'),
                    ]);
                    exit;
                }
                header("Location: view-application.php?id=$app_id&doc_comment=1");
                exit;
            }
            $stmt->close();
            $fail('Failed to save document comment.');
        }
    }
}

// ── Reopen to original evaluator ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen') {
    if (adminReopenToEvaluator($database, $app_id, $admin_email)) {
        log_activity(
            $database,
            $admin_email,
            'admin',
            'application',
            'reopen',
            "Reopened {$applicant_name}'s application to the original evaluator",
            $app_id
        );
        notify_evaluator_for_application(
            $database,
            $app_id,
            'Application Reopened',
            "{$applicant_name}'s application has been reopened by Admin and returned to your queue. Please review the application details."
        );
        notify_client_for_application(
            $database,
            $app_id,
            'Application Under Review Again',
            'Your application has been returned to the evaluator for continued review.'
        );
        header("Location: view-application.php?id=$app_id&reopened=1");
        exit;
    }
    $page_error = 'Could not reopen this application — the applicant must resubmit their changes before it can be reopened, or no evaluator is on record for it.';
}

// ── Evaluate directly ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'evaluate_directly') {
    $decision = trim($_POST['decision'] ?? '');
    $comments = trim($_POST['comments'] ?? '');
    if (!$admin_id) {
        $page_error = 'Your admin account could not be identified.';
    } elseif (!in_array($decision, ['approved', 'disapproved'], true)) {
        $page_error = 'Please select a valid decision.';
    } elseif (adminEvaluateDirectly($database, $app_id, $admin_id, $admin_email, $decision, $comments ?: null)) {
        $decisionLabel = $decision === 'approved' ? 'Approved' : 'Disapproved';
        log_activity(
            $database,
            $admin_email,
            'admin',
            'application',
            $decision,
            "Admin directly marked {$applicant_name}'s application as {$decisionLabel}"
                . ($comments ? ' | Note: ' . mb_substr($comments, 0, 300) : ''),
            $app_id
        );
        notify_client_for_application(
            $database,
            $app_id,
            "Application {$decisionLabel}",
            "Your application status is now {$decisionLabel}." . ($comments ? " Admin note: {$comments}" : '')
        );
        send_application_status_email($database, $app_id, $decision, $comments);
        notify_evaluator_for_application(
            $database,
            $app_id,
            "Application {$decisionLabel} by Admin",
            "{$applicant_name}'s application was evaluated directly by Admin and marked {$decisionLabel}."
        );
        header("Location: view-application.php?id=$app_id&success=1&decision=$decision");
        exit;
    } else {
        $page_error = 'Could not evaluate this application directly — the applicant must resubmit their changes first.';
    }
}

// ── Reassign to a different evaluator ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign') {
    $new_evaluator_id = (int) ($_POST['evaluator_id'] ?? 0);

    // Reject deactivated evaluators even if the ID was submitted directly
    $evaluator_is_active = false;
    if ($new_evaluator_id) {
        $chk = $database->prepare("
            SELECT 1
            FROM evaluators e
            JOIN webuser w ON w.email = e.email
            WHERE e.eid = ? AND w.is_active = 1
            LIMIT 1
        ");
        $chk->bind_param("i", $new_evaluator_id);
        $chk->execute();
        $evaluator_is_active = $chk->get_result()->num_rows > 0;
        $chk->close();
    }

    if ($new_evaluator_id && $evaluator_is_active && adminReassignApplication($database, $app_id, $new_evaluator_id, $admin_email)) {
        $ev_stmt = $database->prepare("SELECT full_name FROM evaluators WHERE eid = ?");
        $ev_stmt->bind_param("i", $new_evaluator_id);
        $ev_stmt->execute();
        $ev_name = $ev_stmt->get_result()->fetch_assoc()['full_name'] ?? 'Evaluator';
        $ev_stmt->close();
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
        header("Location: view-application.php?id=$app_id&reassigned=1");
        exit;
    }
    $page_error = 'Reassignment failed — either no active evaluator was selected, or this application is still awaiting applicant resubmission.';
}

// Get application details, including current owner
$stmt = $database->prepare("
        SELECT a.id, a.status, a.updated_at, a.submitted_at, a.notes,
           a.client_id, a.current_evaluator_id, a.handling_party, a.rsf_id,
           r.rsf_number, r.position_title, r.worksite_country, r.employer_name, r.qualifications, r.slots_available,
           CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
           c.email AS applicant_email, c.phone, c.address, c.profile_picture,
           ev.full_name AS current_evaluator_name, ev.email AS current_evaluator_email
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN clients   c ON a.client_id = c.cid
    LEFT JOIN evaluators ev ON a.current_evaluator_id = ev.eid
    WHERE a.id = ?
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    header('Location: applications.php');
    exit;
}

log_activity(
    $database,
    $admin_email,
    'admin',
    'application',
    'view',
    "Viewed {$applicant_name}'s application",
    $app_id
);

// Other jobs this same applicant has applied for (excluding this one and drafts)
$otherApps = [];
$stmt = $database->prepare("
    SELECT a.id, a.status, a.submitted_at,
           r.position_title, r.worksite_country, r.rsf_number
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    WHERE a.client_id = ? AND a.id <> ? AND a.status <> 'draft'
    ORDER BY a.submitted_at DESC
");
$stmt->bind_param("ii", $app['client_id'], $app_id);
$stmt->execute();
$otherApps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Applicant Fit: eligibility + preference match (live-recomputed) ──
$fitData               = computeApplicationFit($database, $app_id);
$fit_client_id         = $fitData['client_id'];
$fit_rsf_id            = $fitData['rsf_id'];
$fitHasQuals           = $fitData['hasQuals'];
$fitEligible           = $fitData['eligible'];
$fitQualChecks         = $fitData['qualChecks'];
$fitHasPreferences     = $fitData['hasPreferences'];
$fitMatchDetails       = $fitData['matchDetails'];

// Work experience details (client_profiles) — kept as its own scoped
// query here rather than folded into computeApplicationFit(), since that
// helper is shared across evaluator/admin views and doesn't currently
// expose these fields.
$workExp = null;
if ($fit_client_id) {
    $stmt = $database->prepare("
        SELECT work_experience_years, last_employer, last_position
        FROM client_profiles
        WHERE client_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $fit_client_id);
    $stmt->execute();
    $workExp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Evaluators for the Reassign dropdown — active accounts only
$evaluators = [];
$res = $database->query("
    SELECT e.eid, e.full_name
    FROM evaluators e
    JOIN webuser w ON w.email = e.email
    WHERE w.is_active = 1
    ORDER BY e.full_name
");
if ($res) $evaluators = $res->fetch_all(MYSQLI_ASSOC);

// Answers
$stmt = $database->prepare("
    SELECT aa.answer, aa.answered_at, q.question_text, q.is_required
    FROM application_answers aa
    JOIN questions q ON aa.question_id = q.id
    WHERE aa.application_id = ?
    ORDER BY q.order_no, q.id
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$answers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Documents
$stmt = $database->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Required documents for this job order — queried independently of
// application_documents so the checklist is visible even before the
// applicant has uploaded anything.
// Required documents for this job order — queried independently of
// application_documents so missing requirements are visible even before
// any upload exists.
$requiredDocs = [];
if (!empty($app['rsf_id'])) {
    $stmt = $database->prepare("
        SELECT id, document_type, document_name, is_required, description
        FROM rsf_required_documents
        WHERE rsf_id = ?
        ORDER BY is_required DESC, document_name
    ");
    $stmt->bind_param("i", $app['rsf_id']);
    $stmt->execute();
    $requiredDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Map uploaded files to requirements: prefer required_doc_id (new uploads),
// fall back to matching by document_type (legacy uploads where
// required_doc_id is NULL, as seen in the current data).
$uploadedByReqId = [];
$uploadedByType  = [];
foreach ($documents as $d) {
    if (!empty($d['required_doc_id'])) {
        $uploadedByReqId[(int) $d['required_doc_id']][] = $d;
    }
    $typeKey = strtolower(trim($d['document_type'] ?? ''));
    if ($typeKey !== '') {
        $uploadedByType[$typeKey][] = $d;
    }
}

// Track which uploaded docs got matched to a requirement, so the
// "extra uploads" fallback list below doesn't show them a second time.
// Track which uploaded docs got matched to a requirement, so the
// "extra uploads" fallback list below doesn't show them a second time.
$matchedDocIds = [];
$metCount = 0;
$requiredCount = 0; // only rows where is_required = 1
foreach ($requiredDocs as $rd) {
    $reqId   = (int) $rd['id'];
    $typeKey = strtolower(trim($rd['document_type'] ?? ''));
    $matches = $uploadedByReqId[$reqId] ?? ($uploadedByType[$typeKey] ?? []);
    $isReq   = !empty($rd['is_required']);
    if ($isReq) {
        $requiredCount++;
        if (!empty($matches)) $metCount++;
    }
    foreach ($matches as $m) {
        $matchedDocIds[(int) $m['id']] = true;
    }
}
$extraDocuments = array_values(array_filter(
    $documents,
    fn($d) => !isset($matchedDocIds[(int) $d['id']])
));

// Document comments (from evaluators AND admins)
$docCommentsByDocumentId = [];
if (!empty($documents)) {
    $stmt = $database->prepare("
        SELECT dc.document_id, dc.comment, dc.created_at, dc.commented_by,
               COALESCE(ev.full_name, ad.full_name, dc.commented_by) AS commenter_name,
               CASE WHEN ad.email IS NOT NULL THEN 'admin' WHEN ev.email IS NOT NULL THEN 'evaluator' ELSE 'other' END AS commenter_role
        FROM document_comments dc
        LEFT JOIN evaluators ev ON dc.commented_by = ev.email
        LEFT JOIN admins ad ON dc.commented_by = ad.email
        WHERE dc.document_id IN (
            SELECT id FROM application_documents WHERE application_id = ?
        )
        ORDER BY dc.created_at DESC
    ");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $commentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($commentRows as $row) {
        $did = (int) ($row['document_id'] ?? 0);
        if (!isset($docCommentsByDocumentId[$did])) {
            $docCommentsByDocumentId[$did] = [];
        }
        $docCommentsByDocumentId[$did][] = $row;
    }
}

// Past evaluations — both evaluator-made and admin-made decisions
$stmt = $database->prepare("
    SELECT e.evaluation_type, e.decision, e.comments, e.evaluated_at,
           COALESCE(ev.full_name, ad.full_name, 'Unknown') AS decided_by,
           CASE WHEN e.admin_id IS NOT NULL THEN 'admin' ELSE 'evaluator' END AS decided_by_role
    FROM evaluations e
    LEFT JOIN evaluators ev ON e.evaluator_id = ev.eid
    LEFT JOIN admins ad ON e.admin_id = ad.aid
    WHERE e.application_id = ?
    ORDER BY e.evaluated_at DESC
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$evaluations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status history
$stmt = $database->prepare("
    SELECT from_status, to_status, changed_by, reason, changed_at
    FROM status_history
    WHERE application_id = ?
    ORDER BY changed_at DESC
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$status_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (isset($_GET['success'])) {
    $decisionParam = strtolower($_GET['decision'] ?? '');
    $page_success = match ($decisionParam) {
        'approved'    => 'Application Approved Successfully',
        'disapproved' => 'Application Disapproved Successfully',
        default       => 'Decision recorded successfully.',
    };
}
if (isset($_GET['reopened'])) $page_success = 'Application reopened and sent back to the evaluator.';
if (isset($_GET['reassigned'])) $page_success = 'Application reassigned successfully.';
if (isset($_GET['doc_comment'])) $page_success = 'Document comment added successfully.';

// Status pill
$status     = strtolower($app['status']);
$statusText = str_replace('_', ' ', ucwords($status));
$statusPill = match ($status) {
    'approved'         => 'sp-approved',
    'under_evaluation' => 'sp-under',
    'for_compliance'   => 'sp-compliance',
    'disapproved'      => 'sp-disapproved',
    'assign'           => 'sp-assigned',
    'pending'          => 'sp-assigned',
    'assigned'         => 'sp-assigned',
    'resubmitted'      => 'sp-under',
    'unassign'         => 'sp-default',
    'unassigned'       => 'sp-default',
    default            => 'sp-default',
};

// Applicant avatar initials
$anp    = explode(' ', trim($app['applicant_name'] ?? ''));
$appAvi = strtoupper(count($anp) >= 2
    ? substr($anp[0], 0, 1) . substr(end($anp), 0, 1)
    : substr($app['applicant_name'] ?? 'NA', 0, 2));
$app_has_pic = !empty($app['profile_picture']);
$app_pic_url = $app_has_pic ? '../' . $app['profile_picture'] : null;

$isForCompliance      = $status === 'for_compliance' && $app['handling_party'] === 'admin';
$isResubmitted        = $status === 'resubmitted';
$showAdminHandoffPanel = $isForCompliance || $isResubmitted;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applications – DMW RO IV-A Admin</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="stylesheet" href="../css/view-application.css">
    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* ════════════════════════════════════════════════════════
           LAYOUT — matches evaluator/view-application.php:
           two-column desktop grid + tighter spacing so the action
           rail stays in view and wide screens don't leave dead space.
        ════════════════════════════════════════════════════════ */

        .main {
            max-width: 1360px;
            margin: 0 auto;
            padding-top: 18px;
        }

        .page-hd {
            margin-bottom: 12px;
        }

        .alert {
            margin-bottom: 12px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 16px;
            align-items: start;
        }

        .col-left,
        .col-right {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-width: 0;
        }

        .col-right {
            position: sticky;
            top: 16px;
        }

        .sec-card,
        .eval-panel {
            margin: 0;
        }

        .sec-card-head {
            padding: 11px 16px;
            gap: 8px;
        }

        .sec-card-body {
            padding: 14px 16px;
        }

        .applicant-hero {
            padding: 14px 16px;
            gap: 12px;
        }

        .dl-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px 18px;
        }

        .dl-full {
            grid-column: 1 / -1;
        }

        .qa-item {
            padding: 9px 0;
        }

        .qa-item:not(:last-child) {
            border-bottom: 1px solid var(--border);
        }

        .doc-row {
            padding: 8px 0;
        }

        .tl-item {
            padding-bottom: 8px;
        }

        .timestamps {
            margin-top: 6px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        @media (max-width: 1080px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .col-right {
                position: static;
            }
        }

        @media (max-width: 640px) {
            .main {
                padding-left: 12px;
                padding-right: 12px;
            }

            .sec-card-head,
            .sec-card-body,
            .applicant-hero {
                padding: 12px;
            }

            .dl-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px 12px;
            }
        }

        @media (max-width: 420px) {
            .dl-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 420px) {
            .dl-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ========== DARK MODE — Resubmission/Compliance Actions panel ========== */
        html[data-theme="dark"] .eval-panel {
            background: var(--surface) !important;
            border-color: var(--border) !important;
        }

        html[data-theme="dark"] .eval-panel-head {
            background: var(--surface) !important;
            border-color: var(--border) !important;
            border-bottom: 1px solid var(--border) !important;
        }

        html[data-theme="dark"] .eval-panel-head * {
            color: var(--text1) !important;
        }

        html[data-theme="dark"] .eval-panel-title {
            color: var(--text1) !important;
        }

        html[data-theme="dark"] .eval-panel-head-icon {
            background: rgba(217, 119, 6, .18) !important;
            color: #fbbf24 !important;
        }

        html[data-theme="dark"] .eval-panel-head-icon {
            background: rgba(217, 119, 6, .15) !important;
        }

        html[data-theme="dark"] .eval-textarea {
            background: #1e293b !important;
            color: var(--text1) !important;
            border-color: var(--border) !important;
        }

        html[data-theme="dark"] .eval-textarea::placeholder {
            color: var(--text3) !important;
        }

        html[data-theme="dark"] .eval-btn-approve {
            background: rgba(5, 150, 105, .20) !important;
            color: #6ee7b7 !important;
            border-color: rgba(5, 150, 105, .4) !important;
        }

        html[data-theme="dark"] .eval-btn-disapprove {
            background: rgba(220, 38, 38, .20) !important;
            color: #fca5a5 !important;
            border-color: rgba(220, 38, 38, .4) !important;
        }

        html[data-theme="dark"] .eval-btn-comply {
            background: rgba(217, 119, 6, .20) !important;
            color: #fcd34d !important;
            border-color: rgba(217, 119, 6, .4) !important;
        }

        html[data-theme="dark"] .eval-panel-body hr {
            border-top-color: var(--border) !important;
        }

        html[data-theme="dark"] .status-pill,
        html[data-theme="dark"] .admin-tag-badge {
            background: transparent !important;
            border: none !important;
            filter: brightness(1.25) saturate(1.15);
            text-shadow: 0 0 6px currentColor;
        }

        /* CHANGE — global scrollbar, thinner, light mode default */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        ::-webkit-scrollbar-corner {
            background: transparent;
        }

        html {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f8fafc;
        }

        /* CHANGE — scrollable panels: Status History / Evaluation History bodies,
           document preview modal body, and the evaluator dropdown option list */
        .sec-card-body,
        .doc-preview-body,
        .rs-option-list {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f8fafc;
        }

        .sec-card-body::-webkit-scrollbar,
        .doc-preview-body::-webkit-scrollbar,
        .rs-option-list::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .sec-card-body::-webkit-scrollbar-track,
        .doc-preview-body::-webkit-scrollbar-track,
        .rs-option-list::-webkit-scrollbar-track {
            background: #f8fafc;
        }

        .sec-card-body::-webkit-scrollbar-thumb,
        .doc-preview-body::-webkit-scrollbar-thumb,
        .rs-option-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        /* ✅ CHANGE — dark mode scrollbars */
        html[data-theme="dark"] ::-webkit-scrollbar-track {
            background: #1e293b;
        }

        html[data-theme="dark"] ::-webkit-scrollbar-thumb {
            background: #3b4658;
        }

        html[data-theme="dark"] ::-webkit-scrollbar-thumb:hover {
            background: #4b5768;
        }

        html[data-theme="dark"] {
            scrollbar-color: #3b4658 #1e293b;
        }

        html[data-theme="dark"] .sec-card-body,
        html[data-theme="dark"] .doc-preview-body,
        html[data-theme="dark"] .rs-option-list {
            scrollbar-color: #3b4658 #1e293b;
        }

        html[data-theme="dark"] .sec-card-body::-webkit-scrollbar-track,
        html[data-theme="dark"] .doc-preview-body::-webkit-scrollbar-track,
        html[data-theme="dark"] .rs-option-list::-webkit-scrollbar-track {
            background: #1e293b;
        }

        html[data-theme="dark"] .sec-card-body::-webkit-scrollbar-thumb,
        html[data-theme="dark"] .doc-preview-body::-webkit-scrollbar-thumb,
        html[data-theme="dark"] .rs-option-list::-webkit-scrollbar-thumb {
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

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; ADMIN PORTAL</p>
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

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Application</h2>
                <p>
                    <span class="status-pill <?= $statusPill ?>"><?= htmlspecialchars($statusText) ?></span>
                    &nbsp;·&nbsp; <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                    <?php if (!empty($app['current_evaluator_name'])): ?>
                        &nbsp;·&nbsp; Owner: <?= htmlspecialchars($app['current_evaluator_name']) ?>
                        <?= $app['handling_party'] === 'admin' ? ' (with Admin)' : '' ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <div class="page-hd fade-in d1">
            <div class="page-hd-left">
                <h2>Application</h2>
                <p><?= htmlspecialchars($app['position_title'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($app['employer_name'] ?? '—') ?></p>
            </div>
            <a href="applications.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($page_error): ?>
            <div class="alert alert-error fade-in d1">
                <i class="fas fa-circle-xmark"></i> <?= htmlspecialchars($page_error) ?>
            </div>
        <?php endif; ?>

        <div class="content-grid">
            <!-- ══════════════════ LEFT: case details ══════════════════ -->
            <div class="col-left">

                <!-- ── APPLICANT INFO ── -->
                <div class="sec-card fade-in d2">
                    <div class="applicant-hero">
                        <div class="applicant-avi" <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                            <?= $app_has_pic ? '' : htmlspecialchars($appAvi) ?>
                        </div>
                        <div class="applicant-hero-info">
                            <div class="applicant-hero-name"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                            <div class="applicant-hero-sub"><?= htmlspecialchars($app['applicant_email'] ?? '—') ?></div>
                            <div class="applicant-hero-sub" style="margin-top:1px"><?= htmlspecialchars($app['phone'] ?? '—') ?></div>
                        </div>
                        <span class="status-pill <?= $statusPill ?>"><?= htmlspecialchars($statusText) ?></span>
                    </div>
                    <?php if (!empty($app['address'])): ?>
                        <div style="padding: 0 16px 14px;">
                            <dt style="font-size:9.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text3);margin-bottom:3px;">Address</dt>
                            <dd style="font-size:12.5px;font-weight:500;color:var(--text2);"><?= htmlspecialchars($app['address']) ?></dd>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── OTHER APPLICATIONS BY THIS APPLICANT ── -->
                <?php if (!empty($otherApps)): ?>
                    <div class="sec-card fade-in d2">
                        <div class="sec-card-head">
                            <div class="sec-card-head-icon" style="background:#fff7ed;color:#d97706;">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <span class="sec-card-title">Other Applications</span>
                            <span style="margin-left:auto;font-size:10px;font-weight:700;color:var(--text3);">
                                <?= count($otherApps) ?> other job<?= count($otherApps) !== 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <div class="sec-card-body">
                            <?php foreach ($otherApps as $oa):
                                $oaStatus = strtolower($oa['status']);
                                $oaPill = match ($oaStatus) {
                                    'approved'         => 'sp-approved',
                                    'under_evaluation',
                                    'resubmitted'      => 'sp-under',
                                    'for_compliance'   => 'sp-compliance',
                                    'disapproved'      => 'sp-disapproved',
                                    default            => 'sp-default',
                                };
                            ?>
                                <a href="view-application.php?id=<?= (int) $oa['id'] ?>"
                                    style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;">
                                    <div style="font-size:12.5px;color:var(--text2);line-height:1.5;">
                                        This applicant also applied for the job
                                        <strong style="color:var(--text1);"><?= htmlspecialchars($oa['position_title'] ?? '—') ?></strong>
                                        in <strong style="color:var(--text1);"><?= htmlspecialchars($oa['worksite_country'] ?? '—') ?></strong>
                                        <span style="font-size:11px;color:var(--text3);">
                                            (<?= htmlspecialchars($oa['rsf_number'] ?? '—') ?> · <?= date('M d, Y', strtotime($oa['submitted_at'])) ?>)
                                        </span>
                                    </div>
                                    <span class="status-pill <?= $oaPill ?>">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $oaStatus))) ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ── JOB ORDER (RSF) ── -->
                <div class="sec-card fade-in d3">
                    <div class="sec-card-head">
                        <div class="sec-card-head-icon" style="background:#eff6ff;color:var(--blue);">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <span class="sec-card-title">Job Order (RSF)</span>
                    </div>
                    <div class="sec-card-body">
                        <div class="dl-grid">
                            <div class="dl-item">
                                <dt>RSF Number</dt>
                                <dd style="color:var(--blue)"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></dd>
                            </div>
                            <div class="dl-item">
                                <dt>Position Title</dt>
                                <dd><?= htmlspecialchars($app['position_title'] ?? '—') ?></dd>
                            </div>
                            <div class="dl-item">
                                <dt>Employer</dt>
                                <dd><?= htmlspecialchars($app['employer_name'] ?? '—') ?></dd>
                            </div>
                            <div class="dl-item">
                                <dt>Country</dt>
                                <dd><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></dd>
                            </div>
                            <?php if (!empty($app['slots_available'])): ?>
                                <div class="dl-item">
                                    <dt>Slots Available</dt>
                                    <dd><?= (int) $app['slots_available'] ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($app['qualifications'])): ?>
                                <div class="dl-item dl-full">
                                    <dt>Qualifications</dt>
                                    <dd style="white-space:pre-wrap;font-weight:500;font-size:12.5px;"><?= htmlspecialchars($app['qualifications']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ── WORK EXPERIENCE ── -->
                <?php if ($fit_client_id && $workExp): ?>
                    <div class="sec-card fade-in d3">
                        <div class="sec-card-head">
                            <div class="sec-card-head-icon" style="background:#eef2ff;color:var(--indigo);">
                                <i class="fas fa-briefcase-medical"></i>
                            </div>
                            <span class="sec-card-title">Work Experience</span>
                        </div>
                        <div class="sec-card-body">
                            <?php
                            $weYears    = $workExp['work_experience_years'] ?? null;
                            $weEmployer = trim((string)($workExp['last_employer'] ?? ''));
                            $wePosition = trim((string)($workExp['last_position'] ?? ''));
                            $hasWorkExp = ($weYears !== null && $weYears !== '') || $weEmployer !== '' || $wePosition !== '';
                            ?>
                            <?php if (!$hasWorkExp): ?>
                                <p style="font-size:12px;color:var(--text3);margin:0;">Applicant has not provided work experience details.</p>
                            <?php else: ?>
                                <div class="dl-grid">
                                    <div class="dl-item">
                                        <dt>Years of Experience</dt>
                                        <dd><?= ($weYears !== null && $weYears !== '') ? (int)$weYears . ' year' . ((int)$weYears !== 1 ? 's' : '') : '—' ?></dd>
                                    </div>
                                    <div class="dl-item">
                                        <dt>Last Employer</dt>
                                        <dd><?= $weEmployer !== '' ? htmlspecialchars($weEmployer) : '—' ?></dd>
                                    </div>
                                    <div class="dl-item">
                                        <dt>Last Position</dt>
                                        <dd><?= $wePosition !== '' ? htmlspecialchars($wePosition) : '—' ?></dd>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ── APPLICANT FIT & ELIGIBILITY (live-recomputed) ── -->
                <?php if ($fit_client_id && $fit_rsf_id): ?>
                    <div class="sec-card fade-in d3">
                        <div class="sec-card-head">
                            <div class="sec-card-head-icon" style="background:#fef9c3;color:#a16207;">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <span class="sec-card-title">Applicant Fit &amp; Eligibility</span>
                            <span style="margin-left:auto;font-size:9.5px;font-weight:700;color:var(--text3);">
                                <i class="fas fa-rotate"></i> Live — reflects applicant's current profile
                            </span>
                        </div>
                        <div class="sec-card-body">

                            <div style="margin-bottom:<?= $fitHasPreferences ? '18px' : '0' ?>;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
                                    <span style="font-size:11.5px;font-weight:800;color:var(--text1);text-transform:uppercase;letter-spacing:.04em;">Eligibility Requirements</span>
                                    <?php if ($fitHasQuals): ?>
                                        <span class="status-pill <?= $fitEligible ? 'sp-approved' : 'sp-disapproved' ?>">
                                            <?= $fitEligible ? 'Meets Requirements' : 'Requirements Not Met' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!$fitHasQuals): ?>
                                    <p style="font-size:12px;color:var(--text3);margin:0;">This job order has no specific structured eligibility requirements posted.</p>
                                <?php else: ?>
                                    <?php foreach ($fitQualChecks as $c): ?>
                                        <div style="display:flex;gap:9px;padding:6px 0;border-bottom:1px solid var(--border);">
                                            <span style="color:<?= $c['met'] ? '#059669' : '#dc2626' ?>;font-weight:800;width:16px;text-align:center;flex-shrink:0;">
                                                <?= $c['met'] ? '✓' : '✕' ?>
                                            </span>
                                            <div>
                                                <div style="font-size:12px;font-weight:700;color:var(--text1);"><?= htmlspecialchars($c['label']) ?></div>
                                                <div style="font-size:11.5px;color:var(--text3);line-height:1.5;"><?= htmlspecialchars($c['detail']) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <p style="margin:10px 0 0;font-size:10.5px;color:var(--text3);line-height:1.5;">Not meeting a listed requirement does not auto-disqualify the applicant — this is informational to support your decision.</p>
                                <?php endif; ?>
                            </div>

                            <?php if ($fitHasPreferences && $fitMatchDetails): ?>
                                <?php
                                $score = $fitMatchDetails['score'];
                                if ($score >= 70) {
                                    $tierColor = '#059669';
                                    $tierLabel = 'Strong Match';
                                } elseif ($score >= 40) {
                                    $tierColor = '#a16207';
                                    $tierLabel = 'Fair Match';
                                } else {
                                    $tierColor = '#6b7280';
                                    $tierLabel = 'Low Match';
                                }
                                ?>
                                <div style="border-top:1px solid var(--border);padding-top:14px;">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
                                        <span style="font-size:11.5px;font-weight:800;color:var(--text1);text-transform:uppercase;letter-spacing:.04em;">Preference Fit</span>
                                        <span style="font-size:11px;font-weight:800;color:<?= $tierColor ?>;"><?= $tierLabel ?> · <?= $score ?>%</span>
                                    </div>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0 20px;">
                                        <?php foreach ($fitMatchDetails['components'] as $c): ?>
                                            <?php
                                            if (!$c['applicable']) {
                                                $icon = '−';
                                                $iconColor = '#9ca3af';
                                                $level = 'Not scored';
                                            } else {
                                                $ratio = $c['max'] > 0 ? $c['earned'] / $c['max'] : 0;
                                                if ($ratio >= 0.99) {
                                                    $icon = '✓';
                                                    $iconColor = '#059669';
                                                    $level = 'Strong match';
                                                } elseif ($ratio > 0) {
                                                    $icon = '~';
                                                    $iconColor = '#a16207';
                                                    $level = 'Partial match';
                                                } else {
                                                    $icon = '✕';
                                                    $iconColor = '#dc2626';
                                                    $level = 'No match';
                                                }
                                            }
                                            ?>
                                            <div style="padding:6px 0;border-bottom:1px solid var(--border);">
                                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                                    <div style="display:flex;align-items:center;gap:8px;">
                                                        <span style="color:<?= $iconColor ?>;font-weight:800;width:16px;text-align:center;"><?= $icon ?></span>
                                                        <span style="font-weight:700;font-size:12px;color:var(--text1);"><?= htmlspecialchars($c['label']) ?></span>
                                                    </div>
                                                    <span style="font-size:10.5px;font-weight:700;color:var(--text3);white-space:nowrap;"><?= $level ?></span>
                                                </div>
                                                <div style="font-size:11.5px;color:var(--text3);line-height:1.5;padding:3px 0 2px 24px;"><?= htmlspecialchars($c['detail']) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p style="margin:8px 0 0;font-size:10.5px;color:var(--text3);line-height:1.5;">Reflects fit to the applicant's saved preferences — separate from eligibility above.</p>
                                </div>
                            <?php else: ?>
                                <div style="<?= $fitHasQuals ? 'border-top:1px solid var(--border);padding-top:14px;' : '' ?>">
                                    <span style="font-size:11.5px;font-weight:800;color:var(--text1);text-transform:uppercase;letter-spacing:.04em;">Preference Fit</span>
                                    <p style="font-size:12px;color:var(--text3);margin:8px 0 0;">Applicant hasn't set job preferences yet, so no preference match score is available for this job order.</p>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

                <!-- ── QUESTION ANSWERS ── -->
                <?php if (!empty($answers)): ?>
                    <div class="sec-card fade-in d4">
                        <div class="sec-card-head">
                            <div class="sec-card-head-icon" style="background:#eef2ff;color:var(--indigo);">
                                <i class="fas fa-list-ul"></i>
                            </div>
                            <span class="sec-card-title">Question Answers</span>
                        </div>
                        <div class="sec-card-body">
                            <?php foreach ($answers as $a): ?>
                                <div class="qa-item">
                                    <div class="qa-question"><?= htmlspecialchars($a['question_text']) ?></div>
                                    <div class="qa-answer"><?= nl2br(htmlspecialchars($a['answer'] ?? '—')) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ── DOCUMENTS (requirements checklist + uploaded files + comment threads) ── -->
                <?php if (!empty($requiredDocs) || !empty($documents)): ?>
                    <div class="sec-card fade-in d4">
                        <div class="sec-card-head">
                            <div class="sec-card-head-icon" style="background:#eff6ff;color:var(--blue);">
                                <i class="fas fa-folder-open"></i>
                            </div>
                            <span class="sec-card-title">Documents</span>
                            <?php if ($requiredCount > 0): ?>
                                <span style="margin-left:auto;font-size:10px;font-weight:700;color:var(--text3);">
                                    <?= $metCount ?> of <?= $requiredCount ?> required on file
                                </span>
                            <?php elseif (!empty($requiredDocs)): ?>
                                <span style="margin-left:auto;font-size:10px;font-weight:700;color:var(--text3);">
                                    No documents required
                                </span>
                            <?php else: ?>
                                <span style="margin-left:auto;font-size:10px;font-weight:700;color:var(--text3);">
                                    <?= count($documents) ?> file<?= count($documents) !== 1 ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="sec-card-body">

                            <?php foreach ($requiredDocs as $rd):
                                $reqId      = (int) $rd['id'];
                                $typeKey    = strtolower(trim($rd['document_type'] ?? ''));
                                $matches    = $uploadedByReqId[$reqId] ?? ($uploadedByType[$typeKey] ?? []);
                                $isUploaded = !empty($matches);
                                $isRequired = !empty($rd['is_required']);
                            ?>
                                <div style="padding:8px 0;border-bottom:1px solid var(--border);">
                                    <div style="display:flex;align-items:center;gap:9px;">
                                        <span style="color:<?= $isUploaded ? '#059669' : '#dc2626' ?>;font-weight:800;width:16px;text-align:center;flex-shrink:0;">
                                            <?= $isUploaded ? '✓' : '✕' ?>
                                        </span>
                                        <div style="flex:1;min-width:0;">
                                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                                <span style="font-size:12.5px;font-weight:700;color:var(--text1);"><?= htmlspecialchars($rd['document_name']) ?></span>
                                                <span class="status-pill <?= $isRequired ? 'sp-disapproved' : 'sp-default' ?>" style="font-size:9px;padding:2px 7px;">
                                                    <?= $isRequired ? 'Required' : 'Optional' ?>
                                                </span>
                                                <span class="status-pill <?= $isUploaded ? 'sp-approved' : 'sp-disapproved' ?>" style="font-size:9px;padding:2px 7px;">
                                                    <?= $isUploaded ? 'On File' : 'Missing' ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($rd['description'])): ?>
                                                <div style="font-size:11.5px;color:var(--text3);margin-top:2px;"><?= htmlspecialchars($rd['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php foreach ($matches as $d): ?>
                                        <div class="doc-row" style="margin-left:25px;">
                                            <div class="doc-row-left">
                                                <div class="doc-icon"><i class="fas fa-file-lines"></i></div>
                                                <div>
                                                    <div class="doc-name"><?= htmlspecialchars($d['file_name']) ?></div>
                                                    <div class="doc-type"><?= htmlspecialchars($d['document_type']) ?></div>
                                                </div>
                                            </div>
                                            <?php if (!empty($d['file_path'])): $docExt = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION)); ?>
                                                <button type="button" class="doc-view-btn"
                                                    onclick="openDocPreview('../<?= htmlspecialchars($d['file_path']) ?>', '<?= htmlspecialchars(addslashes($d['file_name'])) ?>', '<?= htmlspecialchars($docExt) ?>')">
                                                    <i class="fas fa-eye"></i> Preview
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php $docComments = $docCommentsByDocumentId[(int) $d['id']] ?? []; ?>
                                        <div id="doc-comments-wrap-<?= (int) $d['id'] ?>"
                                            style="margin:8px 0 10px 71px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);<?= empty($docComments) ? 'display:none;' : '' ?>">
                                            <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);margin-bottom:6px;">Document comments</div>
                                            <div id="doc-comments-list-<?= (int) $d['id'] ?>">
                                                <?php foreach ($docComments as $c): ?>
                                                    <div style="padding:6px 0;border-top:1px dashed var(--border);">
                                                        <div style="font-size:11px;color:var(--text3);font-weight:600;">
                                                            <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?>
                                                            <?php if (($c['commenter_role'] ?? '') === 'admin'): ?>
                                                                <span class="admin-tag-badge" style="background:#eef2ff;color:#4338ca;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;margin-left:4px;">ADMIN</span>
                                                            <?php endif; ?>
                                                            · <?= date('M d, Y g:i a', strtotime($c['created_at'])) ?>
                                                        </div>
                                                        <div style="font-size:12px;color:var(--text2);line-height:1.5;"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <form method="POST" class="js-doc-comment-form" data-doc-id="<?= (int) $d['id'] ?>" style="margin:0 0 12px 71px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
                                            <input type="hidden" name="action" value="add_doc_comment">
                                            <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                            <input type="hidden" name="ajax" value="1">
                                            <textarea name="doc_comment" required
                                                placeholder="Add document-specific comment for applicant..."
                                                style="flex:1;min-width:220px;min-height:42px;border:1px solid var(--border);border-radius:9px;padding:8px 10px;font-size:12px;font-family:'DM Sans',sans-serif;resize:vertical;"></textarea>
                                            <button type="submit" class="doc-view-btn" style="border:none;cursor:pointer;">
                                                <i class="fas fa-comment-dots"></i> Add Comment
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!empty($extraDocuments)): ?>
                                <?php if (!empty($requiredDocs)): ?>
                                    <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);margin:12px 0 6px;">
                                        Other Uploaded Files
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($extraDocuments as $d): ?>
                                    <div class="doc-row">
                                        <div class="doc-row-left">
                                            <div class="doc-icon"><i class="fas fa-file-lines"></i></div>
                                            <div>
                                                <div class="doc-name"><?= htmlspecialchars($d['file_name']) ?></div>
                                                <div class="doc-type"><?= htmlspecialchars($d['document_type']) ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($d['file_path'])): $docExt = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION)); ?>
                                            <button type="button" class="doc-view-btn"
                                                onclick="openDocPreview('../<?= htmlspecialchars($d['file_path']) ?>', '<?= htmlspecialchars(addslashes($d['file_name'])) ?>', '<?= htmlspecialchars($docExt) ?>')">
                                                <i class="fas fa-eye"></i> Preview
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php $docComments = $docCommentsByDocumentId[(int) $d['id']] ?? []; ?>
                                    <div id="doc-comments-wrap-<?= (int) $d['id'] ?>"
                                        style="margin:8px 0 10px 46px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);<?= empty($docComments) ? 'display:none;' : '' ?>">
                                        <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);margin-bottom:6px;">Document comments</div>
                                        <div id="doc-comments-list-<?= (int) $d['id'] ?>">
                                            <?php foreach ($docComments as $c): ?>
                                                <div style="padding:6px 0;border-top:1px dashed var(--border);">
                                                    <div style="font-size:11px;color:var(--text3);font-weight:600;">
                                                        <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?>
                                                        <?php if (($c['commenter_role'] ?? '') === 'admin'): ?>
                                                            <span class="admin-tag-badge" style="background:#eef2ff;color:#4338ca;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;margin-left:4px;">ADMIN</span>
                                                        <?php endif; ?>
                                                        · <?= date('M d, Y g:i a', strtotime($c['created_at'])) ?>
                                                    </div>
                                                    <div style="font-size:12px;color:var(--text2);line-height:1.5;"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <form method="POST" class="js-doc-comment-form" data-doc-id="<?= (int) $d['id'] ?>" style="margin:0 0 14px 46px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
                                        <input type="hidden" name="action" value="add_doc_comment">
                                        <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                        <input type="hidden" name="ajax" value="1">
                                        <textarea name="doc_comment" required
                                            placeholder="Add document-specific comment for applicant..."
                                            style="flex:1;min-width:220px;min-height:42px;border:1px solid var(--border);border-radius:9px;padding:8px 10px;font-size:12px;font-family:'DM Sans',sans-serif;resize:vertical;"></textarea>
                                        <button type="submit" class="doc-view-btn" style="border:none;cursor:pointer;">
                                            <i class="fas fa-comment-dots"></i> Add Comment
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

            </div><!-- /col-left -->

            <!-- ══════════════════ RIGHT: action + history rail ══════════════════ -->
            <div class="col-right">

                <!-- ── COMPLIANCE / RESUBMISSION ACTIONS (case is with Admin, or applicant just resubmitted) ── -->
                <div class="eval-panel fade-in d5">
                    <div class="eval-panel-head">
                        <div class="eval-panel-head-icon">
                            <i class="fas <?= $showAdminHandoffPanel ? 'fa-triangle-exclamation' : 'fa-circle-info' ?>"></i>
                        </div>
                        <span class="eval-panel-title">
                            <?= $showAdminHandoffPanel
                                ? ($isResubmitted ? 'Resubmission Actions' : 'Awaiting Applicant Compliance')
                                : 'Admin Actions' ?>
                        </span>
                    </div>
                    <?php if (!$showAdminHandoffPanel): ?>
                        <div class="eval-panel-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:28px 16px;text-align:center;">
                            <i class="fas fa-lock" style="font-size:22px;color:var(--text3);opacity:.5;"></i>
                            <p style="margin:0;font-size:12.5px;color:var(--text3);line-height:1.6;">
                                <?php if (in_array($status, ['approved', 'disapproved'], true)): ?>
                                    This application has already been finalized as <strong><?= htmlspecialchars($statusText) ?></strong>. No further admin actions are available.
                                <?php else: ?>
                                    Approve, Disapprove, Reassign, and Reopen become available here only after the evaluator flags this application <strong>For Compliance</strong> and the applicant resubmits — right now it's still with the evaluator (<strong><?= htmlspecialchars($statusText) ?></strong>).
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="eval-panel-body">
                            <?php if ($isForCompliance && !$isResubmitted): ?>
                                <div style="display:flex;align-items:flex-start;gap:10px;padding:4px 0;">
                                    <i class="fas fa-hourglass-half" style="color:var(--text3);margin-top:2px;"></i>
                                    <div style="font-size:12.5px;color:var(--text2);line-height:1.6;">
                                        This application is waiting on the applicant to address the evaluator's comments
                                        and resubmit. Reopening, direct evaluation, and reassignment are unavailable until
                                        the applicant resubmits — this prevents re-evaluating an application whose
                                        deficiencies haven't been fixed yet.
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="display:flex;flex-direction:column;gap:18px;">

                                    <?php if (!empty($app['current_evaluator_id'])): ?>
                                        <div>
                                            <div style="font-size:12.5px;color:var(--text2);margin-bottom:8px;">
                                                Send this case back to <strong><?= htmlspecialchars($app['current_evaluator_name']) ?></strong> to continue evaluating<?= $isResubmitted ? ' now that the applicant has resubmitted' : ' after the applicant has complied' ?>.
                                            </div>
                                            <form method="POST" id="reopenToEvaluatorForm">
                                                <input type="hidden" name="action" value="reopen">
                                                <button type="button"
                                                    data-evaluator-name="<?= htmlspecialchars($app['current_evaluator_name'], ENT_QUOTES) ?>"
                                                    onclick="confirmReopenToEvaluator(this)"
                                                    class="eval-btn eval-btn-approve">
                                                    <i class="fas fa-rotate-left"></i> Reopen to Evaluator
                                                </button>
                                            </form>
                                        </div>
                                        <hr style="border:none;border-top:1px solid var(--border);margin:0;">
                                    <?php endif; ?>

                                    <div>
                                        <div style="font-size:12.5px;color:var(--text2);margin-bottom:8px;">
                                            Evaluate this application yourself instead of sending it back to the evaluator.
                                        </div>
                                        <form method="POST" id="evalDirectForm">
                                            <input type="hidden" name="action" value="evaluate_directly">
                                            <label style="display:block;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text2);margin-bottom:7px;">
                                                Comments
                                            </label>
                                            <textarea name="comments" class="eval-textarea" placeholder="Notes for the applicant..."></textarea>
                                            <div class="eval-actions">
                                                <button type="button" class="eval-btn eval-btn-approve" onclick="submitDirectEval('approved')">
                                                    <i class="fas fa-circle-check"></i> Approve
                                                </button>
                                                <button type="button" class="eval-btn eval-btn-disapprove" onclick="submitDirectEval('disapproved')">
                                                    <i class="fas fa-circle-xmark"></i> Disapprove
                                                </button>
                                            </div>
                                            <input type="hidden" name="decision" id="directDecisionInput" value="">
                                        </form>
                                    </div>
                                    <hr style="border:none;border-top:1px solid var(--border);margin:0;">

                                    <?php if (!empty($evaluators)): ?>
                                        <div>
                                            <div style="font-size:12.5px;color:var(--text2);margin-bottom:8px;">
                                                Reassign this case to a different evaluator.
                                            </div>
                                            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
                                                <input type="hidden" name="action" value="reassign">
                                                <select name="evaluator_id" required
                                                    style="border:1px solid var(--border);background:var(--surface,#fff);border-radius:10px;padding:9px 12px;font-size:12.5px;color:var(--text1);">
                                                    <option value="">Select evaluator...</option>
                                                    <?php foreach ($evaluators as $ev): ?>
                                                        <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="eval-btn eval-btn-comply">
                                                    <i class="fas fa-user-pen"></i> Reassign
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!--  STATUS HISTORY  -->
                <div class="sec-card fade-in d6">
                    <div class="sec-card-head">
                        <div class="sec-card-head-icon" style="background:#ecfdf5;color:var(--green);">
                            <i class="fas fa-arrows-rotate"></i>
                        </div>
                        <span class="sec-card-title">Status History</span>
                    </div>
                    <?php if (!empty($status_history)): ?>
                        <div class="sec-card-body" style="max-height:340px;overflow-y:auto;">
                            <?php foreach ($status_history as $sh): ?>
                                <div class="tl-item">
                                    <div class="tl-dot-wrap">
                                        <div class="tl-dot"></div>
                                        <div class="tl-line"></div>
                                    </div>
                                    <div class="tl-body">
                                        <div class="tl-title">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['from_status'] ?? '—'))) ?>
                                            &nbsp;<i class="fas fa-arrow-right" style="font-size:9px;color:var(--text3)"></i>&nbsp;
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['to_status'] ?? '—'))) ?>
                                        </div>
                                        <div class="tl-sub">
                                            <?= htmlspecialchars($sh['changed_by']) ?>
                                            <?= !empty($sh['reason']) ? ' — ' . htmlspecialchars($sh['reason']) : '' ?>
                                        </div>
                                        <div class="tl-date"> <?= date('M d, Y • g:i a', strtotime($sh['changed_at'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sec-card-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:28px 16px;text-align:center;">
                            <i class="fas fa-clock-rotate-left" style="font-size:22px;color:var(--text3);opacity:.5;"></i>
                            <p style="margin:0;font-size:12.5px;color:var(--text3);">There is no status history available.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!--  EVALUATION HISTORY  -->
                <div class="sec-card fade-in d7">
                    <div class="sec-card-head">
                        <div class="sec-card-head-icon" style="background:#eef2ff;color:var(--indigo);">
                            <i class="fas fa-clock-rotate-left"></i>
                        </div>
                        <span class="sec-card-title">Evaluation History</span>
                    </div>
                    <?php if (!empty($evaluations)): ?>
                        <div class="sec-card-body" style="max-height:340px;overflow-y:auto;">
                            <?php foreach ($evaluations as $ev): ?>
                                <?php
                                $evPill = match (strtolower($ev['decision'])) {
                                    'approved'        => 'sp-approved',
                                    'for_compliance'  => 'sp-compliance',
                                    'disapproved'     => 'sp-disapproved',
                                    default           => 'sp-default',
                                };
                                ?>
                                <div class="tl-item">
                                    <div class="tl-dot-wrap">
                                        <div class="tl-dot" style="background:var(--indigo)"></div>
                                        <div class="tl-line"></div>
                                    </div>
                                    <div class="tl-body">
                                        <div class="tl-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <?= htmlspecialchars($ev['decided_by']) ?>
                                            <?php if (($ev['decided_by_role'] ?? '') === 'admin'): ?>
                                                <span class="admin-tag-badge" style="background:#eef2ff;color:#4338ca;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">ADMIN</span>
                                            <?php endif; ?>
                                            <span class="status-pill <?= $evPill ?>"><?= ucfirst(htmlspecialchars($ev['decision'])) ?></span>
                                        </div>
                                        <?php if (!empty($ev['comments'])): ?>
                                            <div class="tl-sub"><?= nl2br(htmlspecialchars($ev['comments'])) ?></div>
                                        <?php endif; ?>
                                        <div class="tl-date"><?= date('M d, Y • g:i a', strtotime($ev['evaluated_at'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sec-card-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:28px 16px;text-align:center;">
                            <i class="fas fa-magnifying-glass-chart" style="font-size:22px;color:var(--text3);opacity:.5;"></i>
                            <p style="margin:0;font-size:12.5px;color:var(--text3);">There is no evaluation history available.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- /col-right -->
        </div><!-- /content-grid -->

        <!-- Timestamps -->
        <div class="timestamps fade-in d8">
            Submitted: <?= date('M d, Y h:i a', strtotime($app['submitted_at'] ?? $app['updated_at'] ?? 'now')) ?>
            &nbsp;·&nbsp;
            Last updated: <?= date('M d, Y h:i a', strtotime($app['updated_at'] ?? 'now')) ?>
        </div>

    </div><!-- /main -->

    <!-- FLOATING BOTTOM DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk">
                <i class="fas fa-house"></i><span class="dk-lbl">Home</span>
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
            <!-- <a href="assignments.html" class="dk"><i class="fas fa-list-check"></i><span class="dk-lbl">Assign</span></a> -->
            <a href="job-orders.php" class="dk"><i class="fas fa-briefcase"></i><span class="dk-lbl">RSF</span></a>
            <a href="reports.php" class="dk"><i class="fas fa-chart-bar"></i><span class="dk-lbl">Reports</span></a>
            </a>
            <a href="applicant-reports.php" class="dk" data-tip="Applicant Reports">
                <i class="fas fa-file-contract"></i>
                <span class="dk-lbl">Applicants</span>
            </a>
            <a href="analytics.php" class="dk">
                <i class="fas fa-chart-bar text-blue-300 text-base leading-none"></i><span class="dk-lbl"">Analytics</span>
            </a>
               <a href=" user.php" class="dk" data-tip="Users">
                    <i class="fas fa-users-gear"></i>
                    <span class="dk-lbl">Users</span>
            </a>
            <div class=" dk-div">
            </div>
            <a href="settings.php" class="dk" data-tip="Settings"><i class="fas fa-gear"></i><span class="dk-lbl">Settings</span></a>
            <div class="dk" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>
    <!--  DOCUMENT PREVIEW MODAL  -->
    <div id="docPreviewOverlay" class="doc-preview-overlay" onclick="if(event.target===this) closeDocPreview()">
        <div class="doc-preview-modal">
            <div class="doc-preview-head">
                <span id="docPreviewName" class="doc-preview-name"><i class="fas fa-file-lines"></i> </span>
                <div class="doc-preview-actions">
                    <a id="docPreviewOpenNew" href="#" target="_blank" class="doc-preview-open-btn" title="Open in new tab">
                        <i class="fas fa-up-right-from-square"></i>
                    </a>
                    <button type="button" class="doc-preview-close-btn" onclick="closeDocPreview()" title="Close">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div id="docPreviewBody" class="doc-preview-body">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <style>
        .doc-preview-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .doc-preview-overlay.open {
            display: flex;
        }

        .doc-preview-modal {
            background: #fff;
            border-radius: 14px;
            width: 100%;
            max-width: 860px;
            height: 85vh;
            max-height: 900px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
        }

        .doc-preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border, #e5e7eb);
            gap: 12px;
        }

        .doc-preview-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text2, #374151);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-preview-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .doc-preview-open-btn,
        .doc-preview-close-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid var(--border, #e5e7eb);
            background: #fff;
            color: var(--text2, #374151);
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
        }

        .doc-preview-open-btn:hover,
        .doc-preview-close-btn:hover {
            background: #f3f4f6;
        }

        .doc-preview-body {
            flex: 1;
            overflow: auto;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .doc-preview-body iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .doc-preview-body img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .doc-preview-unsupported {
            text-align: center;
            padding: 40px;
            color: var(--text3, #6b7280);
            font-size: 13px;
        }

        .doc-preview-unsupported a {
            color: var(--blue, #2563eb);
            font-weight: 700;
            text-decoration: none;
        }
    </style>

    <script>
        function openDocPreview(src, name, ext) {
            const overlay = document.getElementById('docPreviewOverlay');
            const body = document.getElementById('docPreviewBody');
            const nameEl = document.getElementById('docPreviewName');
            const openNew = document.getElementById('docPreviewOpenNew');

            nameEl.innerHTML = '<i class="fas fa-file-lines"></i> ' + name;
            openNew.href = src;

            const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            ext = (ext || '').toLowerCase();

            if (ext === 'pdf') {
                body.innerHTML = '<iframe src="' + src + '"></iframe>';
            } else if (imageExts.includes(ext)) {
                body.innerHTML = '<img src="' + src + '" alt="' + name + '">';
            } else {
                body.innerHTML = '<div class="doc-preview-unsupported">' +
                    'Preview is not available for this file type.<br>' +
                    '<a href="' + src + '" target="_blank">Open ' + name + ' in a new tab</a>' +
                    '</div>';
            }

            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeDocPreview() {
            const overlay = document.getElementById('docPreviewOverlay');
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            document.getElementById('docPreviewBody').innerHTML = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDocPreview();
        });
    </script>

    <script>
        /*Live date + time*/
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
        setInterval(updateLiveDateTime, 1000); // refresh every second

        // Reopen to Evaluator: ask for confirmation via SweetAlert before submitting.
        function confirmReopenToEvaluator(btn) {
            const evaluatorName = btn.dataset.evaluatorName;
            Swal.fire({
                title: 'Reopen this application?',
                text: `This will send it back to ${evaluatorName} for continued review.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, reopen it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('reopenToEvaluatorForm').submit();
                }
                // If cancelled, Swal closes on its own and nothing else happens.
            });
        }

        <?php if ($page_success): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.mixin({
                    toast: true,
                    position: "top-end",
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.onmouseenter = Swal.stopTimer;
                        toast.onmouseleave = Swal.resumeTimer;
                    }
                }).fire({
                    icon: "success",
                    title: <?= json_encode($page_success) ?>
                });
            });
        <?php endif; ?>

        function submitDirectEval(decision) {
            const labels = {
                approved: {
                    title: 'Approve Application?',
                    text: 'This records YOUR decision as Admin, bypassing the evaluator.',
                    icon: 'success',
                    confirmColor: '#059669'
                },
                disapproved: {
                    title: 'Disapprove Application?',
                    text: 'This records YOUR decision as Admin, bypassing the evaluator.',
                    icon: 'error',
                    confirmColor: '#dc2626'
                },
            };
            const l = labels[decision];
            Swal.fire({
                title: l.title,
                text: l.text,
                icon: l.icon,
                showCancelButton: true,
                confirmButtonText: 'Yes, confirm',
                cancelButtonText: 'Cancel',
                confirmButtonColor: l.confirmColor,
                cancelButtonColor: '#6b7280',
            }).then(result => {
                if (result.isConfirmed) {
                    document.getElementById('directDecisionInput').value = decision;

                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait while your evaluation is being saved.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    document.getElementById('evalDirectForm').submit();
                }
            });
        }
    </script>
    <script>
        (function() {
            /* Wraps the Reassign form's native <select name="evaluator_id">
               with a styled trigger + dropdown panel. The panel is appended
               to <body> and positioned `fixed` (computed from the trigger's
               on-screen position) with a very high z-index, so it always
               renders above the floating dock / sticky sidebar instead of
               getting hidden behind them by a parent stacking context.

               The original <select> stays in the form (hidden) with its
               `name` intact, so the surrounding <form method="POST"> still
               submits exactly the same field — no PHP changes needed.

               required-note: this select had `required`, but browsers skip
               required-validation on hidden elements, so that check is
               replaced with a manual one on form submit. */
            const select = document.querySelector('select[name="evaluator_id"]');
            if (!select) return;

            const form = select.closest('form');
            const placeholderOpt = select.querySelector('option[value=""]');
            const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'position:relative;flex:1;min-width:180px;';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.style.display = 'none';
            select.tabIndex = -1;
            select.removeAttribute('required'); // replaced by manual check below

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.style.cssText = 'width:100%;border:1px solid var(--border);background:var(--surface,#fff);border-radius:10px;padding:9px 12px;font-size:12.5px;color:var(--text1);display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;transition:border-color .15s;';
            trigger.innerHTML = '<span class="rs-trigger-label" style="color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>' +
                '<i class="fas fa-chevron-down" style="font-size:10px;color:var(--text3);transition:transform .15s;flex-shrink:0;"></i>';
            wrapper.appendChild(trigger);

            // ── Appended to <body>, positioned fixed, very high z-index ──
            const panel = document.createElement('div');
            panel.className = 'rs-option-list';
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
                    if (opt.value === '') return; // "Select evaluator..." is a true placeholder here
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
                document.querySelectorAll('[role="listbox"]').forEach(p => {
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
            document.addEventListener('click', closePanel);

            refresh();

            // Manual required-check, since the hidden native <select> is
            // exempt from HTML5 constraint validation.
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
        })();
    </script>
    <script>
        document.addEventListener('submit', function(e) {
            const form = e.target.closest('.js-doc-comment-form');
            if (!form) return;
            e.preventDefault();

            const btn = form.querySelector('button[type="submit"]');
            const textarea = form.querySelector('textarea[name="doc_comment"]');
            const docId = form.dataset.docId;

            if (!textarea.value.trim()) return;

            btn.disabled = true;
            const originalBtnHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';

            fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form)
                })
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;

                    if (!data.success) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Could not post comment',
                            text: data.message
                        });
                        return;
                    }

                    const wrap = document.getElementById('doc-comments-wrap-' + docId);
                    const list = document.getElementById('doc-comments-list-' + docId);

                    const badge = data.commenter_role === 'admin' ?
                        '<span class="admin-tag-badge" style="background:#eef2ff;color:#4338ca;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;margin-left:4px;">ADMIN</span>' :
                        '';

                    const row = document.createElement('div');
                    row.style.cssText = 'padding:6px 0;border-top:1px dashed var(--border);';
                    row.innerHTML = `
            <div style="font-size:11px;color:var(--text3);font-weight:600;">
                ${data.commenter_name}${badge} · ${data.created_at}
            </div>
            <div style="font-size:12px;color:var(--text2);line-height:1.5;">${data.comment}</div>
        `;

                    list.prepend(row);
                    wrap.style.display = 'block';
                    textarea.value = '';

                    Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    }).fire({
                        icon: 'success',
                        title: 'Comment added.'
                    });
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;
                    Swal.fire({
                        icon: 'error',
                        title: 'Network error',
                        text: 'Please try again.'
                    });
                });
        });
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
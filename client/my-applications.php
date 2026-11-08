<?php
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}
date_default_timezone_set('Asia/Manila');
// HELPERS  (mirrors my-applications.php originals) 

function myapp_requirement_lines(array $job): array
{
    $lines = [];
    $q = trim((string) ($job['qualifications'] ?? ''));
    if ($q !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $q) as $line) {
            $line = trim(preg_replace('/^[➢\-\*\d\.\)\s]+/u', '', $line));
            if ($line !== '') $lines[] = $line;
        }
    }
    if ($lines === []) {
        $lines[] = 'I confirm that I meet the qualifications and requirements described in the official RSF for this position.';
    }
    $lines[] = 'I confirm that I have read and understood the RSF details (including the PDF, if provided) for this position.';
    $lines[] = 'I confirm that the information I provide in this application is true and complete to the best of my knowledge.';
    return $lines;
}

function myapp_required_documents_for_job(mysqli $db, int $job_id): array
{
    if ($job_id <= 0) return [];
    $stmt = $db->prepare('SELECT id, document_type, document_name, is_required, description FROM rsf_required_documents WHERE rsf_id = ? ORDER BY id ASC');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $docs = [];
    foreach ($rows as $r) {
        if (empty($r['document_type']) || empty($r['document_name'])) continue;
        $docs[] = ['id' => (int)$r['id'], 'document_type' => (string)$r['document_type'], 'document_name' => (string)$r['document_name'], 'is_required' => (int)($r['is_required'] ?? 1), 'description' => (string)($r['description'] ?? '')];
    }
    if ($docs === []) {
        return [
            ['id' => 0, 'document_type' => 'passport',    'document_name' => 'Passport (valid)',      'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'resume',       'document_name' => 'Resume / CV',           'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'diploma',      'document_name' => 'Diploma / TOR',         'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'certificate',  'document_name' => 'Certificate / license', 'is_required' => 1, 'description' => ''],
        ];
    }
    return $docs;
}

function myapp_questions_for_job(mysqli $db, int $job_id): array
{
    if ($job_id <= 0) return [];
    $stmt = $db->prepare('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id = ? ORDER BY COALESCE(order_no, 999), id');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows === []) {
        $res  = $db->query('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id IS NULL ORDER BY COALESCE(order_no, 999), id');
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    return $rows;
}

function myapp_load_answers(mysqli $db, int $app_id): array
{
    $map  = [];
    $stmt = $db->prepare("SELECT question_id, answer FROM application_answers WHERE application_id = ?");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $r) $map[(int)$r['question_id']] = $r['answer'];
    return $map;
}

function myapp_load_docs(mysqli $db, int $app_id): array
{
    $stmt = $db->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function myapp_load_status_history(mysqli $db, int $app_id): array
{
    $stmt = $db->prepare("SELECT from_status, to_status, changed_by, reason, changed_at FROM status_history WHERE application_id = ? ORDER BY changed_at ASC");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function myapp_load_doc_comments(mysqli $db, int $app_id): array
{
    $stmt = $db->prepare("
        SELECT dc.comment, dc.commented_by, dc.created_at,
               ad.document_type, w.usertype AS commenter_type
        FROM document_comments dc
        JOIN application_documents ad ON dc.document_id = ad.id
        LEFT JOIN webuser w ON w.email = dc.commented_by
        WHERE ad.application_id = ?
        ORDER BY dc.created_at ASC
    ");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function myapp_load_eval_comments(mysqli $db, int $app_id): string
{
    $stmt = $db->prepare("SELECT comments FROM evaluations WHERE application_id = ? ORDER BY evaluated_at DESC LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($r && trim((string)($r['comments'] ?? '')) !== '') ? trim($r['comments']) : '';
}

function myapp_is_assigned(mysqli $db, int $app_id): bool
{
    $stmt = $db->prepare("SELECT id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$r;
}

function myapp_relative_time(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 2592000) {
        $d = (int) floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 31536000) {
        $mo = (int) floor($diff / 2592000);
        return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago';
    }
    $y = (int) floor($diff / 31536000);
    return $y . ' year' . ($y === 1 ? '' : 's') . ' ago';
}

function myapp_status_info(string $status): array
{
    return match (true) {
        $status === 'draft' => ['pill' => 'draft', 'icon' => 'fa-pen', 'label' => 'Draft', 'color' => '#f59e0b'],
        $status === 'approved' => ['pill' => 'approved', 'icon' => 'fa-circle-check', 'label' => 'Approved', 'color' => '#10b981'],
        $status === 'under_evaluation' => ['pill' => 'evaluating', 'icon' => 'fa-user-check', 'label' => 'Under Evaluation', 'color' => '#6366f1'],
        $status === 'for_compliance' => ['pill' => 'compliance', 'icon' => 'fa-triangle-exclamation', 'label' => 'For Compliance', 'color' => '#f59e0b'],
        $status === 'disapproved' => ['pill' => 'disapproved', 'icon' => 'fa-circle-xmark', 'label' => 'Disapproved', 'color' => '#ef4444'],
        $status === 'resubmitted' => ['pill' => 'resubmitted', 'icon' => 'fa-rotate-right', 'label' => 'Resubmitted', 'color' => '#0ea5e9'],
        $status === 'pending' => ['pill' => 'pending', 'icon' => 'fa-clock', 'label' => 'Pending Evaluator Review', 'color' => '#64748b'],
        default => ['pill' => 'pending', 'icon' => 'fa-file-lines', 'label' => ucwords(str_replace('_', ' ', $status)), 'color' => '#94a3b8'],
    };
}

// LOAD CLIENT 

$client_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT cid, profile_picture, CONCAT(first_name, ' ', last_name) AS full_name FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$client) {
    header('Location: ../login.php');
    exit;
}
$client_id = (int)$client['cid'];
$has_picture = !empty($client['profile_picture']);
$picture_url = $has_picture ? '../' . $client['profile_picture'] : null;
$open_app_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// WIZARD STATE
$show_wizard       = false;
$wizard_job        = null;
$wizard_job_id     = (int)($_GET['job_id'] ?? 0);
$wizard_step_explicit = isset($_GET['step']); // true only if the URL itself asked for a step
$wizard_url_step   = max(1, (int)($_GET['step'] ?? 1));
$wizard_block_msg  = '';
$wizard_display_step = 1;

if ($wizard_job_id > 0) {
    $stmt = $database->prepare('SELECT * FROM rsf_job_orders WHERE id = ? AND slots_available > 0 AND (deadline IS NULL OR deadline >= CURDATE()) LIMIT 1');
    $stmt->bind_param('i', $wizard_job_id);
    $stmt->execute();
    $wizard_job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$wizard_job) {
        $wizard_block_msg = 'This job is no longer available (closed, deadline passed, or no slots left).';
    } else {
        $stmt = $database->prepare('SELECT id, status FROM applications WHERE client_id = ? AND rsf_id = ? LIMIT 1');
        $stmt->bind_param('ii', $client_id, $wizard_job_id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($dup && $dup['status'] !== 'draft') {
            $wizard_block_msg = 'You have already applied for this position.';
        } else {
            $show_wizard = true;
            $draft_id = $dup['id'] ?? null;

            if (!isset($_SESSION['job_id']) || (int)$_SESSION['job_id'] !== $wizard_job_id) {
                unset($_SESSION['app_step'], $_SESSION['app_answers'], $_SESSION['app_checklist_ok'], $_SESSION['app_rsf_seen'], $_SESSION['wizard_uploads'], $_SESSION['app_uploads_ok'], $_SESSION['draft_app_id']);
                $_SESSION['job_id'] = $wizard_job_id;

                if ($draft_id) {
                    // Resuming a saved draft — rebuild session state from the DB.
                    $_SESSION['draft_app_id']   = (int) $draft_id;
                    $_SESSION['app_rsf_seen']   = true;
                    $_SESSION['app_answers']    = app_load_saved_answers($database, (int) $draft_id);
                    $_SESSION['wizard_uploads'] = app_load_saved_uploads($database, (int) $draft_id);

                    $requiredDocs = myapp_required_documents_for_job($database, $wizard_job_id);
                    $questions    = myapp_questions_for_job($database, $wizard_job_id);

                    $answeredAll = true;
                    foreach ($questions as $q) {
                        if (!empty($q['is_required']) && trim((string)($_SESSION['app_answers'][(int)$q['id']] ?? '')) === '') {
                            $answeredAll = false;
                            break;
                        }
                    }
                    $uploadsOk = app_uploads_complete($_SESSION['wizard_uploads'], $requiredDocs);

                    $_SESSION['app_step']       = $uploadsOk ? 4 : ($answeredAll ? 3 : 2);
                    $_SESSION['app_uploads_ok'] = $uploadsOk;
                } else {
                    $_SESSION['app_step'] = 1;
                }
            }
            $sess_step = max(1, (int)($_SESSION['app_step'] ?? 1));

            // No explicit ?step= in the URL (e.g. the "Finish Your Application"
            // link) → land the applicant exactly where they left off instead
            // of defaulting to Step 1 and forcing them through the RSF
            // checkbox again.
            if (!$wizard_step_explicit) {
                $wizard_url_step = $sess_step;
            }

            if ($wizard_url_step > $sess_step) {
                header("Location: my-applications.php?job_id={$wizard_job_id}&step={$sess_step}");
                exit;
            }
            $wizard_display_step = $wizard_url_step;
            if ($wizard_display_step > 4) {
                header("Location: my-applications.php?job_id={$wizard_job_id}&step=4");
                exit;
            }
            if ($wizard_display_step === 4 && ((int)($_SESSION['app_step'] ?? 0) < 4 || empty($_SESSION['app_uploads_ok']))) {
                $st = max(1, min(3, (int)($_SESSION['app_step'] ?? 1)));
                header("Location: my-applications.php?job_id={$wizard_job_id}&step={$st}");
                exit;
            }
        }
    }
}

$wizard_questions = [];
if ($show_wizard) $wizard_questions = myapp_questions_for_job($database, $wizard_job_id);

// LOAD ALL APPLICATIONS  (with extended detail for tabs)
$applications = [];
$open_app_idx = null;
$forComplianceApps  = [];
$forComplianceCount = 0;

if (!$show_wizard) {
    $stmt = $database->prepare("
        SELECT a.id, a.rsf_id, a.status, a.submitted_at, a.updated_at, a.notes,
               r.rsf_number, r.position_title, r.employer_name, r.worksite_country,
               r.classification, r.qualifications, r.pdf_path, r.slots_available, r.deadline,
               (SELECT e.comments FROM evaluations e WHERE e.application_id = a.id ORDER BY e.evaluated_at DESC LIMIT 1) AS last_eval_comments
               FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.client_id = ?
        ORDER BY a.updated_at DESC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // For each application, pre-load everything needed for tabs
    foreach ($applications as &$app) {
        $aid           = (int)$app['id'];
        $rsf_id        = (int)$app['rsf_id'];
        $app['questions']       = myapp_questions_for_job($database, $rsf_id);
        $app['answers_map']     = myapp_load_answers($database, $aid);
        $app['required_docs']   = myapp_required_documents_for_job($database, $rsf_id);
        $app['uploaded_docs']   = myapp_load_docs($database, $aid);
        $app['status_history']  = myapp_load_status_history($database, $aid);
        $app['doc_comments'] = myapp_load_doc_comments($database, $aid);

        // Merge status changes + document comments into one chronological timeline
        $events = [];
        foreach ($app['status_history'] as $sh) {
            $events[] = ['type' => 'status', 'ts' => strtotime($sh['changed_at']), 'data' => $sh];
        }
        foreach ($app['doc_comments'] as $dc) {
            $events[] = ['type' => 'comment', 'ts' => strtotime($dc['created_at']), 'data' => $dc];
        }
        usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $app['timeline_events'] = $events;
        $app['eval_comments']   = myapp_load_eval_comments($database, $aid);
        $app['is_assigned']     = myapp_is_assigned($database, $aid);
        $app['req_lines']       = myapp_requirement_lines($app);
        $app['status_info']     = myapp_status_info($app['status']);
        // Build uploaded doc lookup
        $app['uploaded_by_req']  = [];
        $app['uploaded_by_type'] = [];
        foreach ($app['uploaded_docs'] as $d) {
            $rid = (int)($d['required_doc_id'] ?? 0);
            if ($rid > 0) $app['uploaded_by_req'][$rid]              = $d;
            else          $app['uploaded_by_type'][$d['document_type']] = $d;
        }
    }
    unset($app);
    $total     = count($applications);
    $app_count = $total;

    // Applications sitting in for_compliance need the applicant to act
    // before an admin/evaluator can touch them again — surface this
    // prominently instead of leaving it buried inside a status tab.
    $forComplianceApps  = array_values(array_filter($applications, fn($a) => $a['status'] === 'for_compliance'));
    $forComplianceCount = count($forComplianceApps);

    $open_app_idx = null;
    if ($open_app_id > 0) {
        foreach ($applications as $i => $a) {
            if ((int)$a['id'] === $open_app_id) {
                $open_app_idx = $i;
                break;
            }
        }
    }
} else {
    // Wizard is active — skip the heavy per-application loading entirely.
    // Just get a cheap count for the dock badge.
    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM applications WHERE client_id = ? AND status <> 'draft'");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $app_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    $total = 0;
}


$submitted = isset($_GET['submitted']);

// Initials
$initials = '';
foreach (explode(' ', trim($client['full_name'] ?? 'Client')) as $w) {
    $initials .= strtoupper($w[0] ?? '');
    if (strlen($initials) >= 2) break;
}
// Aliases so the shared avatar-menu markup works on this page
$client_name     = $client['full_name'] ?? 'Client';
$client_initials = $initials ?: 'U';
// If only one application, auto-select it (skip the list picker)
$single_mode = ($total === 1 && !$show_wizard);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Applications – DMW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]']
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">

    <?php include '../includes/theme-init.php'; ?>
    <style>
        html[data-theme="dark"] .status-filter-panel {
            background: var(--surface) !important;
            border-color: var(--border) !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .55);
        }

        html[data-theme="dark"] .status-filter-option {
            color: var(--text2) !important;
        }

        html[data-theme="dark"] .status-filter-option:hover {
            background: rgba(255, 255, 255, .07) !important;
            color: var(--text1) !important;
        }

        html[data-theme="dark"] .status-filter-option.is-selected {
            background: rgba(59, 130, 246, .2) !important;
            color: #93c5fd !important;
        }

        /* ── Application Picker (multi-app list) ── */
        .app-picker-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .app-picker-item {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s, transform .15s;
            text-decoration: none;
        }

        .app-picker-item:hover {
            border-color: var(--blue-lt);
            box-shadow: 0 4px 16px rgba(59, 130, 246, .13);
            transform: translateY(-1px);
        }

        .app-picker-item.active {
            border-color: var(--blue-lt);
            background: rgba(59, 130, 246, 0.12);
        }

        .app-picker-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
            color: #fff;
        }

        .app-picker-info {
            flex: 1;
            min-width: 0;
        }

        .app-picker-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-picker-meta {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-picker-date {
            font-size: 11px;
            color: var(--text3);
            margin-top: 3px;
        }

        .app-picker-arrow {
            font-size: 11px;
            color: var(--text3);
            flex-shrink: 0;
        }

        /* ── Status pill ── */
        .status-pill {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 4px 10px;
            border-radius: 99px;
            white-space: nowrap;
            flex-shrink: 0;
            text-transform: capitalize;
        }

        .status-pill.pending {
            background: #f1f5f9;
            color: var(--text2);
        }

        .status-pill.approved {
            background: #ecfdf5;
            color: var(--green);
        }

        .status-pill.evaluating {
            background: #eef2ff;
            color: var(--indigo);
        }

        .status-pill.compliance {
            background: #fffbeb;
            color: var(--amber);
        }

        .status-pill.disapproved {
            background: #fef2f2;
            color: var(--red);
        }

        .status-pill.resubmitted {
            background: #e0f2fe;
            color: #0369a1;
        }

        .status-pill.draft {
            background: #fff7ed;
            color: #c2410c;
        }

        /* Dark mode: keep the text color, drop the light background */
        [data-theme="dark"] .status-pill {
            background: transparent !important;
        }

        /* ── Application Detail Panel ── */
        .app-detail-panel {
            display: none;
        }

        .app-detail-panel.active {
            display: block;
            animation: fadeUp .25s ease;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Detail header breadcrumb ── */
        .detail-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px 0;
            margin-bottom: 14px;
        }

        .detail-job-name {
            font-size: 14px;
            font-weight: 800;
            color: var(--text1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .detail-job-meta {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 1px;
        }

        /* ── Two-part Tab System ── */
        .two-part-wrapper {
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        /* Part labels */
        .part-label-bar {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: rgba(148, 163, 184, 0.08);
        }

        .part-segment {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 8px 0 6px;
        }

        .part-segment+.part-segment {
            border-left: 1px solid var(--border);
        }

        .part-segment-num {
            font-size: 8.5px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 1px;
        }

        .part-segment-name {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text2);
        }

        .part-segment.part-active .part-segment-name {
            color: var(--blue-lt);
        }

        .part-segment.part-active .part-segment-num {
            color: var(--blue-lt);
        }

        /* Tab row */
        .app-tabs {
            display: flex;
            width: 100%;
            overflow-x: hidden;
            /* was auto — no longer needed once tabs stretch */
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .app-tabs::-webkit-scrollbar {
            display: none;
        }

        .atab {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            flex: 1 1 0;
            /* was flex-shrink:0 — now grows/shrinks equally, fills row */
            min-width: 0;
            /* allows text to actually shrink/ellipsis instead of overflowing */
            padding: 10px 8px;
            border-bottom: 2.5px solid transparent;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3);
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color .18s, border-color .18s, background .18s;
            font-family: 'DM Sans', sans-serif;
            background: none;
            border-left: none;
            border-right: none;
            border-top: none;
        }

        .atab i {
            font-size: 13px;
        }

        .atab:hover {
            color: var(--blue);
            background: rgba(59, 130, 246, 0.08);
        }

        .atab.active {
            color: var(--blue);
            border-bottom-color: var(--blue-lt);
        }

        .atab.done {
            color: var(--green);
            border-bottom-color: #22c55e;
            background: rgba(16, 185, 129, 0.1);
        }

        .atab.done:hover {
            color: var(--green);
            background: rgba(16, 185, 129, 0.18);
        }

        .atab:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        @media (max-width: 480px) {
            .atab {
                padding: 8px 4px;
                font-size: 8px;
                gap: 2px;
            }

            .atab i {
                font-size: 11px;
            }
        }

        @media (max-width: 360px) {

            .atab span,
            .atab {
                font-size: 0;
                /* hide text label */
            }

            .atab i {
                font-size: 14px;
            }
        }

        /* Part divider tab */
        .atab-divider {
            width: 1px;
            flex: 0 0 auto;
            background: var(--border);
            margin: 6px 0;
        }

        /* Tab content */
        .atab-content {
            display: none;
        }

        .atab-content.active {
            display: block;
            animation: fadeUp .22s ease;
        }

        /* ── Current Stage Indicator (Status tab) ── */
        .atab.current-stage {
            color: var(--green) !important;
            border-bottom-color: var(--green) !important;
            background: rgba(16, 185, 129, 0.1);
            position: relative;
        }

        .atab.current-stage:hover {
            color: var(--green) !important;
            background: rgba(16, 185, 129, 0.18);
        }

        .atab.current-stage::after {
            content: '';
            position: absolute;
            top: 6px;
            right: 10px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            animation: dmwPulseDot 1.6s infinite;
        }

        @keyframes dmwPulseDot {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, .55);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        /* Checkmark badge for already-completed tabs */
        .atab.done {
            position: relative;
        }

        .atab.done::before {
            content: '\2713';
            position: absolute;
            top: 5px;
            right: 9px;
            font-size: 9px;
            font-weight: 900;
            color: var(--green);
        }

        .tab-body {
            padding: 18px 16px;
        }

        /* ── Content Blocks ── */
        .info-grid {
            display: grid;
            gap: 10px 18px;
            grid-template-columns: 130px 1fr;
            margin-bottom: 14px;
        }

        @media (max-width: 480px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-dt {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            padding-top: 2px;
        }

        .info-dd {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1);
            margin: 0;
            line-height: 1.5;
        }

        .qual-box {
            background: rgba(148, 163, 184, 0.08);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            line-height: 1.6;
            color: var(--text2);
            white-space: pre-wrap;
        }

        .pdf-embed {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .pdf-embed iframe {
            width: 100%;
            height: 500px;
            border: 0;
            display: block;
        }

        /* Question answer row */
        .qa-row {
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .qa-row:last-child {
            border-bottom: none;
        }

        .qa-q {
            font-size: 12px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 4px;
        }

        .qa-a {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1);
        }

        /* Compliance checklist */
        .checklist-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 9px;
        }

        .checklist-icon {
            color: var(--green);
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* Document row */
        .doc-row {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: var(--surface);
        }

        .doc-row.has-file {
            border-color: rgba(16, 185, 129, 0.35);
            background: rgba(16, 185, 129, 0.08);
        }

        .doc-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .doc-info {
            flex: 1;
            min-width: 0;
        }

        .doc-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
        }

        .doc-type {
            font-size: 11px;
            color: var(--text3);
            margin-top: 1px;
        }

        .doc-file {
            font-size: 11.5px;
            color: var(--green);
            font-weight: 600;
            margin-top: 3px;
        }

        .doc-missing {
            font-size: 11.5px;
            color: var(--text3);
            font-style: italic;
            margin-top: 3px;
        }

        /* Review summary blocks */
        .review-section {
            margin-bottom: 16px;
        }

        .review-section-title {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        /* ── Status Tab: timeline ── */
        .status-current-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            margin-bottom: 18px;
        }

        .timeline {
            position: relative;
            padding-left: 28px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 9px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 16px;
        }

        .timeline-dot {
            position: absolute;
            left: -22px;
            top: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2.5px solid #fff;
            box-shadow: 0 0 0 2px var(--border);
            background: #cbd5e1;
        }

        .timeline-dot.done {
            background: var(--green);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35);
        }

        .timeline-dot.current {
            background: var(--blue-lt);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.35);
        }

        .timeline-dot.bad {
            background: var(--red);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.35);
        }

        .timeline-dot.warn {
            background: var(--amber);
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35);
        }

        .timeline-to {
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
        }

        .timeline-date {
            font-size: 11px;
            color: var(--text3);
            margin-top: 2px;
        }

        .timeline-reason {
            font-size: 12px;
            color: var(--text2);
            font-style: italic;
            margin-top: 3px;
        }

        .eval-note-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 14px;
        }

        .eval-note-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--amber);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }

        .eval-note-text {
            font-size: 13px;
            color: var(--text1);
            font-style: italic;
            line-height: 1.55;
        }

        /* ── Wiz re-use styles ── */
        .wiz-block {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--amber);
        }

        .wiz-block a {
            color: var(--blue-lt);
            font-weight: 700;
        }

        .wiz-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .wiz-card-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .wiz-step-pill {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--blue-lt);
            margin-bottom: 8px;
        }

        .wiz-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--text1);
            margin: 0 0 6px;
        }

        .wiz-sub {
            font-size: 12.5px;
            color: var(--text3);
            margin: 0;
            line-height: 1.45;
        }

        .wiz-body {
            padding: 18px;
        }

        .wiz-form {
            padding: 18px;
        }

        .wiz-muted {
            font-size: 12px;
            color: var(--text3);
            margin: 0 0 12px;
        }

        .wiz-field {
            margin-bottom: 14px;
        }

        .wiz-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 6px;
        }

        .wiz-req {
            color: #dc2626;
        }

        .wiz-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            background: var(--surface);
            color: var(--text1);
            box-sizing: border-box;
        }

        .wiz-input:focus {
            outline: none;
            border-color: var(--blue-lt);
        }

        .wiz-dl {
            display: grid;
            gap: 10px 16px;
            grid-template-columns: 1fr;
            margin: 0 0 14px;
        }

        @media (min-width: 560px) {
            .wiz-dl {
                grid-template-columns: 140px 1fr;
            }
        }

        .wiz-dt {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
        }

        .wiz-dd {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1);
            margin: 0;
            line-height: 1.5;
        }

        .wiz-qual {
            background: rgba(148, 163, 184, 0.08);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            line-height: 1.55;
            color: var(--text2);
            white-space: pre-wrap;
        }

        .wiz-pdf {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: var(--surface);
        }

        .wiz-pdf iframe {
            width: 100%;
            height: 520px;
            border: 0;
            display: block;
            background: #fff;
        }

        .wiz-check-item {
            margin-bottom: 10px;
        }

        .wiz-check-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text1);
            cursor: pointer;
            line-height: 1.45;
        }

        .wiz-check {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            flex-shrink: 0;
            accent-color: var(--blue);
        }

        .wiz-checklist {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .wiz-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            align-items: center;
        }

        .wiz-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: opacity .15s, transform .1s;
        }

        .wiz-btn:active {
            transform: scale(.98);
        }

        .wiz-btn.primary {
            background: var(--blue);
            color: #fff;
        }

        .wiz-btn.secondary {
            background: var(--surface);
            color: var(--text2);
            border: 1px solid var(--border);
        }

        .wiz-btn.amber {
            background: var(--amber);
            color: #fff;
        }

        .wiz-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .wiz-error {
            color: var(--red);
            font-size: 12.5px;
            font-weight: 600;
            margin: 10px 0 0;
        }

        .wiz-error.hidden {
            display: none;
        }

        .wiz-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.35);
            border-radius: 14px;
            padding: 18px;
            text-align: center;
        }

        .wiz-success h3 {
            margin: 0 0 8px;
            color: var(--green);
            font-size: 16px;
        }

        .wiz-success p {
            margin: 0 0 14px;
            color: var(--text1);
            font-size: 13px;
        }

        .wiz-review-block {
            margin-bottom: 16px;
        }

        .wiz-review-block h4 {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            margin: 0 0 8px;
        }

        .wiz-upload-row {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            background: var(--surface);
        }

        .wiz-upload-row.has-file {
            border-color: rgba(16, 185, 129, 0.35);
            background: rgba(16, 185, 129, 0.08);
        }

        .wiz-upload-filename {
            font-size: 12px;
            color: var(--green);
            font-weight: 600;
            margin: 4px 0 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wiz-upload-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-top: 8px;
        }

        .wiz-doc-upload-form {
            display: contents;
        }

        .wiz-preview-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(15, 23, 42, 0.55);
            padding: 24px 16px;
            align-items: center;
            justify-content: center;
        }

        .wiz-preview-overlay.open {
            display: flex;
        }

        .wiz-preview-modal {
            width: min(920px, 100%);
            height: min(86vh, 820px);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
        }

        .wiz-preview-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .wiz-preview-name {
            flex: 1;
            min-width: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wiz-preview-body {
            flex: 1;
            min-height: 0;
            background: #0f172a;
        }

        .wiz-preview-body iframe,
        .wiz-preview-body img {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            object-fit: contain;
            background: #0f172a;
        }

        .wiz-preview-unsupported {
            color: #e2e8f0;
            font-size: 13px;
            line-height: 1.5;
            padding: 32px 20px;
            text-align: center;
        }

        .wiz-preview-unsupported a {
            color: #93c5fd;
            font-weight: 700;
        }

        /* ── Misc ── */
        .success-banner {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: var(--green);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        /* ── Action-needed banner (for_compliance applications) ── */
        .action-needed-banner {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            background: rgba(245, 158, 11, 0.1);
            border: 1.5px solid rgba(245, 158, 11, 0.4);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 16px;
        }

        .action-needed-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.18);
            color: var(--amber);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .action-needed-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text1);
            margin-bottom: 3px;
        }

        .action-needed-text {
            font-size: 12.5px;
            color: var(--text2);
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .action-needed-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--amber);
            color: #fff;
            font-size: 12.5px;
            font-weight: 700;
            padding: 9px 16px;
            border-radius: 10px;
            text-decoration: none;
        }

        .action-needed-btn:hover {
            opacity: .9;
        }

        .action-needed-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action-needed-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--card);
            border: 1px solid rgba(245, 158, 11, 0.4);
            color: var(--text1);
            font-size: 11.5px;
            font-weight: 700;
            padding: 7px 12px;
            border-radius: 9px;
            text-decoration: none;
        }

        .action-needed-chip:hover {
            border-color: var(--amber);
            color: var(--amber);
        }

        .action-needed-chip i {
            font-size: 9px;
        }

        /* ── "Action needed" tag next to status pills in list/table ── */
        .action-req-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
            font-weight: 800;
            color: var(--amber);
            margin-top: 3px;
            white-space: nowrap;
        }

        .action-req-tag i {
            font-size: 8px;
        }

        /* ── Action Required card inside Status tab ── */
        .action-required-card {
            background: rgba(245, 158, 11, 0.08);
            border: 1.5px solid rgba(245, 158, 11, 0.35);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }

        .action-required-head {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            font-weight: 800;
            color: var(--amber);
            margin-bottom: 4px;
        }

        .action-required-desc {
            font-size: 12px;
            color: var(--text2);
            line-height: 1.5;
            margin: 0;
        }

        .empty-state {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 48px 24px;
            text-align: center;
        }

        .empty-state i {
            font-size: 40px;
            color: var(--border);
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 14px;
            font-weight: 600;
            color: var(--text2);
        }

        .empty-state span {
            font-size: 12px;
            color: var(--text3);
            display: block;
            margin-top: 4px;
        }

        .browse-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 18px;
            background: var(--blue);
            color: #fff;
            font-size: 12.5px;
            font-weight: 700;
            padding: 9px 20px;
            border-radius: 10px;
            text-decoration: none;
        }

        .sec-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 10px;
            display: block;
        }

        .edit-app-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--blue);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            font-family: 'DM Sans', sans-serif;
        }

        .edit-app-btn:hover {
            opacity: .9;
        }

        /* Part 2 status overview card */
        .status-overview {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }

        /* Progress steps row for status */
        .progress-steps {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 18px;
            overflow-x: auto;
        }

        .ps-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            flex: 1;
            min-width: 52px;
        }

        .ps-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
            border: 2px solid var(--border);
            background: var(--surface);
            color: var(--text3);
            position: relative;
            z-index: 1;
        }

        .ps-circle.done {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }

        .ps-circle.current {
            background: var(--blue-lt);
            border-color: var(--blue-lt);
            color: #fff;
        }

        .ps-circle.bad {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
        }

        .ps-circle.warn {
            background: var(--amber);
            border-color: var(--amber);
            color: #fff;
        }

        .ps-label {
            font-size: 9px;
            font-weight: 700;
            color: var(--text3);
            text-align: center;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        .ps-connector {
            flex: 1;
            height: 2px;
            background: var(--border);
            min-width: 8px;
        }

        .ps-connector.done {
            background: var(--green);
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;

        }

        .app-picker-title {
            font-size: 15px;
        }

        .app-picker-meta {
            font-size: 13px;
        }

        .app-picker-date {
            font-size: 12.5px;
        }

        .status-pill {
            font-size: 12px;
        }

        .detail-job-name {
            font-size: 15.5px;
        }

        .detail-job-meta {
            font-size: 13px;
        }

        .part-segment-num {
            font-size: 10px;
        }

        .part-segment-name {
            font-size: 12px;
        }

        .atab {
            font-size: 11px;
        }

        .atab i {
            font-size: 14.5px;
        }

        .info-dt {
            font-size: 11.5px;
        }

        .info-dd {
            font-size: 14.5px;
        }

        .qual-box {
            font-size: 14px;
        }

        .qa-q {
            font-size: 13.5px;
        }

        .qa-a {
            font-size: 14.5px;
        }

        .doc-name {
            font-size: 14.5px;
        }

        .doc-type {
            font-size: 12.5px;
        }

        .doc-file,
        .doc-missing {
            font-size: 13px;
        }

        .review-section-title {
            font-size: 11.5px;
        }

        .status-current-badge {
            font-size: 15.5px;
        }

        .timeline-to {
            font-size: 14.5px;
        }

        .timeline-date {
            font-size: 12.5px;
        }

        .timeline-reason {
            font-size: 13.5px;
        }

        .eval-note-label {
            font-size: 11.5px;
        }

        .eval-note-text {
            font-size: 14.5px;
        }

        .ps-circle {
            font-size: 13.5px;
        }

        .ps-label {
            font-size: 10.5px;
        }

        .wiz-block {
            font-size: 14.5px;
        }

        .wiz-step-pill {
            font-size: 11px;
        }

        .wiz-title {
            font-size: 18.5px;
        }

        .wiz-sub {
            font-size: 14px;
        }

        .wiz-body,
        .wiz-form {
            font-size: 14.5px;
        }

        .wiz-muted {
            font-size: 13.5px;
        }

        .wiz-label {
            font-size: 13.5px;
        }

        .wiz-input {
            font-size: 14.5px;
        }

        .wiz-dt {
            font-size: 11.5px;
        }

        .wiz-dd {
            font-size: 14.5px;
        }

        .wiz-qual {
            font-size: 14px;
        }

        .wiz-check-label {
            font-size: 14px;
        }

        .wiz-btn {
            font-size: 14px;
        }

        .wiz-error {
            font-size: 14px;
        }

        .wiz-success h3 {
            font-size: 17.5px;
        }

        .wiz-success p {
            font-size: 14.5px;
        }

        .wiz-review-block h4 {
            font-size: 12.5px;
        }

        .wiz-upload-filename {
            font-size: 13.5px;
        }

        .wiz-preview-name {
            font-size: 14.5px;
        }

        .wiz-preview-unsupported {
            font-size: 14.5px;
        }

        .success-banner {
            font-size: 14.5px;
        }

        .action-needed-title {
            font-size: 15.5px;
        }

        .action-needed-text {
            font-size: 14px;
        }

        .action-needed-btn {
            font-size: 14px;
        }

        .action-needed-chip {
            font-size: 13px;
        }

        .action-req-tag {
            font-size: 11px;
        }

        .action-required-head {
            font-size: 14px;
        }

        .action-required-desc {
            font-size: 13.5px;
        }

        .empty-state p {
            font-size: 15.5px;
        }

        .empty-state span {
            font-size: 13.5px;
        }

        .browse-btn {
            font-size: 14px;
        }

        .sec-label {
            font-size: 11.5px;
        }

        .edit-app-btn {
            font-size: 13.5px;
        }

        /* The original small-screen tab rules are re-declared here, AFTER the
           base .atab above, otherwise the base would override them. */
        @media (max-width: 480px) {
            .atab {
                font-size: 10px;
            }

            .atab i {
                font-size: 13px;
            }
        }

        @media (max-width: 360px) {
            .atab {
                font-size: 0;
            }

            /* labels still hidden on very small phones */
            .atab i {
                font-size: 15px;
            }
        }

        /* B) Tailwind arbitrary sizes (avatar menu, picker table/cards, search bar).
           !important required: the Tailwind CDN sheet is injected after this block. */
        .text-\[9px\] {
            font-size: 10.5px !important;
        }

        .text-\[10px\] {
            font-size: 11.5px !important;
        }

        .text-\[10\.5px\] {
            font-size: 12px !important;
        }

        .text-\[11px\] {
            font-size: 12.5px !important;
        }

        .text-\[11\.5px\] {
            font-size: 13px !important;
        }

        .text-\[12px\],
        .text-xs {
            font-size: 13.5px !important;
        }

        .text-\[12\.5px\] {
            font-size: 14px !important;
        }

        .text-\[13px\] {
            font-size: 14.5px !important;
        }

        .text-\[13\.5px\] {
            font-size: 15px !important;
        }

        .text-sm {
            font-size: 15.5px !important;
        }

        /* C) Inline style="font-size:…" attributes. Class CSS can't reach these,
           so match the attribute value. Only text sizes are mapped; the inline
           15/16/18/20px values are icons and are left alone. */
        [style*="font-size:10px"] {
            font-size: 11.5px !important;
        }

        [style*="font-size:11px"] {
            font-size: 12.5px !important;
        }

        [style*="font-size:11.5px"] {
            font-size: 13px !important;
        }

        [style*="font-size:12px"] {
            font-size: 13.5px !important;
        }

        [style*="font-size:12.5px"] {
            font-size: 14px !important;
        }

        [style*="font-size:13px"] {
            font-size: 14.5px !important;
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body class="min-h-screen">

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; APPLICANT PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <!-- Dark mode toggle -->
            <button type="button" class="theme-toggle" title="Toggle dark mode">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>
            <div class="tb-btn"><i class="fas fa-bell"></i><span class="notif-dot"></span></div>
            <div class="relative" id="avatarMenuWrap">
                <button type="button" id="avatarMenuBtn"
                    class="topbar-avatar cursor-pointer border-0 p-0"
                    aria-haspopup="menu" aria-expanded="false" aria-controls="avatarMenu" aria-label="Account menu"
                    <?= $has_picture ? "style=\"background-image:url('" . htmlspecialchars($picture_url, ENT_QUOTES) . "');background-size:cover;background-position:center;\"" : '' ?>>
                    <?= $has_picture ? '' : htmlspecialchars($client_initials) ?>
                </button>

                <div id="avatarMenu" role="menu"
                    class="hidden absolute right-0 top-[calc(100%+10px)] w-[240px] bg-[var(--card)] border border-[var(--border)] rounded-[14px] shadow-[0_16px_40px_rgba(11,31,75,.18)] overflow-hidden z-[700]">

                    <!-- Header: who is signed in -->
                    <div class="py-3 px-4 border-b border-[var(--border)] bg-[var(--surface)]">
                        <div class="text-[12.5px] font-bold text-[var(--text1)] truncate"><?= htmlspecialchars($client_name) ?></div>
                        <div class="text-[10.5px] text-[var(--text3)] truncate"><?= htmlspecialchars($client_email) ?></div>
                    </div>

                    <!-- Navigation -->
                    <div class="py-1.5">
                        <a href="profile.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-user-gear w-4 text-center text-[var(--blue-lt)]"></i> My Profile
                        </a>
                        <a href="my-applications.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-file-lines w-4 text-center text-[var(--blue-lt)]"></i> My Applications
                        </a>
                        <a href="jobs.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-briefcase w-4 text-center text-[var(--blue-lt)]"></i> Browse Jobs
                        </a>
                    </div>

                    <!-- Logout (reuses your existing logout() function) -->
                    <div class="border-t border-[var(--border)] py-1.5">
                        <button type="button" role="menuitem" onclick="logout('Applicant')"
                            class="w-full flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[#dc2626] bg-transparent border-0 cursor-pointer text-left transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-arrow-right-from-bracket w-4 text-center"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top flex items-center gap-2 flex-nowrap" style="width:100%;">
            <div class="subbar-greeting min-w-0">
                <h2>My Applications</h2>
                <p><?= $total ?> total application<?= $total !== 1 ? 's' : '' ?></p>
            </div>
            <div class="subbar-actions flex items-center gap-2 flex-shrink-0" style="margin-left:auto;">
                <a href="jobs.php" class="filter-pill"><i class="fas fa-briefcase"></i> <span class="hidden sm:inline">Browse Jobs</span></a>
                <a href="index.php" class="filter-pill"><i class="fas fa-house"></i> <span class="hidden sm:inline">Dashboard</span></a>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <?php if ($wizard_block_msg !== ''): ?>
            <div class="wiz-block fade-in d1">
                <?= htmlspecialchars($wizard_block_msg) ?>
                &nbsp;<a href="jobs.php">Back to job opportunities</a>
            </div>
        <?php endif; ?>

        <?php if ($submitted): ?>
            <div class="success-banner fade-in d1">
                <i class="fas fa-circle-check"></i>
                Application submitted successfully. It will be reviewed by an evaluator.
            </div>
        <?php endif; ?>



        <!-- SECTION A: APPLICATION WIZARD  (only shown when applying) -->
        <?php if ($show_wizard && $wizard_job): ?>
            <?php
            $sess_step_ui = max(1, (int)($_SESSION['app_step'] ?? 1));
            $tab_step     = $wizard_display_step;
            if ($tab_step < 1 || $tab_step > 4) $tab_step = min(4, max(1, $sess_step_ui));
            $is_done_1 = $sess_step_ui > 1;
            $is_done_2 = $sess_step_ui > 2;
            $is_done_3 = $sess_step_ui > 3;
            $can_2 = $sess_step_ui >= 2;
            $can_3 = $sess_step_ui >= 3;
            $can_4 = $sess_step_ui >= 4 && !empty($_SESSION['app_uploads_ok']);
            ?>

            <p class="sec-label fade-in d5" style="margin-top:1.3rem">
                <i class="fas fa-file-lines"></i> &nbsp;New Application — <?= htmlspecialchars($wizard_job['position_title'] ?? '') ?>
            </p>

            <div class="two-part-wrapper fade-in d5" id="applicationWizardGuide">

                <!-- Part labels -->
                <div class="part-label-bar">
                    <div class="part-segment part-active">
                        <!--<div class="part-segment-num">Part 1</div>-->
                        <div class="part-segment-name"><i class="fas fa-pen-to-square" style="font-size:10px;margin-right:4px"></i>Application Phase</div>
                    </div>
                    <div class="part-segment">
                        <!--<div class="part-segment-num">Part 2</div>-->
                        <div class="part-segment-name"><i class="fas fa-chart-line" style="font-size:10px;margin-right:4px"></i>Status Tracking</div>
                    </div>
                </div>

                <!-- Tab bar -->
                <div class="app-tabs" id="processTabs">
                    <button id="ptab-rsf" class="atab <?= $tab_step === 1 ? 'active' : '' ?> <?= $is_done_1 ? 'done' : '' ?>" onclick="switchWizTab('tab1',this)"><i class="fas fa-file-lines"></i> RSF</button>
                    <button id="ptab-questions" class="atab <?= $tab_step === 2 ? 'active' : '' ?> <?= $is_done_2 ? 'done' : '' ?>" onclick="switchWizTab('tab2',this)" <?= $can_2 ? '' : 'disabled' ?>><i class="fas fa-edit"></i> Questions</button>
                    <button id="ptab-uploads" class="atab <?= $tab_step === 3 ? 'active' : '' ?> <?= $is_done_3 ? 'done' : '' ?>" onclick="switchWizTab('tab3',this)" <?= $can_3 ? '' : 'disabled' ?>><i class="fas fa-file-arrow-up"></i> Requirements</button>
                    <button id="ptab-review" class="atab <?= $tab_step === 4 ? 'active' : '' ?>" onclick="switchWizTab('tab4',this)" <?= $can_4 ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> Review & Submit</button>
                    <div class="atab-divider"></div>
                    <button class="atab" onclick="alert('Submit your application first to track status here.')" disabled><i class="fas fa-chart-line"></i> Status</button>
                </div>

                <!-- Tab Contents -->
                <div class="tab-body">

                    <!-- TAB 1: RSF -->
                    <div id="tab1" class="atab-content <?= $tab_step === 1 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 1): ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 1 of 4</span>
                                    <h3 class="wiz-title">Recruitment Specification (RSF)</h3>
                                    <p class="wiz-sub">Review the position details. When ready, proceed to the application questions.</p>
                                </div>
                                <div class="wiz-body">
                                    <dl class="wiz-dl">
                                        <dt class="wiz-dt">Job title / position</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['position_title'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">RSF number</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['rsf_number'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Employer</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['employer_name'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Worksite (country)</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['worksite_country'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Classification</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['classification'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Slots available</dt>
                                        <dd class="wiz-dd"><?= (int)($wizard_job['slots_available'] ?? 0) ?></dd>
                                        <dt class="wiz-dt">Deadline</dt>
                                        <dd class="wiz-dd"><?= $wizard_job['deadline'] ? date('F d, Y', strtotime($wizard_job['deadline'])) : 'Open' ?></dd>
                                        <!--<dt class="wiz-dt">Qualifications &amp; requirements</dt>-->
                                        <!-- <dd class="wiz-dd">
                                            <div class="wiz-qual"><?= htmlspecialchars($wizard_job['qualifications'] ?? 'See RSF PDF for full details.') ?></div>
                                        </dd>-->
                                    </dl>
                                    <?php if (!empty($wizard_job['pdf_path'])): ?>
                                        <p class="wiz-muted"><a href="../<?= htmlspecialchars($wizard_job['pdf_path']) ?>" target="_blank" style="font-weight:700;color:var(--blue-lt);"><i class="fas fa-file-pdf"></i> Open full RSF PDF</a></p>
                                        <div class="wiz-pdf"><iframe src="../<?= htmlspecialchars($wizard_job['pdf_path']) ?>" title="RSF PDF Preview" loading="lazy"></iframe></div>
                                    <?php endif; ?>
                                    <div class="wiz-field" style="margin-top:14px;margin-bottom:0;">
                                        <label class="wiz-check-label"><input type="checkbox" id="rsf_ack" class="wiz-check" /><span>I have read and understood the <strong>Recruitment Specification Form (RSF)</strong> before proceeding.</span></label>
                                    </div>
                                    <p id="wizard-step1-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <a href="jobs.php" class="wiz-btn secondary">Cancel</a>
                                        <button type="button" class="wiz-btn primary" id="wizard-step1-proceed">Proceed <i class="fas fa-arrow-right"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 1 of 4 &middot; Completed</span>
                                    <h3 class="wiz-title">Recruitment Specification (RSF)</h3>
                                    <p class="wiz-sub"><i class="fas fa-check-circle" style="color:var(--green)"></i> You confirmed you reviewed this position's RSF.</p>
                                </div>
                                <div class="wiz-body">
                                    <dl class="wiz-dl">
                                        <dt class="wiz-dt">Job title / position</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['position_title'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">RSF number</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['rsf_number'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Employer</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['employer_name'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Worksite (country)</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['worksite_country'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Classification</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['classification'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Slots available</dt>
                                        <dd class="wiz-dd"><?= (int)($wizard_job['slots_available'] ?? 0) ?></dd>
                                        <dt class="wiz-dt">Deadline</dt>
                                        <dd class="wiz-dd"><?= $wizard_job['deadline'] ? date('F d, Y', strtotime($wizard_job['deadline'])) : 'Open' ?></dd>
                                    </dl>
                                    <?php if (!empty($wizard_job['pdf_path'])): ?>
                                        <p class="wiz-muted"><a href="../<?= htmlspecialchars($wizard_job['pdf_path']) ?>" target="_blank" style="font-weight:700;color:var(--blue-lt);"><i class="fas fa-file-pdf"></i> Open full RSF PDF</a></p>
                                        <div class="wiz-pdf"><iframe src="../<?= htmlspecialchars($wizard_job['pdf_path']) ?>" title="RSF PDF Preview" loading="lazy"></iframe></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 2: Questions -->
                    <div id="tab2" class="atab-content <?= $tab_step === 2 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 2 || $sess_step_ui > 2): ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 2 of 4<?= $sess_step_ui > 2 ? ' &middot; You can still edit these' : '' ?></span>
                                    <h3 class="wiz-title">Application Questions</h3>
                                    <p class="wiz-sub"><?= htmlspecialchars($wizard_job['position_title'] ?? 'Position') ?> — answer all required fields.</p>
                                </div>
                                <form id="wizard-step2-form" class="wiz-form">
                                    <input type="hidden" name="job_id" value="<?= (int) $wizard_job_id ?>">
                                    <?php if ($wizard_questions === []): ?>
                                        <p class="wiz-muted">No evaluation questions configured. You may continue.</p>
                                        <?php else: foreach ($wizard_questions as $q):
                                            $req = !empty($q['is_required']);
                                            $qid = (int) $q['id'];
                                            $label = trim((string)$q['question_text']);
                                            $useTA = strlen($label) > 160;
                                            $prev  = is_array($_SESSION['app_answers'] ?? null) ? (string)($_SESSION['app_answers'][$qid] ?? '') : ''; ?>
                                            <div class="wiz-field">
                                                <label class="wiz-label" for="q_<?= $qid ?>"><?= htmlspecialchars($label) ?><?php if ($req): ?><span class="wiz-req">*</span><?php endif; ?></label>
                                                <?php if ($useTA): ?>
                                                    <textarea class="wiz-input" id="q_<?= $qid ?>" name="q_<?= $qid ?>" rows="4" <?= $req ? 'required' : '' ?>><?= htmlspecialchars($prev) ?></textarea>
                                                <?php else: ?>
                                                    <input class="wiz-input" type="text" id="q_<?= $qid ?>" name="q_<?= $qid ?>" value="<?= htmlspecialchars($prev) ?>" <?= $req ? 'required' : '' ?>>
                                                <?php endif; ?>
                                            </div>
                                    <?php endforeach;
                                    endif; ?>
                                    <p id="wizard-step2-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <a class="wiz-btn secondary" href="my-applications.php?job_id=<?= (int) $wizard_job_id ?>&step=1">Back</a>
                                        <button type="submit" class="wiz-btn primary" id="wizard-step2-submit"><?= $sess_step_ui > 2 ? 'Save Changes' : 'Continue' ?> <i class="fas fa-arrow-right"></i></button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?><p class="wiz-muted" style="padding-top:4px;">Complete the previous step to access questions.</p>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 3: Requirements (Uploads) -->
                    <div id="tab3" class="atab-content <?= $tab_step === 3 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 3 || $sess_step_ui > 3):
                            $wiz_uploads = is_array($_SESSION['wizard_uploads'] ?? null) ? $_SESSION['wizard_uploads'] : [];
                            $doc_types   = myapp_required_documents_for_job($database, (int)$wizard_job_id); ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 3 of 4<?= $sess_step_ui > 3 ? ' &middot; You can still replace files' : '' ?></span>
                                    <h3 class="wiz-title">Upload Requirements</h3>
                                    <p class="wiz-sub">Upload one file per required document (PDF, JPG, or PNG).</p>
                                </div>
                                <div class="wiz-form">
                                    <?php foreach ($doc_types as $doc):
                                        $docType = (string)($doc['document_type'] ?? '');
                                        $docName = (string)($doc['document_name'] ?? $docType);
                                        $reqId   = (int)($doc['id'] ?? 0);
                                        $isReq   = !empty($doc['is_required']);
                                        $uKey    = $reqId > 0 ? 'doc_' . $reqId : 'type_' . strtolower($docType);
                                        $hasFile = !empty($wiz_uploads[$uKey]['file_name']);
                                        $wizFileName = (string)($wiz_uploads[$uKey]['file_name'] ?? '');
                                        $wizFilePath = (string)($wiz_uploads[$uKey]['file_path'] ?? '');
                                        $wizFileExt  = strtolower(pathinfo($wizFilePath !== '' ? $wizFilePath : $wizFileName, PATHINFO_EXTENSION)); ?>
                                        <div class="wiz-upload-row <?= $hasFile ? 'has-file' : '' ?>">
                                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                                <i class="fas <?= $hasFile ? 'fa-file-circle-check' : 'fa-file-arrow-up' ?>" style="color:<?= $hasFile ? 'var(--green)' : 'var(--text3)' ?>;font-size:15px;"></i>
                                                <strong style="font-size:13px;color:var(--text1);"><?= htmlspecialchars($docName) ?></strong>
                                                <?php if ($isReq): ?><span class="wiz-req" style="font-size:11px;">*</span><?php endif; ?>
                                            </div>
                                            <?php if (!empty($doc['description'])): ?><p class="wiz-muted" style="margin:0 0 6px 23px;"><?= htmlspecialchars($doc['description']) ?></p><?php endif; ?>
                                            <?php if ($hasFile): ?>
                                                <div class="wiz-upload-filename" style="margin-left:23px;">
                                                    <i class="fas fa-check"></i><?= htmlspecialchars($wizFileName) ?>
                                                </div>
                                            <?php endif; ?>
                                            <form class="wiz-doc-upload-form" data-action="step3_upload">
                                                <input type="hidden" name="action" value="step3_upload">
                                                <input type="hidden" name="job_id" value="<?= (int)$wizard_job_id ?>">
                                                <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">
                                                <input type="hidden" name="document_name" value="<?= htmlspecialchars($docName) ?>">
                                                <input type="hidden" name="required_doc_id" value="<?= $reqId ?>">
                                                <div class="wiz-upload-actions">
                                                    <?php if ($hasFile && $wizFilePath !== ''): ?>
                                                        <button type="button" class="wiz-btn secondary wiz-preview-btn" style="padding:7px 14px;font-size:11.5px;"
                                                            data-preview-src="../<?= htmlspecialchars($wizFilePath) ?>"
                                                            data-preview-name="<?= htmlspecialchars($wizFileName) ?>"
                                                            data-preview-ext="<?= htmlspecialchars($wizFileExt) ?>">
                                                            <i class="fas fa-eye"></i> Preview
                                                        </button>
                                                    <?php endif; ?>
                                                    <input type="file" name="doc_file" id="file_<?= $uKey ?>" accept=".pdf,.jpg,.jpeg,.png" style="display:none;">
                                                    <button type="button" class="wiz-btn secondary wiz-file-picker-btn" style="padding:7px 14px;font-size:11.5px;">
                                                        <i class="fas fa-upload"></i> <?= $hasFile ? 'Replace' : 'Choose File' ?>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                    <p id="wizard-step3-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <a class="wiz-btn secondary" href="my-applications.php?job_id=<?= (int)$wizard_job_id ?>&step=2">Back</a>
                                        <button type="button" class="wiz-btn primary" id="wizard-step3-continue"><?= $sess_step_ui > 3 ? 'Save & Return to Review' : 'Continue' ?> <i class="fas fa-arrow-right"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?><p class="wiz-muted" style="padding-top:4px;">Complete the previous step to upload documents.</p>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 4: Review & Submit -->
                    <div id="tab4" class="atab-content <?= $tab_step === 4 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 4):
                            $wiz_uploads_rev = is_array($_SESSION['wizard_uploads'] ?? null) ? $_SESSION['wizard_uploads'] : [];
                            $doc_types_rev   = myapp_required_documents_for_job($database, (int)$wizard_job_id); ?>
                            <div class="wiz-card" id="wizard-step4-review">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 4 of 4</span>
                                    <h3 class="wiz-title">Review &amp; Submit</h3>
                                    <p class="wiz-sub">Review all your information, then submit your application.</p>
                                </div>
                                <div class="wiz-body">
                                    <!-- RSF summary -->
                                    <div class="wiz-review-block">
                                        <h4>Position</h4>
                                        <p style="font-size:13px;font-weight:700;color:var(--text1);margin:0;"><?= htmlspecialchars($wizard_job['position_title'] ?? '—') ?></p>
                                        <p style="font-size:12px;color:var(--text3);margin:3px 0 0;"><?= htmlspecialchars($wizard_job['employer_name'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($wizard_job['worksite_country'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($wizard_job['rsf_number'] ?? '—') ?></p>
                                    </div>
                                    <!-- Answers summary -->
                                    <?php if ($wizard_questions !== []):
                                        // Build the rows once, render them in both the table and the mobile cards.
                                        $rev_answers = [];
                                        foreach ($wizard_questions as $q) {
                                            $qid = (int)$q['id'];
                                            $ans = is_array($_SESSION['app_answers'] ?? null)
                                                ? trim((string)($_SESSION['app_answers'][$qid] ?? ''))
                                                : '';
                                            $rev_answers[] = [
                                                'question' => (string)$q['question_text'],
                                                'required' => !empty($q['is_required']),
                                                'answer'   => $ans,
                                            ];
                                        }
                                        $rev_answered = count(array_filter($rev_answers, fn($r) => $r['answer'] !== ''));
                                    ?>
                                        <div class="wiz-review-block">
                                            <div class="flex items-center justify-between gap-2 mb-2">
                                                <h4 style="margin:0;">Your Answers</h4>
                                                <span class="text-[11px] font-bold rounded-full px-2.5 py-1"
                                                    style="background:rgba(16,185,129,.12);color:var(--green);">
                                                    <?= $rev_answered ?> of <?= count($rev_answers) ?> answered
                                                </span>
                                            </div>

                                            <!-- Desktop / tablet: table -->
                                            <div class="hidden md:block rounded-xl overflow-hidden" style="border:1px solid var(--border);background:var(--card);">
                                                <div class="overflow-x-auto">
                                                    <table class="w-full border-collapse text-left">
                                                        <thead>
                                                            <tr class="text-[10px] font-bold uppercase tracking-wide" style="background:var(--surface);color:var(--text3);">
                                                                <th class="px-4 py-3 w-12">#</th>
                                                                <th class="px-4 py-3 w-[45%]">Question</th>
                                                                <th class="px-4 py-3">Your Answer</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($rev_answers as $i => $r): ?>
                                                                <tr style="border-top:1px solid var(--border);">
                                                                    <td class="px-4 py-3 align-top text-[12px] font-bold" style="color:var(--text3);"><?= $i + 1 ?></td>
                                                                    <td class="px-4 py-3 align-top">
                                                                        <div class="text-[13px] font-bold break-words" style="color:var(--text1);">
                                                                            <?= htmlspecialchars($r['question']) ?>
                                                                            <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                                        </div>
                                                                    </td>
                                                                    <td class="px-4 py-3 align-top">
                                                                        <?php if ($r['answer'] !== ''): ?>
                                                                            <div class="text-[13px] font-medium break-words whitespace-pre-wrap" style="color:var(--text2);"><?= htmlspecialchars($r['answer']) ?></div>
                                                                        <?php else: ?>
                                                                            <span class="text-[12.5px] italic" style="color:var(--text3);">No answer provided</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- Mobile: stacked cards -->
                                            <div class="md:hidden flex flex-col gap-2.5">
                                                <?php foreach ($rev_answers as $i => $r): ?>
                                                    <div class="rounded-xl p-3.5" style="border:1px solid var(--border);background:var(--card);">
                                                        <div class="text-[10px] font-bold uppercase tracking-wide" style="color:var(--text3);">Question <?= $i + 1 ?></div>
                                                        <div class="text-[13px] font-bold mt-0.5 break-words" style="color:var(--text1);">
                                                            <?= htmlspecialchars($r['question']) ?>
                                                            <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                        </div>
                                                        <div class="mt-2 pt-2 text-[12.5px] break-words whitespace-pre-wrap" style="border-top:1px solid var(--border);color:var(--text2);">
                                                            <?php if ($r['answer'] !== ''): ?>
                                                                <?= htmlspecialchars($r['answer']) ?>
                                                            <?php else: ?>
                                                                <span class="italic" style="color:var(--text3);">No answer provided</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <!-- Documents summary -->
                                    <?php
                                    // Build the rows once, render them in both the table and the mobile cards.
                                    $rev_rows = [];
                                    foreach ($doc_types_rev as $doc) {
                                        $docType = (string)($doc['document_type'] ?? '');
                                        $reqId   = (int)($doc['id'] ?? 0);
                                        $uKey    = $reqId > 0 ? 'doc_' . $reqId : 'type_' . strtolower($docType);
                                        $revName = (string)($wiz_uploads_rev[$uKey]['file_name'] ?? '');
                                        $revPath = (string)($wiz_uploads_rev[$uKey]['file_path'] ?? '');
                                        $revExt  = strtolower(pathinfo($revPath !== '' ? $revPath : $revName, PATHINFO_EXTENSION));
                                        $rev_rows[] = [
                                            'name'     => (string)($doc['document_name'] ?? $docType),
                                            'required' => !empty($doc['is_required']),
                                            'file'     => $revName,
                                            'path'     => $revPath,
                                            'ext'      => $revExt,
                                            'icon'     => $revExt === 'pdf' ? 'fa-file-pdf' : (in_array($revExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'fa-file-image' : 'fa-file-lines'),
                                        ];
                                    }
                                    $rev_uploaded = count(array_filter($rev_rows, fn($r) => $r['file'] !== ''));
                                    ?>
                                    <div class="wiz-review-block">
                                        <div class="flex items-center justify-between gap-2 mb-2">
                                            <h4 style="margin:0;">Uploaded Documents</h4>
                                            <span class="text-[11px] font-bold rounded-full px-2.5 py-1"
                                                style="background:rgba(16,185,129,.12);color:var(--green);">
                                                <?= $rev_uploaded ?> of <?= count($rev_rows) ?> uploaded
                                            </span>
                                        </div>

                                        <?php if ($rev_rows === []): ?>
                                            <p class="wiz-muted">No documents required for this position.</p>
                                        <?php else: ?>

                                            <!-- Desktop / tablet: table -->
                                            <div class="hidden md:block rounded-xl overflow-hidden" style="border:1px solid var(--border);background:var(--card);">
                                                <div class="overflow-x-auto">
                                                    <table class="w-full border-collapse text-left">
                                                        <thead>
                                                            <tr class="text-[10px] font-bold uppercase tracking-wide" style="background:var(--surface);color:var(--text3);">
                                                                <th class="px-4 py-3 w-12">#</th>
                                                                <th class="px-4 py-3">Document</th>
                                                                <th class="px-4 py-3">File Name</th>
                                                                <th class="px-4 py-3">Status</th>
                                                                <th class="px-4 py-3 text-center w-32">Preview</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($rev_rows as $i => $r): $has = $r['file'] !== ''; ?>
                                                                <tr style="border-top:1px solid var(--border);">
                                                                    <td class="px-4 py-3 text-[12px] font-bold" style="color:var(--text3);"><?= $i + 1 ?></td>
                                                                    <td class="px-4 py-3">
                                                                        <div class="text-[13px] font-bold" style="color:var(--text1);">
                                                                            <?= htmlspecialchars($r['name']) ?>
                                                                            <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                                        </div>
                                                                    </td>
                                                                    <td class="px-4 py-3">
                                                                        <?php if ($has): ?>
                                                                            <div class="flex items-center gap-2 min-w-0">
                                                                                <i class="fas <?= $r['icon'] ?>" style="color:var(--blue-lt);"></i>
                                                                                <span class="text-[12.5px] font-medium break-all" style="color:var(--text2);"><?= htmlspecialchars($r['file']) ?></span>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <span class="text-[12.5px] italic" style="color:var(--text3);">—</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="px-4 py-3">
                                                                        <?php if ($has): ?>
                                                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1 whitespace-nowrap"
                                                                                style="background:rgba(16,185,129,.12);color:var(--green);">
                                                                                <i class="fas fa-circle-check"></i> Uploaded
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1 whitespace-nowrap"
                                                                                style="background:rgba(239,68,68,.12);color:var(--red);">
                                                                                <i class="fas fa-circle-xmark"></i> Missing
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="px-4 py-3 text-center">
                                                                        <?php if ($r['path'] !== ''): ?>
                                                                            <button type="button"
                                                                                class="wiz-btn secondary wiz-preview-btn" style="padding:6px 12px;font-size:11.5px;"
                                                                                data-preview-src="../<?= htmlspecialchars($r['path']) ?>"
                                                                                data-preview-name="<?= htmlspecialchars($r['file']) ?>"
                                                                                data-preview-ext="<?= htmlspecialchars($r['ext']) ?>">
                                                                                <i class="fas fa-eye"></i> Preview
                                                                            </button>
                                                                        <?php else: ?>
                                                                            <span class="text-[12px]" style="color:var(--text3);">—</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- Mobile: stacked cards -->
                                            <div class="md:hidden flex flex-col gap-2.5">
                                                <?php foreach ($rev_rows as $i => $r): $has = $r['file'] !== ''; ?>
                                                    <div class="rounded-xl p-3.5" style="border:1px solid var(--border);background:var(--card);">
                                                        <div class="flex items-start justify-between gap-2">
                                                            <div class="min-w-0">
                                                                <div class="text-[10px] font-bold uppercase tracking-wide" style="color:var(--text3);">Document <?= $i + 1 ?></div>
                                                                <div class="text-[13px] font-bold" style="color:var(--text1);">
                                                                    <?= htmlspecialchars($r['name']) ?>
                                                                    <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php if ($has): ?>
                                                                <span class="inline-flex flex-shrink-0 items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1"
                                                                    style="background:rgba(16,185,129,.12);color:var(--green);"><i class="fas fa-circle-check"></i> Uploaded</span>
                                                            <?php else: ?>
                                                                <span class="inline-flex flex-shrink-0 items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1"
                                                                    style="background:rgba(239,68,68,.12);color:var(--red);"><i class="fas fa-circle-xmark"></i> Missing</span>
                                                            <?php endif; ?>
                                                        </div>

                                                        <div class="mt-2 flex items-center gap-2 min-w-0 text-[12.5px]" style="color:var(--text2);">
                                                            <?php if ($has): ?>
                                                                <i class="fas <?= $r['icon'] ?>" style="color:var(--blue-lt);"></i>
                                                                <span class="break-all"><?= htmlspecialchars($r['file']) ?></span>
                                                            <?php else: ?>
                                                                <span class="italic" style="color:var(--text3);">No file uploaded</span>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php if ($r['path'] !== ''): ?>
                                                            <button type="button"
                                                                class="wiz-btn secondary wiz-preview-btn w-full mt-3" style="padding:8px 12px;font-size:12px;"
                                                                data-preview-src="../<?= htmlspecialchars($r['path']) ?>"
                                                                data-preview-name="<?= htmlspecialchars($r['file']) ?>"
                                                                data-preview-ext="<?= htmlspecialchars($r['ext']) ?>">
                                                                <i class="fas fa-eye"></i> Preview
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                        <?php endif; ?>
                                    </div>
                                    <p id="wizard-step4-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <button type="button" class="wiz-btn primary" id="wizard-step4-submit" data-job-id="<?= (int)$wizard_job_id ?>"><i class="fas fa-paper-plane"></i> Submit Application</button>
                                    </div>
                                </div>
                            </div>
                            <div id="wizard-step4-success" class="wiz-success hidden">
                                <i class="fas fa-circle-check" style="font-size:32px;color:var(--green);margin-bottom:10px;"></i>
                                <h3>Application Submitted!</h3>
                                <p id="wizard-step4-success-msg">Your application has been submitted for review.</p>
                                <button class="wiz-btn primary" id="wizard-step4-reload">View My Applications</button>
                            </div>
                        <?php else: ?><p class="wiz-muted" style="padding-top:4px;">Complete the previous steps first.</p>
                        <?php endif; ?>
                    </div>

                </div><!-- /tab-body -->
            </div><!-- /two-part-wrapper -->
        <?php endif; ?>

        <!-- SECTION B: SUBMITTED APPLICATIONS  (tab-based, for-each) -->

        <?php if (!empty($applications)): ?>

            <?php if (!$show_wizard): ?>
                <p class="sec-label fade-in d2" style="margin-top:1.3rem"><i class="fas fa-folder-open"></i> &nbsp;My Applications</p>
            <?php endif; ?>

            <?php if ($total > 1): ?>
                <!-- ── MULTI-APPLICATION: responsive picker (Tailwind) ──
                     Desktop/tablet: table with an Action column.
                     Mobile: stacked cards.
                     Both use the exact same openAppDetail(idx) call the
                     old picker buttons used — no navigation change. ── -->
                <div id="app-picker-section" class="fade-in d2">

                    <!-- Search + status filter — filters the picker list
                         instantly, client-side, no page reload. All
                         application data for this client is already
                         rendered in the DOM (there is no large shared
                         table to page through like the evaluator queue),
                         so this filters the existing rows/cards directly
                         instead of round-tripping to the server. -->
                    <div class="bg-[var(--card)] border border-[var(--border)] rounded-[14px] p-3.5 mb-3.5 flex flex-col gap-2.5 sm:flex-row sm:items-center">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-xs pointer-events-none"></i>
                            <input
                                type="text"
                                id="myapp-search-input"
                                placeholder="Search by position, employer, RSF #, or country…"
                                autocomplete="off"
                                class="w-full py-[9px] pr-9 pl-[34px] border border-[var(--border)] rounded-[10px] text-[13px] font-medium text-[var(--text1)] bg-[var(--surface)] outline-none transition-colors focus:border-[var(--blue-lt)] focus:bg-[var(--card)] placeholder:text-[var(--text3)]">
                            <button type="button" id="myapp-search-clear"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-xs hidden hover:text-[var(--text1)]"
                                title="Clear search">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                        <select id="myapp-status-select"
                            class="py-[9px] px-3 border border-[var(--border)] rounded-[10px] text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] outline-none cursor-pointer transition-colors focus:border-[var(--blue-lt)] sm:w-56">
                            <option value="">All Statuses</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending Evaluator Review</option>
                            <option value="under_evaluation">Under Evaluation</option>
                            <option value="for_compliance">For Compliance</option>
                            <option value="resubmitted">Resubmitted</option>
                            <option value="approved">Approved</option>
                            <option value="disapproved">Disapproved</option>
                        </select>
                    </div>

                    <?php if (!$show_wizard && $forComplianceCount > 0): ?>
                        <div class="action-needed-banner fade-in d1">
                            <div class="action-needed-icon"><i class="fas fa-triangle-exclamation"></i></div>
                            <div class="action-needed-body">
                                <div class="action-needed-title">
                                    <?= $forComplianceCount ?> application<?= $forComplianceCount !== 1 ? 's' : '' ?> need<?= $forComplianceCount === 1 ? 's' : '' ?> your action
                                </div>
                                <div class="action-needed-text">
                                    An evaluator has requested changes. Review their comments and resubmit so your application can continue processing.
                                </div>
                                <?php if ($forComplianceCount === 1): ?>
                                    <a href="edit-application.php?id=<?= (int)$forComplianceApps[0]['id'] ?>&tab=requirements" class="action-needed-btn">
                                        <i class="fas fa-pen"></i> Review &amp; Resubmit
                                    </a>
                                <?php else: ?>
                                    <div class="action-needed-list">
                                        <?php foreach ($forComplianceApps as $fa): ?>
                                            <a href="edit-application.php?id=<?= (int)$fa['id'] ?>&tab=requirements" class="action-needed-chip">
                                                <?= htmlspecialchars($fa['position_title']) ?> <i class="fas fa-arrow-right"></i>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- Empty state — shown when the search/status filter matches nothing -->
                    <div id="myapp-picker-empty" class="rounded-2xl py-12 px-6 text-center" style="display:none;border:1.5px solid var(--border);background:var(--card);">
                        <i class="fas fa-folder-open text-3xl block mb-3" style="color:var(--border);"></i>
                        <p class="text-sm font-bold" style="color:var(--text2);">No applications match your search</p>
                        <span class="text-xs" id="myapp-picker-empty-text" style="color:var(--text3);">Try a different keyword or status.</span>
                    </div>

                    <!-- Desktop / tablet: table -->
                    <div id="myapp-picker-table-wrap" class="hidden md:block rounded-2xl overflow-hidden" style="border:1.5px solid var(--border);background:var(--card);">
                        <div class="overflow-x-auto no-scrollbar">
                            <table class="w-full min-w-[900px] border-collapse text-sm">
                                <thead>
                                    <tr class="text-[10px] font-bold uppercase tracking-wide" style="background:var(--surface);color:var(--text3);">
                                        <th class="text-left px-5 py-3">Position &amp; Employer</th>
                                        <th class="text-left px-5 py-3">RSF / Country</th>
                                        <th class="text-left px-5 py-3">Status</th>
                                        <th class="text-left px-5 py-3">Updated</th>
                                        <th class="px-5 py-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="myapp-picker-tbody">
                                    <?php foreach ($applications as $idx => $app):
                                        $si = $app['status_info']; ?>
                                        <tr class="transition myapp-picker-row" style="border-top:1px solid var(--border);" id="picker-row-<?= $idx ?>"
                                            data-position="<?= htmlspecialchars(strtolower($app['position_title'] ?? '')) ?>"
                                            data-employer="<?= htmlspecialchars(strtolower($app['employer_name'] ?? '')) ?>"
                                            data-rsf="<?= htmlspecialchars(strtolower($app['rsf_number'] ?? '')) ?>"
                                            data-country="<?= htmlspecialchars(strtolower($app['worksite_country'] ?? '')) ?>"
                                            data-status="<?= htmlspecialchars(strtolower($app['status'] ?? '')) ?>"
                                            onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-[12px]" style="background:<?= $si['color'] ?>1a;color:<?= $si['color'] ?>;">
                                                        <i class="fas <?= $si['icon'] ?>"></i>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="truncate text-[13px] font-bold" style="color:var(--text1);"><?= htmlspecialchars($app['position_title']) ?></div>
                                                        <div class="truncate text-[11px] font-medium" style="color:var(--text3);"><?= htmlspecialchars($app['employer_name']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="text-[12.5px] font-bold" style="color:var(--blue-lt);"><?= htmlspecialchars($app['rsf_number']) ?></div>
                                                <div class="text-[11px]" style="color:var(--text3);"><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></div>
                                            </td>
                                            <td class="px-5 py-3">
                                                <span class="status-pill <?= $si['pill'] ?>"><?= htmlspecialchars($si['label']) ?></span>
                                                <?php if ($app['status'] === 'for_compliance'): ?>
                                                    <div class="action-req-tag"><i class="fas fa-bolt"></i> Action needed</div>
                                                <?php elseif ($app['status'] === 'draft'): ?>
                                                    <div class="action-req-tag" style="color:var(--amber);"><i class="fas fa-pen"></i> Continue application</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-3 text-[11.5px]" style="color:var(--text3);">
                                                <?= htmlspecialchars(myapp_relative_time($app['updated_at'])) ?>
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <button type="button" onclick="openAppDetail(<?= $idx ?>)" id="picker-item-<?= $idx ?>"
                                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] font-bold text-white transition hover:opacity-90"
                                                    style="background:var(--blue-lt);">
                                                    View <i class="fas fa-chevron-right text-[9px]"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Mobile: stacked cards -->
                    <div id="myapp-picker-cards" class="md:hidden flex flex-col gap-2.5">
                        <?php foreach ($applications as $idx => $app):
                            $si = $app['status_info']; ?>
                            <div class="rounded-2xl p-3.5 myapp-picker-card" style="border:1.5px solid var(--border);background:var(--card);" id="picker-card-<?= $idx ?>"
                                data-position="<?= htmlspecialchars(strtolower($app['position_title'] ?? '')) ?>"
                                data-employer="<?= htmlspecialchars(strtolower($app['employer_name'] ?? '')) ?>"
                                data-rsf="<?= htmlspecialchars(strtolower($app['rsf_number'] ?? '')) ?>"
                                data-country="<?= htmlspecialchars(strtolower($app['worksite_country'] ?? '')) ?>"
                                data-status="<?= htmlspecialchars(strtolower($app['status'] ?? '')) ?>">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-[13px]" style="background:<?= $si['color'] ?>1a;color:<?= $si['color'] ?>;">
                                        <i class="fas <?= $si['icon'] ?>"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="truncate text-[13.5px] font-bold" style="color:var(--text1);"><?= htmlspecialchars($app['position_title']) ?></div>
                                                <div class="truncate text-[11px] font-medium" style="color:var(--text3);"><?= htmlspecialchars($app['employer_name']) ?></div>
                                            </div>
                                            <div class="flex flex-col items-end gap-1 flex-shrink-0">
                                                <span class="status-pill <?= $si['pill'] ?>"><?= htmlspecialchars($si['label']) ?></span>
                                                <?php if ($app['status'] === 'for_compliance'): ?>
                                                    <div class="action-req-tag"><i class="fas fa-bolt"></i> Action needed</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10.5px] font-medium" style="color:var(--text3);">
                                            <span class="font-bold" style="color:var(--blue-lt);"><i class="fas fa-hashtag text-[9px] mr-1"></i><?= htmlspecialchars($app['rsf_number']) ?></span>
                                            <span><i class="fas fa-earth-asia text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></span>
                                            <span><i class="fas fa-clock text-[9px] mr-1 opacity-70"></i>Updated <?= htmlspecialchars(myapp_relative_time($app['updated_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" onclick="openAppDetail(<?= $idx ?>)"
                                    class="mt-3 w-full inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[12px] font-bold text-white transition"
                                    style="background:var(--blue-lt);">
                                    View <i class="fas fa-chevron-right text-[10px]"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            <?php endif; ?>

            <!--  APPLICATION DETAIL PANELS (one per application)  -->
            <?php foreach ($applications as $idx => $app):
                $aid       = (int)$app['id'];
                $si        = $app['status_info'];
                $can_edit  = canEditApplication($app['status']);
                $is_unassigned = !$app['is_assigned'];
                $show_edit_btn = $is_unassigned && $can_edit;
                $eval_note = trim((string)($app['notes'] ?? '')) !== '' ? trim($app['notes']) : $app['eval_comments'];
                $show_note = in_array($app['status'], ['for_compliance', 'disapproved'], true) && $eval_note !== '';
                $history   = $app['status_history'];
            ?>
                <div class="app-detail-panel <?= ($single_mode && $idx === 0) ? 'active' : '' ?>" id="app-detail-<?= $idx ?>">

                    <?php if ($app['status'] === 'draft'): ?>

                        <?php if ($total > 1): ?>
                            <div class="detail-header">
                                <button type="button" onclick="closeAppDetail(<?= $idx ?>)"
                                    class="inline-flex items-center gap-1.5 shrink-0 cursor-pointer
                                    text-xs font-bold px-3 py-1.5 rounded-lg border
                                    bg-blue-50 border-blue-200 text-[var(--blue-lt)]
                                    hover:bg-blue-100
                                    dark:bg-white/5 dark:border-white/15 dark:text-blue-300
                                    dark:hover:bg-blue-500/15 dark:hover:border-blue-400/40
                                    transition-colors">
                                    <i class="fas fa-arrow-left"></i> All Applications
                                </button>
                                <div style="min-width:0;">
                                    <div class="detail-job-name"><?= htmlspecialchars($app['position_title']) ?></div>
                                    <div class="detail-job-meta"><?= htmlspecialchars($app['rsf_number']) ?> &middot; <?= htmlspecialchars($app['employer_name']) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="wiz-card">
                            <div class="wiz-card-head">
                                <span class="wiz-step-pill" style="color:var(--amber);">Draft &middot; Not Yet Submitted</span>
                                <h3 class="wiz-title"><?= htmlspecialchars($app['position_title']) ?></h3>
                                <p class="wiz-sub"><?= htmlspecialchars($app['employer_name']) ?> &middot; <?= htmlspecialchars($app['worksite_country'] ?? '—') ?> &middot; <?= htmlspecialchars($app['rsf_number']) ?></p>
                            </div>
                            <div class="wiz-body">
                                <p style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:16px;">
                                    You started this application but haven't submitted it yet. Your answers and uploaded documents have already been saved — pick up right where you left off.
                                </p>
                                <a href="my-applications.php?job_id=<?= (int) $app['rsf_id'] ?>" class="wiz-btn primary" style="display:inline-flex;">
                                    <i class="fas fa-rotate-right"></i> Finish Your Application
                                </a>
                            </div>
                        </div>

                    <?php else: ?>

                        <?php if ($total > 1): ?>
                            <!-- Breadcrumb / back button for multi-app -->
                            <div class="detail-header">
                                <button type="button" onclick="closeAppDetail(<?= $idx ?>)"
                                    class="inline-flex items-center gap-1.5 shrink-0 cursor-pointer
                                    text-xs font-bold px-3 py-1.5 rounded-lg border
                                    bg-blue-50 border-blue-200 text-[var(--blue-lt)]
                                    hover:bg-blue-100
                                    dark:bg-white/5 dark:border-white/15 dark:text-blue-300
                                    dark:hover:bg-blue-500/15 dark:hover:border-blue-400/40
                                    transition-colors">
                                    <i class="fas fa-arrow-left"></i> All Applications
                                </button>
                                <div style="min-width:0;">
                                    <div class="detail-job-name"><?= htmlspecialchars($app['position_title']) ?></div>
                                    <div class="detail-job-meta"><?= htmlspecialchars($app['rsf_number']) ?> &middot; <?= htmlspecialchars($app['employer_name']) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Tab panel wrapper -->
                        <div class="two-part-wrapper" style="margin-bottom:16px;">

                            <!-- Part labels -->
                            <div class="part-label-bar" id="part-label-<?= $idx ?>">
                                <div class="part-segment" id="seg-part1-<?= $idx ?>">
                                    <!--<div class="part-segment-num">Part 1</div>-->
                                    <div class="part-segment-name"><i class="fas fa-pen-to-square" style="font-size:10px;margin-right:4px"></i>Application Phase</div>
                                </div>
                                <div class="part-segment part-active" id="seg-part2-<?= $idx ?>">
                                    <!--<div class="part-segment-num">Part 2</div>-->
                                    <div class="part-segment-name"><i class="fas fa-chart-line" style="font-size:10px;margin-right:4px"></i>Status Tracking</div>
                                </div>
                            </div>

                            <!-- Tab row -->
                            <div class="app-tabs">
                                <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-rsf-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                    <i class="fas fa-file-lines"></i> RSF
                                </button>
                                <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-q-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                    <i class="fas fa-edit"></i> Questions
                                </button>
                                <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-req-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                    <i class="fas fa-file-arrow-up"></i> Requirements
                                </button>
                                <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-rev-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                    <i class="fas fa-clipboard-list"></i> Summary
                                </button>
                                <div class="atab-divider"></div>
                                <button class="atab active current-stage" onclick="switchAppTab(<?= $idx ?>,'atcontent-status-<?= $idx ?>',this,'seg-part2-<?= $idx ?>')">
                                    <i class="fas fa-chart-line"></i> Status
                                </button>
                            </div>

                            <!-- ── TAB CONTENTS ── -->
                            <div class="tab-body">

                                <!-- TAB: RSF -->
                                <div id="atcontent-rsf-<?= $idx ?>" class="atab-content">
                                    <?php if ($show_edit_btn): ?>
                                        <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                            <i class="fas fa-pen"></i> Edit Application
                                        </a>
                                    <?php endif; ?>
                                    <dl class="info-grid">
                                        <dt class="info-dt">Position</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['position_title']) ?></dd>
                                        <dt class="info-dt">RSF Number</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['rsf_number']) ?></dd>
                                        <dt class="info-dt">Employer</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['employer_name']) ?></dd>
                                        <dt class="info-dt">Country</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></dd>
                                        <dt class="info-dt">Classification</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['classification'] ?? '—') ?></dd>
                                        <dt class="info-dt">Slots Available</dt>
                                        <dd class="info-dd"><?= (int)($app['slots_available'] ?? 0) ?></dd>
                                        <dt class="info-dt">Deadline</dt>
                                        <dd class="info-dd"><?= !empty($app['deadline']) ? date('F d, Y', strtotime($app['deadline'])) : 'Open' ?></dd>
                                        <dt class="info-dt">Applied On</dt>
                                        <dd class="info-dd"><?= date('F d, Y', strtotime($app['submitted_at'])) ?></dd>
                                    </dl>
                                    <?php if (!empty($app['qualifications'])): ?>
                                        <p style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);margin-bottom:6px;">Qualifications &amp; Requirements</p>
                                        <div class="qual-box"><?= htmlspecialchars($app['qualifications']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['pdf_path'])): ?>
                                        <p style="margin-top:12px;margin-bottom:4px;"><a href="../<?= htmlspecialchars($app['pdf_path']) ?>" target="_blank" style="font-size:12.5px;font-weight:700;color:var(--blue-lt);"><i class="fas fa-file-pdf"></i> Open RSF PDF</a></p>
                                        <div class="pdf-embed"><iframe src="../<?= htmlspecialchars($app['pdf_path']) ?>" title="RSF PDF" loading="lazy"></iframe></div>
                                    <?php endif; ?>
                                </div>

                                <!-- TAB: Questions -->
                                <div id="atcontent-q-<?= $idx ?>" class="atab-content">
                                    <?php if ($show_edit_btn): ?>
                                        <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                            <i class="fas fa-pen"></i> Edit Application
                                        </a>
                                    <?php endif; ?>
                                    <?php if (empty($app['questions'])): ?>
                                        <p class="wiz-muted">No application questions for this position.</p>
                                        <?php else: foreach ($app['questions'] as $q):
                                            $qid = (int)$q['id'];
                                            $ans = $app['answers_map'][$qid] ?? '—'; ?>
                                            <div class="qa-row">
                                                <div class="qa-q"><?= htmlspecialchars((string)$q['question_text']) ?><?php if (!empty($q['is_required'])): ?> <span style="color:var(--red);">*</span><?php endif; ?></div>
                                                <div class="qa-a"><?= htmlspecialchars((string)$ans) ?></div>
                                            </div>
                                    <?php endforeach;
                                    endif; ?>
                                </div>

                                <!-- TAB: Requirements -->
                                <div id="atcontent-req-<?= $idx ?>" class="atab-content">
                                    <?php if ($show_edit_btn): ?>
                                        <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                            <i class="fas fa-pen"></i> Edit Application
                                        </a>
                                    <?php endif; ?>
                                    <?php if (empty($app['required_docs'])): ?>
                                        <p class="wiz-muted">No required documents listed for this position.</p>
                                        <?php else: foreach ($app['required_docs'] as $rd):
                                            $rid     = (int)$rd['id'];
                                            $dtype   = (string)$rd['document_type'];
                                            $uploaded = $rid > 0 ? ($app['uploaded_by_req'][$rid] ?? null) : ($app['uploaded_by_type'][$dtype] ?? null);
                                            $hasFile = $uploaded !== null; ?>
                                            <div class="doc-row <?= $hasFile ? 'has-file' : '' ?>">
                                                <div class="doc-icon" style="color:<?= $hasFile ? 'var(--green)' : 'var(--text3)' ?>;">
                                                    <i class="fas <?= $hasFile ? 'fa-file-circle-check' : 'fa-file-circle-xmark' ?>"></i>
                                                </div>
                                                <div class="doc-info">
                                                    <div class="doc-name"><?= htmlspecialchars($rd['document_name']) ?><?php if (!empty($rd['is_required'])): ?> <span style="color:var(--red);font-size:11px;">*</span><?php endif; ?></div>
                                                    <div class="doc-type"><?= htmlspecialchars($dtype) ?></div>
                                                    <?php if (!empty($rd['description'])): ?><div class="doc-type" style="font-style:italic;"><?= htmlspecialchars($rd['description']) ?></div><?php endif; ?>
                                                    <?php if ($hasFile): ?>
                                                        <div class="doc-file"><i class="fas fa-check"></i> <?= htmlspecialchars($uploaded['file_name']) ?></div>
                                                    <?php else: ?>
                                                        <div class="doc-missing">Not uploaded</div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($hasFile && !empty($uploaded['file_path'])):
                                                    $upName = (string)($uploaded['file_name'] ?? '');
                                                    $upPath = (string)$uploaded['file_path'];
                                                    $upExt  = strtolower(pathinfo($upPath !== '' ? $upPath : $upName, PATHINFO_EXTENSION)); ?>
                                                    <button type="button" class="wiz-btn secondary wiz-preview-btn" style="padding:7px 12px;font-size:11.5px;white-space:nowrap;"
                                                        data-preview-src="../<?= htmlspecialchars($upPath) ?>"
                                                        data-preview-name="<?= htmlspecialchars($upName) ?>"
                                                        data-preview-ext="<?= htmlspecialchars($upExt) ?>">
                                                        <i class="fas fa-eye"></i> Preview
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                    <?php endforeach;
                                    endif; ?>
                                </div>

                                <!-- TAB: Review & Submit -->
                                <div id="atcontent-rev-<?= $idx ?>" class="atab-content">
                                    <?php if ($show_edit_btn): ?>
                                        <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                            <i class="fas fa-pen"></i> Edit Application
                                        </a>
                                    <?php endif; ?>

                                    <?php
                                    // Build rows once, render in both the table and the mobile cards.
                                    $rv_answers = [];
                                    foreach ($app['questions'] as $q) {
                                        $rv_answers[] = [
                                            'question' => (string)$q['question_text'],
                                            'required' => !empty($q['is_required']),
                                            'answer'   => trim((string)($app['answers_map'][(int)$q['id']] ?? '')),
                                        ];
                                    }
                                    $rv_answered = count(array_filter($rv_answers, fn($r) => $r['answer'] !== ''));

                                    $rv_docs = [];
                                    foreach ($app['required_docs'] as $rd) {
                                        $rid   = (int)$rd['id'];
                                        $dtype = (string)$rd['document_type'];
                                        $up    = $rid > 0 ? ($app['uploaded_by_req'][$rid] ?? null) : ($app['uploaded_by_type'][$dtype] ?? null);
                                        $fname = (string)($up['file_name'] ?? '');
                                        $fpath = (string)($up['file_path'] ?? '');
                                        $fext  = strtolower(pathinfo($fpath !== '' ? $fpath : $fname, PATHINFO_EXTENSION));
                                        $rv_docs[] = [
                                            'name'     => (string)$rd['document_name'],
                                            'required' => !empty($rd['is_required']),
                                            'file'     => $fname,
                                            'path'     => $fpath,
                                            'ext'      => $fext,
                                            'icon'     => $fext === 'pdf' ? 'fa-file-pdf' : (in_array($fext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'fa-file-image' : 'fa-file-lines'),
                                        ];
                                    }
                                    $rv_uploaded = count(array_filter($rv_docs, fn($r) => $r['file'] !== ''));
                                    ?>

                                    <!-- Application Summary -->
                                    <div class="review-section">
                                        <div class="review-section-title">Application Summary</div>
                                        <dl class="info-grid" style="margin-bottom:0;">
                                            <dt class="info-dt">Position</dt>
                                            <dd class="info-dd"><?= htmlspecialchars($app['position_title']) ?></dd>
                                            <dt class="info-dt">RSF</dt>
                                            <dd class="info-dd"><?= htmlspecialchars($app['rsf_number']) ?></dd>
                                            <dt class="info-dt">Employer</dt>
                                            <dd class="info-dd"><?= htmlspecialchars($app['employer_name']) ?></dd>
                                            <dt class="info-dt">Country</dt>
                                            <dd class="info-dd"><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></dd>
                                            <dt class="info-dt">Status</dt>
                                            <dd class="info-dd"><span class="status-pill <?= $si['pill'] ?>"><?= htmlspecialchars($si['label']) ?></span></dd>
                                            <dt class="info-dt">Submitted</dt>
                                            <dd class="info-dd"><?= date('F d, Y • g:i a', strtotime($app['submitted_at'])) ?></dd>
                                            <dt class="info-dt">Last Updated</dt>
                                            <dd class="info-dd"><?= date('F d, Y • g:i a', strtotime($app['updated_at'])) ?></dd>
                                        </dl>
                                    </div>

                                    <!-- Application Questions & Answers -->
                                    <?php if ($rv_answers !== []): ?>
                                        <div class="review-section">
                                            <div class="flex items-center justify-between gap-2 mb-2">
                                                <div class="review-section-title" style="margin:0;">Application Questions &amp; Answers</div>
                                                <span class="text-[11px] font-bold rounded-full px-2.5 py-1"
                                                    style="background:rgba(16,185,129,.12);color:var(--green);">
                                                    <?= $rv_answered ?> of <?= count($rv_answers) ?> answered
                                                </span>
                                            </div>

                                            <!-- Desktop / tablet: table -->
                                            <div class="hidden md:block rounded-xl overflow-hidden" style="border:1px solid var(--border);background:var(--card);">
                                                <div class="overflow-x-auto">
                                                    <table class="w-full border-collapse text-left">
                                                        <thead>
                                                            <tr class="text-[10px] font-bold uppercase tracking-wide" style="background:var(--surface);color:var(--text3);">
                                                                <th class="px-4 py-3 w-12">#</th>
                                                                <th class="px-4 py-3 w-[45%]">Question</th>
                                                                <th class="px-4 py-3">Answer</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($rv_answers as $i => $r): ?>
                                                                <tr style="border-top:1px solid var(--border);">
                                                                    <td class="px-4 py-3 align-top text-[12px] font-bold" style="color:var(--text3);"><?= $i + 1 ?></td>
                                                                    <td class="px-4 py-3 align-top">
                                                                        <div class="text-[13px] font-bold break-words" style="color:var(--text1);">
                                                                            <?= htmlspecialchars($r['question']) ?>
                                                                            <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                                        </div>
                                                                    </td>
                                                                    <td class="px-4 py-3 align-top">
                                                                        <?php if ($r['answer'] !== ''): ?>
                                                                            <div class="text-[13px] font-medium break-words whitespace-pre-wrap" style="color:var(--text2);"><?= htmlspecialchars($r['answer']) ?></div>
                                                                        <?php else: ?>
                                                                            <span class="text-[12.5px] italic" style="color:var(--text3);">No answer provided</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- Mobile: stacked cards -->
                                            <div class="md:hidden flex flex-col gap-2.5">
                                                <?php foreach ($rv_answers as $i => $r): ?>
                                                    <div class="rounded-xl p-3.5" style="border:1px solid var(--border);background:var(--card);">
                                                        <div class="text-[10px] font-bold uppercase tracking-wide" style="color:var(--text3);">Question <?= $i + 1 ?></div>
                                                        <div class="text-[13px] font-bold mt-0.5 break-words" style="color:var(--text1);">
                                                            <?= htmlspecialchars($r['question']) ?>
                                                            <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                        </div>
                                                        <div class="mt-2 pt-2 text-[12.5px] break-words whitespace-pre-wrap" style="border-top:1px solid var(--border);color:var(--text2);">
                                                            <?php if ($r['answer'] !== ''): ?>
                                                                <?= htmlspecialchars($r['answer']) ?>
                                                            <?php else: ?>
                                                                <span class="italic" style="color:var(--text3);">No answer provided</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Uploaded Documents -->
                                    <div class="review-section" style="margin-bottom:0;">
                                        <div class="flex items-center justify-between gap-2 mb-2">
                                            <div class="review-section-title" style="margin:0;">Uploaded Documents</div>
                                            <span class="text-[11px] font-bold rounded-full px-2.5 py-1"
                                                style="background:rgba(16,185,129,.12);color:var(--green);">
                                                <?= $rv_uploaded ?> of <?= count($rv_docs) ?> uploaded
                                            </span>
                                        </div>

                                        <?php if ($rv_docs === []): ?>
                                            <p class="wiz-muted">No documents required for this position.</p>
                                        <?php else: ?>

                                            <!-- Desktop / tablet: table -->
                                            <div class="hidden md:block rounded-xl overflow-hidden" style="border:1px solid var(--border);background:var(--card);">
                                                <div class="overflow-x-auto">
                                                    <table class="w-full border-collapse text-left">
                                                        <thead>
                                                            <tr class="text-[10px] font-bold uppercase tracking-wide" style="background:var(--surface);color:var(--text3);">
                                                                <th class="px-4 py-3 w-12">#</th>
                                                                <th class="px-4 py-3">Document</th>
                                                                <th class="px-4 py-3">File Name</th>
                                                                <th class="px-4 py-3">Status</th>
                                                                <th class="px-4 py-3 text-center w-32">Preview</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($rv_docs as $i => $r): $has = $r['file'] !== ''; ?>
                                                                <tr style="border-top:1px solid var(--border);">
                                                                    <td class="px-4 py-3 text-[12px] font-bold" style="color:var(--text3);"><?= $i + 1 ?></td>
                                                                    <td class="px-4 py-3">
                                                                        <div class="text-[13px] font-bold" style="color:var(--text1);">
                                                                            <?= htmlspecialchars($r['name']) ?>
                                                                            <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                                        </div>
                                                                    </td>
                                                                    <td class="px-4 py-3">
                                                                        <?php if ($has): ?>
                                                                            <div class="flex items-center gap-2 min-w-0">
                                                                                <i class="fas <?= $r['icon'] ?>" style="color:var(--blue-lt);"></i>
                                                                                <span class="text-[12.5px] font-medium break-all" style="color:var(--text2);"><?= htmlspecialchars($r['file']) ?></span>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <span class="text-[12.5px] italic" style="color:var(--text3);">—</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="px-4 py-3">
                                                                        <?php if ($has): ?>
                                                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1 whitespace-nowrap"
                                                                                style="background:rgba(16,185,129,.12);color:var(--green);"><i class="fas fa-circle-check"></i> Uploaded</span>
                                                                        <?php else: ?>
                                                                            <span class="inline-flex items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1 whitespace-nowrap"
                                                                                style="background:rgba(239,68,68,.12);color:var(--red);"><i class="fas fa-circle-xmark"></i> Missing</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="px-4 py-3 text-center">
                                                                        <?php if ($r['path'] !== ''): ?>
                                                                            <button type="button" class="wiz-btn secondary wiz-preview-btn" style="padding:6px 12px;font-size:11.5px;"
                                                                                data-preview-src="../<?= htmlspecialchars($r['path']) ?>"
                                                                                data-preview-name="<?= htmlspecialchars($r['file']) ?>"
                                                                                data-preview-ext="<?= htmlspecialchars($r['ext']) ?>">
                                                                                <i class="fas fa-eye"></i> Preview
                                                                            </button>
                                                                        <?php else: ?>
                                                                            <span class="text-[12px]" style="color:var(--text3);">—</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- Mobile: stacked cards -->
                                            <div class="md:hidden flex flex-col gap-2.5">
                                                <?php foreach ($rv_docs as $i => $r): $has = $r['file'] !== ''; ?>
                                                    <div class="rounded-xl p-3.5" style="border:1px solid var(--border);background:var(--card);">
                                                        <div class="flex items-start justify-between gap-2">
                                                            <div class="min-w-0">
                                                                <div class="text-[10px] font-bold uppercase tracking-wide" style="color:var(--text3);">Document <?= $i + 1 ?></div>
                                                                <div class="text-[13px] font-bold" style="color:var(--text1);">
                                                                    <?= htmlspecialchars($r['name']) ?>
                                                                    <?php if ($r['required']): ?><span style="color:var(--red);">*</span><?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php if ($has): ?>
                                                                <span class="inline-flex flex-shrink-0 items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1"
                                                                    style="background:rgba(16,185,129,.12);color:var(--green);"><i class="fas fa-circle-check"></i> Uploaded</span>
                                                            <?php else: ?>
                                                                <span class="inline-flex flex-shrink-0 items-center gap-1.5 text-[11px] font-bold rounded-full px-2.5 py-1"
                                                                    style="background:rgba(239,68,68,.12);color:var(--red);"><i class="fas fa-circle-xmark"></i> Missing</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-2 flex items-center gap-2 min-w-0 text-[12.5px]" style="color:var(--text2);">
                                                            <?php if ($has): ?>
                                                                <i class="fas <?= $r['icon'] ?>" style="color:var(--blue-lt);"></i>
                                                                <span class="break-all"><?= htmlspecialchars($r['file']) ?></span>
                                                            <?php else: ?>
                                                                <span class="italic" style="color:var(--text3);">No file uploaded</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($r['path'] !== ''): ?>
                                                            <button type="button" class="wiz-btn secondary wiz-preview-btn w-full mt-3" style="padding:8px 12px;font-size:12px;"
                                                                data-preview-src="../<?= htmlspecialchars($r['path']) ?>"
                                                                data-preview-name="<?= htmlspecialchars($r['file']) ?>"
                                                                data-preview-ext="<?= htmlspecialchars($r['ext']) ?>">
                                                                <i class="fas fa-eye"></i> Preview
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- TAB: Status (Part 2) -->
                                <div id="atcontent-status-<?= $idx ?>" class="atab-content active">

                                    <!-- Current status badge -->
                                    <div class="status-current-badge" style="background:<?= $si['color'] ?>1a;color:<?= $si['color'] ?>;border:1.5px solid <?= $si['color'] ?>40;">
                                        <i class="fas <?= $si['icon'] ?>" style="font-size:18px;"></i>
                                        <div>
                                            <div style="font-size:13px;font-weight:800;"><?= htmlspecialchars($si['label']) ?></div>
                                            <div style="font-size:11px;font-weight:600;opacity:.75;">Last updated: <?= date('F d, Y • g:i a', strtotime($app['updated_at'])) ?> &middot; <?= htmlspecialchars(myapp_relative_time($app['updated_at'])) ?></div>
                                        </div>
                                    </div>

                                    <?php if ($app['status'] === 'approved'): ?>
                                        <a href="certificate.php?id=<?= $aid ?>"
                                            class="wiz-btn primary"
                                            style="display:inline-flex;margin-bottom:14px;"
                                            onclick="downloadCertificate(event, this)">
                                            <i class="fas fa-download"></i> Download Certificate
                                        </a>
                                    <?php endif; ?>
                                    <?php
                                    // Build a visual progress indicator using known statuses
                                    $cur = $app['status'];
                                    $historyToStatuses = array_column($history, 'to_status');
                                    $everForCompliance = $cur === 'for_compliance' || $cur === 'resubmitted' || in_array('for_compliance', $historyToStatuses, true);
                                    $everResubmitted   = $cur === 'resubmitted' || in_array('resubmitted', $historyToStatuses, true);

                                    $statusOrder  = ['pending', 'unassign', 'assign', 'under_evaluation'];
                                    $statusLabels = ['Submitted', 'Pending Review', 'Assigned', 'Under Evaluation'];
                                    $statusIcons  = ['fa-file-lines', 'fa-inbox', 'fa-user-check', 'fa-magnifying-glass'];
                                    if ($everForCompliance) {
                                        $statusOrder[]  = 'for_compliance';
                                        $statusLabels[] = 'For Compliance';
                                        $statusIcons[]  = 'fa-triangle-exclamation';
                                    }
                                    if ($everResubmitted) {
                                        $statusOrder[]  = 'resubmitted';
                                        $statusLabels[] = 'Resubmitted';
                                        $statusIcons[]  = 'fa-rotate-right';
                                    }
                                    $statusOrder[]  = 'approved';
                                    $statusLabels[] = 'Approved';
                                    $statusIcons[]  = 'fa-circle-check';
                                    $curIdx = array_search($cur, $statusOrder);
                                    // If disapproved, show a special final state
                                    if ($cur === 'disapproved'):
                                    ?>
                                        <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);border-radius:12px;padding:14px 16px;margin-bottom:18px;">
                                            <div style="font-size:13px;font-weight:800;color:var(--red);margin-bottom:4px;"><i class="fas fa-circle-xmark"></i> Application Disapproved</div>
                                            <div style="font-size:12.5px;color:var(--text1);">Your application has been reviewed and disapproved. Please check the evaluator notes for details.</div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Progress steps -->
                                        <div class="progress-steps" style="margin-bottom:20px;">
                                            <?php foreach ($statusOrder as $si_key => $sval):
                                                $slabel = $statusLabels[$si_key];
                                                $sicon  = $statusIcons[$si_key];
                                                $isDone = $curIdx !== false && $si_key < $curIdx;
                                                $isCur  = $sval === $cur;
                                                $dotClass = $isDone ? 'done' : ($isCur ? 'current' : '');
                                                if ($isCur && in_array($cur, ['for_compliance'], true)) $dotClass = 'warn';
                                                if ($si_key > 0): ?><div class="ps-connector <?= $isDone ? 'done' : '' ?>"></div><?php endif; ?>
                                                <div class="ps-step">
                                                    <div class="ps-circle <?= $dotClass ?>">
                                                        <i class="fas <?= $sicon ?>" style="font-size:10px;"></i>
                                                    </div>
                                                    <div class="ps-label" style="<?= $isCur ? 'color:var(--blue-lt);font-weight:800;' : '' ?>"><?= htmlspecialchars($slabel) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($app['status'] === 'for_compliance'): ?>
                                        <div class="action-required-card">
                                            <div class="action-required-head">
                                                <i class="fas fa-triangle-exclamation"></i> Action Required
                                            </div>
                                            <p class="action-required-desc">An evaluator has requested changes to your application. Review the notes below, update your application, then resubmit — it will only be sent back for evaluation once you've resubmitted.</p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Evaluator notes (compliance/disapproved) -->
                                    <?php if ($show_note): ?>
                                        <div class="eval-note-box">
                                            <div class="eval-note-label"><i class="fas fa-comment-dots"></i> Evaluator Notes</div>
                                            <div class="eval-note-text"><?= nl2br(htmlspecialchars($eval_note)) ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Resubmit button -->
                                    <?php if ($app['status'] === 'for_compliance' && $can_edit): ?>
                                        <a href="edit-application.php?id=<?= $aid ?>&tab=requirements" class="wiz-btn primary" style="display:inline-flex;margin-bottom:14px;">
                                            <i class="fas fa-rotate-right"></i> Update &amp; Resubmit
                                        </a>
                                    <?php endif; ?>

                                    <!-- Timeline -->
                                    <?php $timeline_events = $app['timeline_events'] ?? []; ?>
                                    <?php if (!empty($timeline_events)): ?>
                                        <p style="font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--text3);margin-bottom:10px;">Status History</p>
                                        <div class="timeline">
                                            <?php foreach ($timeline_events as $ev): ?>

                                                <?php if ($ev['type'] === 'status'):
                                                    $sh = $ev['data'];
                                                    $toSt = strtolower((string)($sh['to_status'] ?? ''));
                                                    $dotC = match (true) {
                                                        $toSt === 'approved'       => 'done',
                                                        $toSt === 'disapproved'    => 'bad',
                                                        $toSt === 'for_compliance' => 'warn',
                                                        $toSt === 'resubmitted'    => 'current',
                                                        in_array($toSt, ['under_evaluation', 'assign', 'assigned'], true) => 'current',
                                                        $toSt === 'pending'        => '',
                                                        default => ''
                                                    }; ?>
                                                    <div class="timeline-item">
                                                        <div class="timeline-dot <?= $dotC ?>"></div>
                                                        <div class="timeline-to"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['to_status'] ?? '—'))) ?></div>
                                                        <?php if (!empty($sh['reason'])): ?><div class="timeline-reason"><?= htmlspecialchars($sh['reason']) ?></div><?php endif; ?>
                                                        <div class="timeline-date"><?= date('F d, Y • g:i a', strtotime($sh['changed_at'])) ?> &middot; <?= htmlspecialchars(myapp_relative_time($sh['changed_at'])) ?></div>
                                                    </div>

                                                <?php else: /* comment event */
                                                    $dc = $ev['data'];
                                                    $role_label = match ($dc['commenter_type'] ?? '') {
                                                        'evaluator' => 'Evaluator',
                                                        'admin'     => 'Admin',
                                                        default     => 'Reviewer',
                                                    };
                                                    $doc_label = ucwords(str_replace('_', ' ', $dc['document_type'] ?? 'Document')); ?>
                                                    <div class="timeline-item">
                                                        <div class="timeline-dot" style="background:var(--amber);box-shadow:0 0 0 2px rgba(245,158,11,.35);"></div>
                                                        <div class="timeline-to"><i class="fas fa-comment-dots" style="color:var(--amber);margin-right:5px;"></i>Comment on <?= htmlspecialchars($doc_label) ?></div>
                                                        <div class="timeline-reason">"<?= htmlspecialchars($dc['comment']) ?>" &mdash; <?= htmlspecialchars($role_label) ?></div>
                                                        <div class="timeline-date"><?= date('F d, Y • g:i a', strtotime($dc['created_at'])) ?> &middot; <?= htmlspecialchars(myapp_relative_time($dc['created_at'])) ?></div>
                                                    </div>
                                                <?php endif; ?>

                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="wiz-muted">No status changes recorded yet.</p>
                                    <?php endif; ?>

                                </div><!-- status tab -->

                            </div><!-- tab-body -->
                        </div><!-- two-part-wrapper -->

                    <?php endif; ?>

                </div><!-- app-detail-panel -->
            <?php endforeach; ?>

        <?php elseif (!$show_wizard): ?>
            <!-- ── EMPTY STATE ── -->
            <div class="empty-state fade-in d2" style="margin-top:1rem;">
                <i class="fas fa-inbox"></i>
                <p>No applications yet</p>
                <span>Browse job opportunities and apply for a position to get started.</span>
                <a href="jobs.php" class="browse-btn"><i class="fas fa-briefcase"></i> Browse Jobs</a>
            </div>
        <?php endif; ?>

    </div><!-- /main -->

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="index.php" class="dk"><i class="fas fa-house"></i><span class="dk-lbl">Home</span></a>

            <a href="jobs.php" class="dk"><i class="fas fa-briefcase"></i><span class="dk-lbl">Jobs</span></a>
            <div class="dk-div"></div>
            <a href="my-applications.php" class="dk active">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?><span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span><?php endif; ?>
            </a>
            <!-- <a href="notifications.php" class="dk"><i class="fas fa-bell"></i><span class="dk-lbl">Alerts</span></a>-->
            <div class="dk-div"></div>
            <a href="profile.php" class="dk"><i class="fas fa-user-gear"></i><span class="dk-lbl">Profile</span></a>
            <div class="dk" onclick="logout('Applicant')"><i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i><span class="dk-lbl" style="color:#f87171">Logout</span></div>
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
        setInterval(updateLiveDateTime, 1000); // refresh every second

        /*  TAB SWITCHING  */

        /** Switch wizard tabs (new application flow) */
        function switchWizTab(tabId, btn) {
            if (btn && btn.disabled) return;
            document.querySelectorAll('#applicationWizardGuide .atab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('#applicationWizardGuide .atab').forEach(t => t.classList.remove('active'));
            const el = document.getElementById(tabId);
            if (el) el.classList.add('active');
            if (btn) btn.classList.add('active');
        }

        /** Switch tabs within a submitted application panel */
        function switchAppTab(appIdx, contentId, btn, partSegId) {
            const panel = document.getElementById('app-detail-' + appIdx);
            if (!panel) return;
            // Deactivate all content within this panel
            panel.querySelectorAll('.atab-content').forEach(c => c.classList.remove('active'));
            panel.querySelectorAll('.atab').forEach(t => t.classList.remove('active'));
            // Deactivate part segments
            panel.querySelectorAll('.part-segment').forEach(s => s.classList.remove('part-active'));
            // Activate selected
            const el = document.getElementById(contentId);
            if (el) el.classList.add('active');
            if (btn) btn.classList.add('active');
            // Activate part segment label
            const seg = document.getElementById(partSegId);
            if (seg) seg.classList.add('part-active');
        }

        /*  MULTI-APP PICKER  */

        function openAppDetail(idx) {
            const picker = document.getElementById('app-picker-section');
            const detail = document.getElementById('app-detail-' + idx);
            if (picker) picker.style.display = 'none';
            if (detail) {
                detail.classList.add('active');
                detail.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        function closeAppDetail(idx) {
            const picker = document.getElementById('app-picker-section');
            const detail = document.getElementById('app-detail-' + idx);
            if (detail) detail.classList.remove('active');
            if (picker) {
                picker.style.display = '';
                picker.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        /* PICKER SEARCH + STATUS FILTER 
           Unlike the evaluator queue (applications.php), every application
           row/card here already carries its own data-* attributes rendered
           server-side — there's no separate large table to query, so this
           filters the existing DOM nodes directly instead of fetching from
           the server. Still "no page reload," just done client-side since
           the full applicant dataset is already on the page. */
        (function() {
            const searchInput = document.getElementById('myapp-search-input');
            const statusSelect = document.getElementById('myapp-status-select');
            const clearBtn = document.getElementById('myapp-search-clear');
            const emptyState = document.getElementById('myapp-picker-empty');
            const emptyText = document.getElementById('myapp-picker-empty-text');
            const tableWrap = document.getElementById('myapp-picker-table-wrap');
            const cardsWrap = document.getElementById('myapp-picker-cards');
            if (!searchInput || !statusSelect) return;

            function matches(el, term, status) {
                if (status && el.dataset.status !== status) return false;
                if (!term) return true;
                return (
                    el.dataset.position.includes(term) ||
                    el.dataset.employer.includes(term) ||
                    el.dataset.rsf.includes(term) ||
                    el.dataset.country.includes(term)
                );
            }

            function applyFilter() {
                const term = searchInput.value.trim().toLowerCase();
                const status = statusSelect.value;
                clearBtn.classList.toggle('hidden', !term);

                const rows = document.querySelectorAll('.myapp-picker-row');
                const cards = document.querySelectorAll('.myapp-picker-card');
                let visibleCount = 0;

                rows.forEach(row => {
                    const ok = matches(row, term, status);
                    row.style.display = ok ? '' : 'none';
                    if (ok) visibleCount++;
                });
                cards.forEach(card => {
                    card.style.display = matches(card, term, status) ? '' : 'none';
                });

                if (visibleCount === 0) {
                    if (tableWrap) tableWrap.style.display = 'none';
                    if (cardsWrap) cardsWrap.style.display = 'none';
                    emptyState.style.display = '';
                    const parts = [];
                    if (term) parts.push(`"${term}"`);
                    if (status) parts.push(`status "${status.replace(/_/g, ' ')}"`);
                    emptyText.textContent = parts.length ?
                        `No applications match ${parts.join(' and ')}. Try a different keyword or status.` :
                        'No applications found.';
                } else {
                    emptyState.style.display = 'none';
                    if (tableWrap) tableWrap.style.display = '';
                    if (cardsWrap) cardsWrap.style.display = '';
                }
            }

            searchInput.addEventListener('input', applyFilter);
            statusSelect.addEventListener('change', applyFilter);
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                applyFilter();
                searchInput.focus();
            });
        })();

        /*  WIZARD LOGIC (new application flow)  */
        (function() {
            const guide = document.getElementById('applicationWizardGuide');
            if (!guide) return;
            const jobId = <?= $show_wizard && $wizard_job ? (int)$wizard_job_id : 0 ?>;
            if (!jobId) return;
            const isLiveServer = window.location.port === '5500';

            function showErr(elId, msg) {
                const el = document.getElementById(elId);
                if (!el) return;
                el.textContent = msg || '';
                el.classList.toggle('hidden', !msg);
            }

            function defaultNetworkHelp() {
                if (isLiveServer) return 'Live Server cannot run PHP. Open this page via Apache (e.g. http://localhost/capstone/client/my-applications.php).';
                return 'Network error. Please try again.';
            }

            async function postWizard(fd) {
                const r = await fetch('process_application.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const raw = await r.text();
                try {
                    return JSON.parse(raw);
                } catch (e) {
                    const snip = (raw || '').trim().slice(0, 180);
                    throw new Error(snip ? `Invalid server response: ${snip}` : defaultNetworkHelp());
                }
            }

            function markDoneAndEnable(from, to) {
                const map = {
                    1: {
                        tab: 'tab1',
                        btn: 'ptab-rsf'
                    },
                    2: {
                        tab: 'tab2',
                        btn: 'ptab-questions'
                    },
                    3: {
                        tab: 'tab3',
                        btn: 'ptab-uploads'
                    },
                    4: {
                        tab: 'tab4',
                        btn: 'ptab-review'
                    }
                };
                const fromBtn = document.getElementById(map[from]?.btn || '');
                if (fromBtn) fromBtn.classList.add('done');
                const toBtn = document.getElementById(map[to]?.btn || '');
                if (toBtn) toBtn.disabled = false;
                switchWizTab(map[to]?.tab || ('tab' + to), toBtn);
            }

            /* Step 1 */
            /* Step 1 */
            const p1 = document.getElementById('wizard-step1-proceed');
            if (p1) p1.addEventListener('click', function() {
                showErr('wizard-step1-err', '');
                const ack = document.getElementById('rsf_ack');
                if (ack && !ack.checked) {
                    showErr('wizard-step1-err', 'Please confirm you have read and understood the Recruitment Specification Form RSF before proceeding.');
                    return;
                }
                p1.disabled = true;
                const fd = new FormData();
                fd.append('action', 'step1_proceed');
                fd.append('job_id', String(jobId));
                fd.append('rsf_ack', '1');
                postWizard(fd).then(data => {
                    p1.disabled = false;
                    if (!data.ok) {
                        showErr('wizard-step1-err', data.error || 'Something went wrong.');
                        return;
                    }
                    const tab2 = document.getElementById('tab2');
                    if (tab2) tab2.innerHTML = data.html;
                    try {
                        history.replaceState(null, '', 'my-applications.php?job_id=' + jobId + '&step=2');
                    } catch (e) {}
                    markDoneAndEnable(1, 2);
                    bindStep2();
                }).catch(err => {
                    p1.disabled = false;
                    showErr('wizard-step1-err', err?.message || defaultNetworkHelp());
                });
            });

            /* Step 2 */
            function bindStep2() {
                const form = document.getElementById('wizard-step2-form');
                if (!form || form.dataset.bound === '1') return;
                form.dataset.bound = '1';
                form.addEventListener('submit', function(ev) {
                    ev.preventDefault();
                    showErr('wizard-step2-err', '');
                    const btn = document.getElementById('wizard-step2-submit');
                    if (btn) btn.disabled = true;
                    const fd = new FormData(form);
                    fd.append('action', 'step2_save');
                    postWizard(fd).then(data => {
                        if (btn) btn.disabled = false;
                        if (!data.ok) {
                            showErr('wizard-step2-err', data.error || 'Something went wrong.');
                            return;
                        }
                        const tab3 = document.getElementById('tab3');
                        if (tab3) tab3.innerHTML = data.html;
                        try {
                            history.replaceState(null, '', 'my-applications.php?job_id=' + jobId + '&step=3');
                        } catch (e) {}
                        markDoneAndEnable(2, 3);
                        bindStep3Uploads();
                        bindStep3Continue();
                    }).catch(err => {
                        if (btn) btn.disabled = false;
                        showErr('wizard-step2-err', err?.message || defaultNetworkHelp());
                    });
                });
            }

            /* Step 3 - uploads */
            function bindStep3Uploads() {
                guide.querySelectorAll('.wiz-doc-upload-form').forEach(function(form) {
                    if (form.dataset.bound === '1') return;
                    form.dataset.bound = '1';
                    const fileInput = form.querySelector('input[name="doc_file"]');
                    const pickerBtn = form.querySelector('.wiz-file-picker-btn');
                    const pickerLabel = pickerBtn ? pickerBtn.innerHTML : '';

                    if (pickerBtn && fileInput) {
                        pickerBtn.addEventListener('click', function() {
                            fileInput.click();
                        });
                    }

                    if (fileInput) {
                        fileInput.addEventListener('change', function() {
                            if (!fileInput.files || !fileInput.files.length) return;

                            // Block the same file name being used for a different requirement
                            const picked = fileInput.files[0].name.trim().toLowerCase();
                            const currentRow = form.closest('.wiz-upload-row');
                            let duplicateOf = null;

                            guide.querySelectorAll('.wiz-upload-row').forEach(function(row) {
                                if (row === currentRow || duplicateOf) return;
                                const nameEl = row.querySelector('.wiz-upload-filename');
                                if (nameEl && nameEl.textContent.trim().toLowerCase() === picked) {
                                    const label = row.querySelector('strong');
                                    duplicateOf = label ? label.textContent.trim() : 'another requirement';
                                }
                            });

                            if (duplicateOf) {
                                const dupFileName = fileInput.files[0].name;
                                fileInput.value = ''; // clear the selection so nothing is uploaded
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Document Already Uploaded',
                                    text: '"' + dupFileName + '" was already uploaded for "' + duplicateOf + '". Please choose the correct file for this requirement.',
                                    confirmButtonText: 'OK',
                                    confirmButtonColor: '#2563eb'
                                });
                                return;
                            }

                            if (typeof form.requestSubmit === 'function') {
                                form.requestSubmit();
                            } else {
                                form.dispatchEvent(new Event('submit', {
                                    bubbles: true,
                                    cancelable: true
                                }));
                            }
                        });
                    }

                    form.addEventListener('submit', function(ev) {
                        ev.preventDefault();
                        const errEl = document.getElementById('wizard-step3-err');
                        if (errEl) {
                            errEl.textContent = '';
                            errEl.classList.add('hidden');
                        }
                        if (!fileInput || !fileInput.files || !fileInput.files.length) {
                            if (errEl) {
                                errEl.textContent = 'Please choose a file first.';
                                errEl.classList.remove('hidden');
                            }
                            return;
                        }
                        const fd = new FormData(form);
                        if (pickerBtn) {
                            pickerBtn.disabled = true;
                            pickerBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
                        }
                        postWizard(fd).then(data => {
                            if (pickerBtn) {
                                pickerBtn.disabled = false;
                                pickerBtn.innerHTML = pickerLabel;
                            }
                            if (!data.ok) {
                                if (fileInput) fileInput.value = '';
                                if (data.duplicate) {
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'Document Already Uploaded',
                                        text: data.error,
                                        confirmButtonColor: '#2563eb'
                                    });
                                    return;
                                }
                                if (errEl) {
                                    errEl.textContent = data.error || 'Upload failed.';
                                    errEl.classList.remove('hidden');
                                }
                                return;
                            }
                            const tab3 = document.getElementById('tab3');
                            if (tab3 && data.html) tab3.innerHTML = data.html;
                            bindStep3Uploads();
                            bindStep3Continue();

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
                                title: "Document uploaded successfully"
                            });
                        }).catch(err => {
                            if (pickerBtn) {
                                pickerBtn.disabled = false;
                                pickerBtn.innerHTML = pickerLabel;
                            }
                            if (fileInput) fileInput.value = '';
                            if (errEl) {
                                errEl.textContent = err?.message || defaultNetworkHelp();
                                errEl.classList.remove('hidden');
                            }
                        });
                    });
                });
            }

            /* Step 3 - continue */
            function bindStep3Continue() {
                const c = document.getElementById('wizard-step3-continue');
                if (!c || c.dataset.bound === '1') return;
                c.dataset.bound = '1';
                c.addEventListener('click', function() {
                    const errEl = document.getElementById('wizard-step3-err');
                    if (errEl) {
                        errEl.textContent = '';
                        errEl.classList.add('hidden');
                    }
                    c.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'step3_continue');
                    fd.append('job_id', String(jobId));
                    postWizard(fd).then(data => {
                        c.disabled = false;
                        if (!data.ok) {
                            if (errEl) {
                                errEl.textContent = data.error || 'Please complete all uploads.';
                                errEl.classList.remove('hidden');
                            }
                            return;
                        }
                        const tab4 = document.getElementById('tab4');
                        if (tab4 && data.html) tab4.innerHTML = data.html;
                        try {
                            history.replaceState(null, '', 'my-applications.php?job_id=' + jobId + '&step=4');
                        } catch (e) {}
                        markDoneAndEnable(3, 4);
                        bindStep4Submit();
                        bindStep4Reload();
                    }).catch(err => {
                        c.disabled = false;
                        if (errEl) {
                            errEl.textContent = err?.message || defaultNetworkHelp();
                            errEl.classList.remove('hidden');
                        }
                    });
                });
            }

            bindStep2();
            bindStep3Uploads();
            bindStep3Continue();

            /* Step 4 – submit */
            function bindStep4Submit() {
                const s4 = document.getElementById('wizard-step4-submit');
                if (!s4 || s4.dataset.bound === '1') return;
                s4.dataset.bound = '1';
                s4.addEventListener('click', function() {
                    showErr('wizard-step4-err', '');
                    s4.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'step4_submit');
                    fd.append('job_id', String(jobId));
                    postWizard(fd).then(data => {
                        s4.disabled = false;
                        if (!data.ok) {
                            showErr('wizard-step4-err', data.error || 'Submission failed.');
                            return;
                        }
                        const rev = document.getElementById('wizard-step4-review');
                        const okBox = document.getElementById('wizard-step4-success');
                        const okMsg = document.getElementById('wizard-step4-success-msg');
                        if (rev) rev.classList.add('hidden');
                        if (okMsg) okMsg.textContent = data.message || 'Submitted.';
                        if (okBox) okBox.classList.remove('hidden');
                        const reviewTab = document.getElementById('ptab-review');
                        if (reviewTab) reviewTab.classList.add('done');
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
                            title: "Application submitted successfully"
                        });
                        try {
                            history.replaceState(null, '', 'my-applications.php');
                        } catch (e) {}
                        bindStep4Reload();
                    }).catch(err => {
                        s4.disabled = false;
                        showErr('wizard-step4-err', err?.message || defaultNetworkHelp());
                    });
                });
            }

            function bindStep4Reload() {
                const r4 = document.getElementById('wizard-step4-reload');
                if (!r4 || r4.dataset.bound === '1') return;
                r4.dataset.bound = '1';
                r4.addEventListener('click', () => location.reload());
            }

            bindStep4Submit();
            bindStep4Reload();
        })();

        /* CUSTOM STATUS DROPDOWN — same floating-panel pattern as evaluator/index.php's
           enhancePillSelect(). Wraps the existing native #myapp-status-select so the
           search/filter script above (which reads .value and listens for 'change')
           keeps working completely unmodified. */
        (function() {
            function enhanceStatusSelect(select) {
                if (!select) return;

                const wrapper = document.createElement('div');
                wrapper.className = 'relative inline-flex items-center w-full sm:w-56';
                select.parentNode.insertBefore(wrapper, select);
                wrapper.appendChild(select);
                select.classList.add('hidden');
                select.tabIndex = -1;

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.className = 'flex items-center gap-2 w-full py-[9px] px-3 border border-[var(--border)] rounded-[10px] text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] cursor-pointer transition-colors hover:border-[var(--blue-lt)]';
                trigger.innerHTML = '<span class="flex-1 text-left truncate"></span>' +
                    '<i class="fas fa-chevron-down text-[10px] opacity-60 transition-transform flex-shrink-0"></i>';
                wrapper.appendChild(trigger);

                const panel = document.createElement('div');
                panel.setAttribute('role', 'listbox');
                panel.className = 'status-filter-panel hidden fixed w-56 bg-white border border-slate-200 rounded-2xl shadow-[0_10px_30px_rgba(15,23,42,.14)] z-[999] overflow-hidden p-1.5 max-h-[260px] overflow-y-auto';
                document.body.appendChild(panel); // appended to body so it isn't clipped by the filter bar

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.className = 'status-filter-option flex items-center justify-between gap-2 py-2 px-3 mb-0.5 last:mb-0 rounded-xl text-[12.5px] font-semibold text-slate-600 cursor-pointer hover:bg-blue-50 hover:text-blue-600 transition-colors';
                    row.dataset.value = optionEl.value;
                    const label = document.createElement('span');
                    label.className = 'truncate';
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
                        row.classList.toggle('bg-blue-50', selected);
                        row.classList.toggle('text-blue-600', selected);
                        row.classList.toggle('font-bold', selected);
                        row.classList.toggle('is-selected', selected);
                        let check = row.querySelector('.fa-check');
                        if (selected && !check) {
                            const icon = document.createElement('i');
                            icon.className = 'fas fa-check text-[10px] text-blue-500 flex-shrink-0';
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
                    const panelWidth = 224; // matches w-56
                    let left = rect.left;
                    const maxLeft = window.innerWidth - panelWidth - 8;
                    if (left > maxLeft) left = Math.max(8, maxLeft);
                    panel.style.top = (rect.bottom + 6) + 'px';
                    panel.style.left = left + 'px';
                    panel.style.width = rect.width + 'px';
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

            enhanceStatusSelect(document.getElementById('myapp-status-select'));
        })();

        async function downloadCertificate(event, link) {
            event.preventDefault(); // must happen synchronously, before any await

            Swal.fire({
                title: 'Processing Your Certificate',
                html: 'Your Certificate of Approval is being generated.<br>Please wait while this process is completed.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                const response = await fetch(link.href, {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Server returned an error while generating the certificate.');
                }

                const blob = await response.blob();

                let filename = 'certificate.pdf';
                const disposition = response.headers.get('Content-Disposition');
                if (disposition) {
                    const match = disposition.match(/filename="?([^"]+)"?/);
                    if (match && match[1]) filename = match[1];
                }

                const blobUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = blobUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(blobUrl);

                Swal.close();
                setTimeout(() => {
                    showCertificateDownloadedToast();
                }, 300);

            } catch (err) {
                Swal.close();
                setTimeout(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Download Failed',
                        text: err.message || 'Something went wrong while downloading your certificate. Please try again.'
                    });
                }, 300);
            }
        }
        /* Success toast — fires once the processing modal above has confirmed the certificate request actually completed. */
        function showCertificateDownloadedToast() {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Downloaded successfully',
                text: 'Your certificate has been saved to your device.',
                showConfirmButton: false,
                timer: 2500,
                timerProgressBar: true,
                didOpen: (toastEl) => {
                    toastEl.addEventListener('mouseenter', Swal.stopTimer);
                    toastEl.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
        }
    </script>

    <?php if ($open_app_idx !== null): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                openAppDetail(<?= (int) $open_app_idx ?>);
            });
        </script>
    <?php endif; ?>
    <div id="wizPreviewOverlay" class="wiz-preview-overlay" onclick="if(event.target===this) closeDocPreview()">
        <div class="wiz-preview-modal" role="dialog" aria-modal="true" aria-labelledby="wizPreviewName">
            <div class="wiz-preview-head">
                <span id="wizPreviewName" class="wiz-preview-name"><i class="fas fa-file-lines"></i> Preview</span>
                <a id="wizPreviewOpenNew" href="#" target="_blank" rel="noopener" class="wiz-btn secondary" style="padding:7px 12px;font-size:11.5px;"><i class="fas fa-external-link-alt"></i> Open</a>
                <button type="button" class="wiz-btn secondary" style="padding:7px 12px;font-size:11.5px;" onclick="closeDocPreview()" title="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div id="wizPreviewBody" class="wiz-preview-body"></div>
        </div>
    </div>
    <script>
        function openDocPreview(src, name, ext) {
            const overlay = document.getElementById('wizPreviewOverlay');
            const body = document.getElementById('wizPreviewBody');
            const nameEl = document.getElementById('wizPreviewName');
            const openNew = document.getElementById('wizPreviewOpenNew');
            if (!overlay || !body || !nameEl || !openNew) return;

            nameEl.textContent = name || 'Preview';
            openNew.href = src || '#';
            body.innerHTML = '';
            ext = (ext || '').toLowerCase();
            const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

            if (ext === 'pdf') {
                const iframe = document.createElement('iframe');
                iframe.src = src;
                iframe.title = name || 'Document preview';
                body.appendChild(iframe);
            } else if (imageExts.indexOf(ext) !== -1) {
                const img = document.createElement('img');
                img.src = src;
                img.alt = name || 'Image preview';
                body.appendChild(img);
            } else {
                const wrap = document.createElement('div');
                wrap.className = 'wiz-preview-unsupported';
                wrap.appendChild(document.createTextNode('Preview is not available for this file type. '));
                const link = document.createElement('a');
                link.href = src;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = 'Open in a new tab';
                wrap.appendChild(link);
                body.appendChild(wrap);
            }

            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeDocPreview() {
            const overlay = document.getElementById('wizPreviewOverlay');
            const body = document.getElementById('wizPreviewBody');
            if (overlay) overlay.classList.remove('open');
            if (body) body.innerHTML = '';
            document.body.style.overflow = '';
        }

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.wiz-preview-btn');
            if (!btn) return;
            e.preventDefault();
            openDocPreview(btn.getAttribute('data-preview-src') || '', btn.getAttribute('data-preview-name') || '', btn.getAttribute('data-preview-ext') || '');
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDocPreview();
        });
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
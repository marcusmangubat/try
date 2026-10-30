<?php

/**
 * Client: view / edit submitted application (answers + documents).
 * Editing allowed only when canEditApplication(status) is true.
 */
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';
require_once '../includes/activity_logger.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$client_email = $_SESSION['user_email'];
$stmt = $database->prepare("
    SELECT cid, profile_picture, CONCAT(first_name, ' ', last_name) AS full_name
    FROM clients WHERE email = ? LIMIT 1
");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$client) {
    header('Location: ../login.php');
    exit;
}
$client_id = (int) $client['cid'];
$has_picture = !empty($client['profile_picture']);
$picture_url = $has_picture ? '../' . $client['profile_picture'] : null;

// Aliases so the shared avatar-menu markup works on this page
$client_name = trim((string) ($client['full_name'] ?? '')) ?: 'Client';
$client_initials = '';
foreach (explode(' ', $client_name) as $w) {
    $client_initials .= strtoupper($w[0] ?? '');
    if (strlen($client_initials) >= 2) break;
}
$client_initials = $client_initials ?: 'U';

$app_id = (int) ($_GET['id'] ?? $_POST['application_id'] ?? 0);
if (!$app_id) {
    header('Location: my-applications.php');
    exit;
}

$app_count = 0;
$stmt = $database->prepare("SELECT COUNT(*) as c FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$app_count = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $database->prepare("
    SELECT a.id, a.client_id, a.rsf_id, a.status, a.notes, a.updated_at,
           r.position_title, r.employer_name, r.worksite_country, r.rsf_number
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    WHERE a.id = ? AND a.client_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $app_id, $client_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$app) {
    header('Location: my-applications.php');
    exit;
}

$rsf_id = (int) $app['rsf_id'];
$status  = (string) $app['status'];
$editable = canEditApplication($status);

$tab_map = [
    'rsf'          => ['tabc-rsf',    'tabbtn-rsf',    'seg-part1'],
    'questions'    => ['tabc-q',      'tabbtn-q',      'seg-part1'],
    'requirements' => ['tabc-req',    'tabbtn-req',    'seg-part1'],
    'status'       => ['tabc-status', 'tabbtn-status', 'seg-part2'],
];
$requested_tab = strtolower((string) ($_GET['tab'] ?? ''));
$initial_tab   = $tab_map[$requested_tab] ?? null;

// ── Helper: detect AJAX (fetch) requests from the Requirements tab ──────────
function is_ajax_request(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ── Helper: load RSF-specific required documents (mirrors my-applications.php) ──
function edit_required_documents_for_job(mysqli $db, int $job_id): array
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
        $docs[] = [
            'id'            => (int)    $r['id'],
            'document_type' => (string) $r['document_type'],
            'document_name' => (string) $r['document_name'],
            'is_required'   => (int)    ($r['is_required'] ?? 1),
            'description'   => (string) ($r['description'] ?? ''),
        ];
    }

    // Fallback if no per-job requirements configured yet
    if ($docs === []) {
        return [
            ['id' => 0, 'document_type' => 'passport',    'document_name' => 'Passport (valid)',      'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'resume',      'document_name' => 'Resume / CV',           'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'diploma',     'document_name' => 'Diploma / TOR',         'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'certificate', 'document_name' => 'Certificate / license', 'is_required' => 1, 'description' => ''],
        ];
    }
    return $docs;
}

// ── Helper: load RSF-specific questions (mirrors my-applications.php) ──
function edit_questions_for_job(mysqli $db, int $job_id): array
{
    if ($job_id <= 0) return [];
    $stmt = $db->prepare('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id = ? ORDER BY COALESCE(order_no,999), id');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Backward compat: fall back to global questions
    if ($rows === []) {
        $res  = $db->query('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id IS NULL ORDER BY COALESCE(order_no,999), id');
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    return $rows;
}

// ── Helper: last time this application was flagged 'for_compliance' ──
function edit_last_compliance_timestamp(mysqli $db, int $app_id): ?string
{
    $stmt = $db->prepare("SELECT changed_at FROM status_history WHERE application_id = ? AND to_status = 'for_compliance' ORDER BY changed_at DESC LIMIT 1");
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['changed_at'] ?? null;
}

// ── Helper: notify the assigned evaluator + admin(s) about a resubmission ──
function edit_notify_resubmission(mysqli $db, int $app_id, string $client_name): void
{
    $stmt = $db->prepare("
        SELECT ev.email AS evaluator_email
        FROM applications a
        LEFT JOIN evaluators ev ON ev.eid = a.current_evaluator_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $app_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $title = 'Application Resubmitted';
    $msg   = $client_name . "'s application has been resubmitted and is ready for another review.";
    $type  = 'application_update';

    $recipients = [];
    if (!empty($row['evaluator_email'])) {
        $recipients[] = [$row['evaluator_email'], 'evaluator'];
    }
    $res = $db->query("SELECT email FROM admins");
    while ($res && ($a = $res->fetch_assoc())) {
        $recipients[] = [$a['email'], 'admin'];
    }

    $ins = $db->prepare("
        INSERT INTO notifications (user_email, usertype, application_id, title, message, type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($recipients as [$email, $role]) {
        $ins->bind_param('ssisss', $email, $role, $app_id, $title, $msg, $type);
        $ins->execute();
    }
    $ins->close();
}
/**
 * Renders ONE required-document row (the "wiz-upload-row" block).
 * Used both for the initial page render and for the AJAX JSON response
 * after an upload / replace / remove, so both paths render identical markup.
 *
 * @param array      $doc                    One entry from edit_required_documents_for_job()
 * @param array|null $uploaded               Matching row from application_documents, or null if none uploaded
 * @param array      $docCommentsByDocumentId Map of document_id => [comment rows]
 * @param bool       $editable
 * @param int        $app_id
 */
function edit_render_doc_row(array $doc, ?array $uploaded, array $docCommentsByDocumentId, bool $editable, int $app_id): string
{
    $docType = (string) ($doc['document_type'] ?? '');
    $docName = (string) ($doc['document_name'] ?? $docType);
    $reqId   = (int)    ($doc['id'] ?? 0);
    $isReq   = !empty($doc['is_required']);
    $desc    = (string) ($doc['description'] ?? '');

    $has   = $uploaded !== null;
    $upExt = $has ? strtolower(pathinfo((string)($uploaded['file_path'] ?? ($uploaded['file_name'] ?? '')), PATHINFO_EXTENSION)) : '';
    $hasComments = $has && !empty($docCommentsByDocumentId[(int)($uploaded['id'] ?? 0)]);

    ob_start();
?>
    <div class="wiz-upload-row <?= $has ? 'has-file' : '' ?>" data-required-doc-id="<?= $reqId ?>" data-doc-type="<?= htmlspecialchars($docType) ?>">
        <div class="wiz-label">
            <?= htmlspecialchars($docName) ?>
            <?php if ($has): ?>
                <span style="color:var(--green);font-size:11px;font-weight:600;"> ✓ Uploaded</span>
            <?php elseif ($isReq): ?>
                <span class="wiz-req"> *</span>
            <?php else: ?>
                <span style="font-size:11px;color:var(--text3);"> (optional)</span>
            <?php endif; ?>
        </div>

        <?php if ($desc !== ''): ?>
            <p class="wiz-muted"><?= htmlspecialchars($desc) ?></p>
        <?php endif; ?>

        <?php if ($has): ?>
            <div class="wiz-upload-filename">
                <i class="fas fa-file"></i>
                <?= htmlspecialchars($uploaded['file_name'] ?? '') ?>
            </div>
            <?php if ($hasComments): ?>
                <div class="doc-comments">
                    <div class="doc-comments-hd"><i class="fas fa-comment-dots"></i> Reviewer comments</div>
                    <?php foreach ($docCommentsByDocumentId[(int)($uploaded['id'] ?? 0)] as $c): ?>
                        <div class="doc-comment-item">
                            <div class="doc-comment-meta">
                                <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?>
                                &middot; <?= date('F d, Y g:i a', strtotime($c['created_at'])) ?>
                            </div>
                            <div class="doc-comment-text"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="wiz-upload-actions">
                <?php if (!empty($uploaded['file_path'])): ?>
                    <button type="button" class="btn btn-sm wiz-preview-btn" style="background:var(--surface);border:1px solid var(--border);color:var(--blue-lt);"
                        data-preview-src="../<?= htmlspecialchars($uploaded['file_path']) ?>"
                        data-preview-name="<?= htmlspecialchars($uploaded['file_name'] ?? '') ?>"
                        data-preview-ext="<?= htmlspecialchars($upExt) ?>">
                        <i class="fas fa-eye"></i> Preview
                    </button>
                <?php endif; ?>
                <?php if ($editable): ?>
                    <form method="POST" style="display:inline;" class="delete-doc-form">
                        <input type="hidden" name="action" value="delete_doc">
                        <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                        <input type="hidden" name="doc_id" value="<?= (int)$uploaded['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove this document?">
                            <i class="fas fa-trash-can"></i> Remove
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($editable): ?>
            <form method="POST" enctype="multipart/form-data" class="js-doc-upload-form" style="margin-top:<?= $has ? '10px' : '4px' ?>;">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                <input type="hidden" name="required_doc_id" value="<?= (int)$reqId ?>">
                <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">
                <input type="hidden" name="document_name" value="<?= htmlspecialchars($docName) ?>">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png"
                        class="wiz-input" style="flex:1;min-width:180px;padding:8px;" required>
                    <button type="submit" class="btn btn-primary btn-sm" style="flex-shrink:0;">
                        <i class="fas fa-upload"></i> <?= $has ? 'Replace' : 'Upload' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}

// ── Evaluator note ──
$eval_note = '';
$stmt = $database->prepare("SELECT comments FROM evaluations WHERE application_id = ? ORDER BY evaluated_at DESC LIMIT 1");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$er = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($er && trim((string)($er['comments'] ?? '')) !== '') {
    $eval_note = trim($er['comments']);
}
$notes_display        = trim((string)($app['notes'] ?? '')) !== '' ? trim($app['notes']) : $eval_note;
$show_evaluator_notes = in_array($status, ['for_compliance', 'disapproved'], true);
$show_resubmit        = $status === 'for_compliance' && $editable;

$banner_message = '';
if (in_array($status, ['assign', 'assigned', 'under_evaluation'], true)) {
    $banner_message = 'Your application is assigned and cannot be edited.';
} elseif ($status === 'approved') {
    $banner_message = 'Your application has been approved and cannot be edited.';
} elseif ($status === 'disapproved') {
    $banner_message = 'This application is final and cannot be edited.';
} elseif ($status === 'for_compliance' && $editable) {
    $banner_message = "Please review the evaluator's notes and update your application.";
}

$error   = '';
$success = '';

//  POST: resubmit 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resubmit') {
    $stmt = $database->prepare("SELECT status, updated_at FROM applications WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $app_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cur = $row['status'] ?? '';
    if ($cur !== 'for_compliance') {
        $error = 'Resubmit is only available when your application is for compliance.';
    } else {
        $lastComplianceAt = edit_last_compliance_timestamp($database, $app_id);
        $hasChanges = $lastComplianceAt === null
            || strtotime((string)($row['updated_at'] ?? '')) > strtotime($lastComplianceAt);
        if (!$hasChanges) {
            $error = "Please update your answers or documents based on the evaluator's comments before resubmitting.";
        } elseif (application_status_change($database, $app_id, 'resubmitted', $client_email, 'Client resubmitted application')) {
            log_activity(
                $database,
                $client_email,
                'client',
                'application',
                'resubmit',
                "{$client['full_name']} resubmitted application for {$app['rsf_number']}",
                $app_id
            );
            edit_notify_resubmission($database, $app_id, (string) $client['full_name']);
            header('Location: edit-application.php?id=' . $app_id . '&resubmitted=1');
            exit;
        } else {
            $error = 'Could not update status. Please try again.';
        }
    }
}

// Reload status after resubmit redirect
if (isset($_GET['resubmitted'])) {
    $success = 'Your application was marked as resubmitted. An evaluator will review it again.';
    $stmt    = $database->prepare("SELECT status, notes FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $r2 = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r2) {
        $app['status']   = $r2['status'];
        $app['notes']    = $r2['notes'];
        $status          = (string) $app['status'];
        $editable        = canEditApplication($status);
        $show_resubmit   = $status === 'for_compliance' && $editable;
        $show_evaluator_notes = in_array($status, ['for_compliance', 'disapproved'], true);
        $banner_message  = match (true) {
            in_array($status, ['assign', 'assigned', 'under_evaluation'], true) => 'Your application is assigned and cannot be edited.',
            $status === 'for_compliance' && $editable => "Please review the evaluator's notes and update your application.",
            default => '',
        };
    }
}

// —— POST: save answers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'answers') {
    $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $app_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !canEditApplication($row['status'])) {
        log_activity(
            $database,
            $client_email,
            'client',
            'application',
            'edit_blocked',
            'Attempted to modify application in non-editable status',
            $app_id
        );
        $error = 'You cannot edit this application in its current status.';
    } else {
        $questions_post = edit_questions_for_job($database, $rsf_id);
        $all_ok = true;
        foreach ($questions_post as $q) {
            if ($q['is_required'] && empty(trim($_POST['q_' . $q['id']] ?? ''))) {
                $all_ok = false;
                $error  = 'Please answer all required questions.';
                break;
            }
        }
        if ($all_ok) {
            $database->query("DELETE FROM application_answers WHERE application_id = " . (int)$app_id);
            $stmt = $database->prepare("INSERT INTO application_answers (application_id, question_id, answer) VALUES (?, ?, ?)");
            foreach ($questions_post as $q) {
                $ans = trim($_POST['q_' . $q['id']] ?? '');
                $qid = (int) $q['id'];
                $stmt->bind_param("iis", $app_id, $qid, $ans);
                $stmt->execute();
            }
            $stmt->close();
            $database->query("UPDATE applications SET updated_at = NOW() WHERE id = " . (int)$app_id);
            log_activity(
                $database,
                $client_email,
                'client',
                'application',
                'update_answers',
                "Updated " . count($questions_post) . " answer(s) for {$app['rsf_number']}",
                $app_id
            );
            $success = 'Answers saved successfully.';
        }
    }
}

// —— POST: upload document (per-required-doc, mirrors wizard step 3) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $doc_type   = trim($_POST['document_type']  ?? '') ?: 'other';
    $doc_name   = trim($_POST['document_name']  ?? '') ?: $doc_type;
    $req_doc_id = (int)($_POST['required_doc_id'] ?? 0);
    $new_doc_id = null;
    $rel_path   = null;
    $orig       = null;
    $replaced   = false;

    $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $app_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !canEditApplication($row['status'])) {
        $error = 'You cannot upload documents for this application in its current status.';
    } elseif (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === 0) {
        $upload_dir  = '../uploads/app_documents/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $ext     = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        if (in_array($ext, $allowed, true)) {
            $suffix = $req_doc_id > 0 ? 'doc_' . $req_doc_id : $doc_type;
            $fname  = $app_id . '_' . time() . '_' . $suffix . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $path   = $upload_dir . $fname;
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $path)) {
                $rel_path = 'uploads/app_documents/' . $fname;
                $orig     = $_FILES['doc_file']['name'];

                // Replace existing doc of same required_doc_id / document_type
                if ($req_doc_id > 0) {
                    // Delete the old file + record for this required_doc slot
                    $stmt = $database->prepare("SELECT file_path FROM application_documents WHERE application_id = ? AND required_doc_id = ? LIMIT 1");
                    $stmt->bind_param("ii", $app_id, $req_doc_id);
                    $stmt->execute();
                    $old = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($old && !empty($old['file_path'])) @unlink('../' . $old['file_path']);
                    $replaced = (bool) $old;
                    $database->query("DELETE FROM application_documents WHERE application_id = " . (int)$app_id . " AND required_doc_id = " . (int)$req_doc_id);
                    $stmt = $database->prepare("INSERT INTO application_documents (application_id, document_type, required_doc_id, file_name, file_path) VALUES (?,?,?,?,?)");
                    $stmt->bind_param("isiss", $app_id, $doc_type, $req_doc_id, $orig, $rel_path);
                } else {
                    // Fallback: replace by document_type
                    $stmt2 = $database->prepare("SELECT file_path FROM application_documents WHERE application_id = ? AND document_type = ? AND (required_doc_id IS NULL OR required_doc_id = 0) LIMIT 1");
                    $stmt2->bind_param("is", $app_id, $doc_type);
                    $stmt2->execute();
                    $old2 = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();
                    if ($old2 && !empty($old2['file_path'])) @unlink('../' . $old2['file_path']);
                    $replaced = (bool) $old2;
                    $database->query("DELETE FROM application_documents WHERE application_id = " . (int)$app_id . " AND document_type = '" . $database->real_escape_string($doc_type) . "' AND (required_doc_id IS NULL OR required_doc_id = 0)");
                    $stmt = $database->prepare("INSERT INTO application_documents (application_id, document_type, file_name, file_path) VALUES (?,?,?,?)");
                    $stmt->bind_param("isss", $app_id, $doc_type, $orig, $rel_path);
                }
                $stmt->execute();
                $new_doc_id = $database->insert_id;
                $stmt->close();
                $database->query("UPDATE applications SET updated_at = NOW() WHERE id = " . (int)$app_id);
                log_activity(
                    $database,
                    $client_email,
                    'client',
                    'document',
                    $replaced ? 'replace' : 'upload',
                    ($replaced ? 'Replaced' : 'Uploaded') . " \"{$doc_name}\" ({$orig})",
                    $app_id
                );
                $success = 'Document uploaded successfully.';
            } else {
                $error = 'Upload failed. Please try again.';
            }
        } else {
            $error = 'Invalid file type. Allowed: PDF, JPG, PNG.';
        }
    } else {
        $error = 'Please select a file to upload.';
    }

    // ── AJAX response: return the freshly rendered row instead of reloading ──
    if (is_ajax_request()) {
        header('Content-Type: application/json');
        if ($success !== '' && $new_doc_id) {
            $required_docs_now = edit_required_documents_for_job($database, $rsf_id);
            $matched_doc = null;
            foreach ($required_docs_now as $rd) {
                if ($req_doc_id > 0 && (int)$rd['id'] === $req_doc_id) {
                    $matched_doc = $rd;
                    break;
                }
                if ($req_doc_id === 0 && $rd['document_type'] === $doc_type) {
                    $matched_doc = $rd;
                    break;
                }
            }
            if ($matched_doc === null) {
                $matched_doc = [
                    'id'            => $req_doc_id,
                    'document_type' => $doc_type,
                    'document_name' => $doc_name,
                    'is_required'   => 0,
                    'description'   => '',
                ];
            }
            $uploaded_row = ['id' => $new_doc_id, 'file_name' => $orig, 'file_path' => $rel_path];
            $html = edit_render_doc_row($matched_doc, $uploaded_row, [], $editable, $app_id);

            echo json_encode([
                'success'          => true,
                'message'          => $success,
                'html'             => $html,
                'required_doc_id'  => $req_doc_id,
                'document_type'    => $doc_type,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $error ?: 'Upload failed. Please try again.',
            ]);
        }
        exit;
    }
}

// —— POST: delete document ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc') {
    $doc_id  = (int)($_POST['doc_id'] ?? 0);
    $is_ajax = is_ajax_request();

    $del_success          = false;
    $del_message          = 'Could not remove document.';
    $del_required_doc_id  = 0;
    $del_document_type    = '';
    $del_is_extra         = false;
    $matched_doc          = null;

    if ($doc_id && $editable) {
        $stmt = $database->prepare("SELECT file_path, document_type, required_doc_id FROM application_documents WHERE id = ? AND application_id = ? LIMIT 1");
        $stmt->bind_param("ii", $doc_id, $app_id);
        $stmt->execute();
        $drow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($drow) {
            if (!empty($drow['file_path'])) @unlink('../' . $drow['file_path']);
            $database->query("DELETE FROM application_documents WHERE id = " . $doc_id);
            $database->query("UPDATE applications SET updated_at = NOW() WHERE id = " . (int)$app_id);
            log_activity(
                $database,
                $client_email,
                'client',
                'document',
                'delete',
                "Removed document of type \"{$drow['document_type']}\"",
                $app_id
            );

            $success             = 'Document removed.';
            $del_success         = true;
            $del_message         = $success;
            $del_required_doc_id = (int)    ($drow['required_doc_id'] ?? 0);
            $del_document_type   = (string) ($drow['document_type'] ?? '');

            // Does this removed slot correspond to a configured required document?
            $required_docs_now = edit_required_documents_for_job($database, $rsf_id);
            foreach ($required_docs_now as $rd) {
                if ($del_required_doc_id > 0 && (int)$rd['id'] === $del_required_doc_id) {
                    $matched_doc = $rd;
                    break;
                }
                if ($del_required_doc_id === 0 && $rd['document_type'] === $del_document_type) {
                    $matched_doc = $rd;
                    break;
                }
            }
            $del_is_extra = $matched_doc === null;
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        if ($del_success) {
            if ($del_is_extra) {
                echo json_encode([
                    'success' => true,
                    'message' => $del_message,
                    'extra'   => true,
                    'doc_id'  => $doc_id,
                ]);
            } else {
                $html = edit_render_doc_row($matched_doc, null, [], $editable, $app_id);
                echo json_encode([
                    'success'         => true,
                    'message'         => $del_message,
                    'html'            => $html,
                    'required_doc_id' => $del_required_doc_id,
                    'document_type'   => $del_document_type,
                ]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => $del_message]);
        }
        exit;
    }

    header('Location: edit-application.php?id=' . $app_id);
    exit;
}

// ── Load questions + existing answers ────────────────────────────────────────
$questions   = edit_questions_for_job($database, $rsf_id);
$answers_map = [];
$stmt = $database->prepare("SELECT question_id, answer FROM application_answers WHERE application_id = ?");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$ar = $stmt->get_result();
while ($r = $ar->fetch_assoc()) {
    $answers_map[(int)$r['question_id']] = $r['answer'];
}
$stmt->close();

// ── Load existing uploaded documents ─────────────────────────────────────────
$docs = [];
$stmt = $database->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$docCommentsByDocumentId = [];
if (!empty($docs)) {
    $stmt = $database->prepare("
        SELECT dc.document_id, dc.comment, dc.created_at, dc.commented_by,
               COALESCE(ev.full_name, ad.full_name, dc.commented_by) AS commenter_name
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
        $did = (int)($row['document_id'] ?? 0);
        if (!isset($docCommentsByDocumentId[$did])) {
            $docCommentsByDocumentId[$did] = [];
        }
        $docCommentsByDocumentId[$did][] = $row;
    }
}

// Build a lookup: required_doc_id → uploaded doc row
$uploaded_by_req_id   = [];  // keyed by required_doc_id (int)
$uploaded_by_doc_type = [];  // keyed by document_type (string) for fallback
foreach ($docs as $d) {
    $rid = (int)($d['required_doc_id'] ?? 0);
    if ($rid > 0) {
        $uploaded_by_req_id[$rid] = $d;
    } else {
        $uploaded_by_doc_type[$d['document_type']] = $d;
    }
}

//  Load status history 
$status_history = [];
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
//  Load RSF-specific required documents 
$required_docs = edit_required_documents_for_job($database, $rsf_id);

$compliance_pending_changes = false;
if ($status === 'for_compliance') {
    $lastComplianceAt = null;
    foreach ($status_history as $sh) {
        if (($sh['to_status'] ?? '') === 'for_compliance') {
            $lastComplianceAt = $sh['changed_at']; // DESC-ordered → first match is most recent
            break;
        }
    }
    if ($lastComplianceAt !== null) {
        $compliance_pending_changes = !(strtotime((string)$app['updated_at']) > strtotime($lastComplianceAt));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PRESENTATION-ONLY prep below. Nothing above this line was changed.
// These functions/variables only shape how the already-loaded data is
// displayed, mirroring the wizard UI in my-applications.php — no new
// queries, no writes, no business-rule changes.
// ─────────────────────────────────────────────────────────────────────────────

function edit_status_info(string $status): array
{
    return match (true) {
        $status === 'approved' => ['pill' => 'approved', 'icon' => 'fa-circle-check', 'label' => 'Approved', 'color' => '#10b981'],
        $status === 'under_evaluation' => ['pill' => 'evaluating', 'icon' => 'fa-user-check', 'label' => 'Under Evaluation', 'color' => '#6366f1'],
        $status === 'for_compliance' => ['pill' => 'compliance', 'icon' => 'fa-triangle-exclamation', 'label' => 'For Compliance', 'color' => '#f59e0b'],
        $status === 'disapproved' => ['pill' => 'disapproved', 'icon' => 'fa-circle-xmark', 'label' => 'Disapproved', 'color' => '#ef4444'],
        $status === 'resubmitted' => ['pill' => 'resubmitted', 'icon' => 'fa-rotate-right', 'label' => 'Resubmitted', 'color' => '#0ea5e9'],
        $status === 'pending' => ['pill' => 'pending', 'icon' => 'fa-clock', 'label' => 'Pending Evaluator Review', 'color' => '#64748b'],
        in_array($status, ['assign', 'assigned'], true) => ['pill' => 'evaluating', 'icon' => 'fa-user-check', 'label' => 'Assigned', 'color' => '#6366f1'],
        default => ['pill' => 'pending', 'icon' => 'fa-file-lines', 'label' => ucwords(str_replace('_', ' ', $status)), 'color' => '#94a3b8'],
    };
}

function edit_relative_time(string $datetime): string
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

$status_info = edit_status_info($status);

// Has the applicant changed anything (answers/upload/delete) since the
// evaluator last flagged this 'for_compliance'? Derived from the already
// loaded $status_history (below it's fetched DESC, so the first
// 'for_compliance' entry found is the most recent one) — no extra query.

// Map document_id → document_type, so timeline comments can be labeled
$doc_id_to_type = [];
foreach ($docs as $d) {
    $doc_id_to_type[(int)$d['id']] = (string)($d['document_type'] ?? 'document');
}

// Merge status-history + document comments into one chronological timeline
// (oldest first), the same way my-applications.php builds its Status tab.
$timeline_events = [];
foreach (array_reverse($status_history) as $sh) {
    $timeline_events[] = ['type' => 'status', 'ts' => strtotime($sh['changed_at']), 'data' => $sh];
}
foreach ($docCommentsByDocumentId as $docId => $comments) {
    $doc_type_label = $doc_id_to_type[(int)$docId] ?? 'document';
    foreach ($comments as $c) {
        $timeline_events[] = ['type' => 'comment', 'ts' => strtotime($c['created_at']), 'data' => $c, 'doc_type' => $doc_type_label];
    }
}
usort($timeline_events, fn($a, $b) => $a['ts'] <=> $b['ts']);

// Progress-step model — identical logic to the Status tab in my-applications.php
$cur = $status;
$historyToStatuses = array_column($status_history, 'to_status');
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

$disabled_attr = $editable ? '' : ' disabled';
$readonly_attr = $editable ? '' : ' readonly';
$page_title    = 'Application – ' . ($app['position_title'] ?? 'Edit');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> – DMW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]']
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <style>
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

        .wiz-input:disabled {
            opacity: .65;
            cursor: not-allowed;
            background: #f1f5f9;
        }

        [data-theme="dark"] .wiz-input:disabled {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text2);
        }

        .wiz-muted {
            font-size: 12px;
            color: var(--text3);
            margin: 0 0 8px;
        }

        /* ── Upload row (mirrors wizard Step 3) ── */
        .wiz-upload-row {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            background: var(--surface);
        }

        .wiz-upload-row.has-file {
            border-color: #a7f3d0;
            background: #f0fdf4;
        }

        [data-theme="dark"] .wiz-upload-row.has-file {
            background: rgba(16, 185, 129, 0.10);
            border-color: rgba(16, 185, 129, 0.4);
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

        /* ── Inline reviewer comments (Requirements tab) ── */
        .doc-comments {
            margin-top: 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f8fafc;
            padding: 10px 12px;
        }

        [data-theme="dark"] .doc-comments {
            background: var(--surface);
            border-color: var(--border);
        }

        .doc-comments-hd {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--amber);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }

        .doc-comment-item {
            padding: 6px 0;
            border-top: 1px dashed var(--border);
        }

        .doc-comment-item:first-child {
            border-top: none;
            padding-top: 0;
        }

        .doc-comment-meta {
            font-size: 11px;
            color: var(--text3);
            font-weight: 700;
            margin-bottom: 2px;
        }

        .doc-comment-text {
            font-size: 12.5px;
            color: var(--text1);
            line-height: 1.5;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-family: 'DM Sans', sans-serif;
        }

        .btn-primary {
            background: var(--blue);
            color: #fff;
        }

        .btn-amber {
            background: var(--amber);
            color: #fff;
        }

        .btn-danger {
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fecaca;
        }

        [data-theme="dark"] .btn-danger {
            background: rgba(220, 38, 38, 0.14);
            border-color: rgba(220, 38, 38, 0.4);
            color: #f87171;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 11.5px;
            width: auto;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--red);
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: var(--green);
        }

        .alert-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: var(--navy);
        }

        [data-theme="dark"] .alert-error {
            background: rgba(220, 38, 38, 0.14);
            border-color: rgba(220, 38, 38, 0.35);
        }

        [data-theme="dark"] .alert-success {
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.35);
        }

        [data-theme="dark"] .alert-info {
            background: rgba(59, 130, 246, 0.14);
            border-color: rgba(59, 130, 246, 0.35);
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        .main {
            padding-top: 18px;
            padding-bottom: 40px;
        }

        .page-alerts {
            margin-bottom: 4px;
        }

        /* ── Detail header breadcrumb (same as my-applications.php) ── */
        .detail-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 16px;
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

        /* ── Two-part tab system (same as my-applications.php) ── */
        .two-part-wrapper {
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 16px;
        }

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

        .part-segment-name {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text2);
        }

        .part-segment.part-active .part-segment-name {
            color: var(--blue-lt);
        }

        .app-tabs {
            display: flex;
            width: 100%;
            overflow-x: hidden;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .atab {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            flex: 1 1 0;
            min-width: 0;
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

        .atab.done:hover {
            color: var(--green);
            background: rgba(16, 185, 129, 0.18);
        }

        .atab-divider {
            width: 1px;
            flex: 0 0 auto;
            background: var(--border);
            margin: 6px 0;
        }

        .atab-content {
            display: none;
        }

        .atab-content.active {
            display: block;
            animation: fadeUp .22s ease;
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
            }

            .atab i {
                font-size: 14px;
            }
        }

        .tab-body {
            padding: 18px 16px;
        }

        /* ── Info grid (RSF tab) ── */
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

        /* ── Status pill ── */
        .status-pill {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 4px 10px;
            border-radius: 99px;
            white-space: nowrap;
            text-transform: capitalize;
            display: inline-block;
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

        [data-theme="dark"] .status-pill {
            background: transparent !important;
        }

        .action-req-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
            font-weight: 800;
            color: var(--amber);
            margin-top: 6px;
            white-space: nowrap;
        }

        .action-req-tag i {
            font-size: 8px;
        }

        /* ── Status Tab pieces ── */
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

        .progress-steps {
            display: flex;
            align-items: center;
            gap: 0;
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

        .action-required-card {
            background: rgba(245, 158, 11, 0.08);
            border: 1.5px solid rgba(245, 158, 11, 0.35);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
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

        /* ── Document preview modal (same as my-applications.php) ── */
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

        .back-link-row {
            text-align: center;
            margin-top: 18px;
        }

        @media (max-width: 640px) {
            .main {
                padding-left: 12px;
                padding-right: 12px;
            }

            .detail-header {
                padding-left: 0;
                padding-right: 0;
            }
        }

        /* ═══ READABILITY SCALE (older users) — ~+1.5px per size.
           Keep this block LAST in <style>: it re-declares existing selectors and relies on source order. ═══ */

        /* A) Page classes */
        .detail-job-name {
            font-size: 15.5px;
        }

        .detail-job-meta {
            font-size: 13px;
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

        .atab.done::before {
            font-size: 10.5px;
        }

        .info-dt {
            font-size: 11.5px;
        }

        .info-dd {
            font-size: 14.5px;
        }

        .status-pill {
            font-size: 12px;
        }

        .action-req-tag {
            font-size: 11px;
        }

        .wiz-label {
            font-size: 13.5px;
        }

        .wiz-input {
            font-size: 14.5px;
        }

        .wiz-muted {
            font-size: 13.5px;
        }

        .wiz-upload-filename {
            font-size: 13.5px;
        }

        .doc-comments-hd {
            font-size: 11.5px;
        }

        .doc-comment-meta {
            font-size: 12.5px;
        }

        .doc-comment-text {
            font-size: 14px;
        }

        .btn {
            font-size: 14.5px;
        }

        .btn-sm {
            font-size: 13px;
        }

        /* must stay AFTER .btn */
        .alert {
            font-size: 14.5px;
        }

        .status-current-badge {
            font-size: 15.5px;
        }

        .ps-circle {
            font-size: 13.5px;
        }

        .ps-label {
            font-size: 10.5px;
        }

        .action-required-head {
            font-size: 14px;
        }

        .action-required-desc {
            font-size: 13.5px;
        }

        .eval-note-label {
            font-size: 11.5px;
        }

        .eval-note-text {
            font-size: 14.5px;
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

        .wiz-preview-name {
            font-size: 14.5px;
        }

        .wiz-preview-unsupported {
            font-size: 14.5px;
        }

        /* The original small-screen tab rules are re-declared here, AFTER the base .atab above */
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

        /* B) Tailwind arbitrary sizes (avatar menu, "All Applications" back link) — !important because the CDN sheet loads after this block */
        .text-\[10\.5px\] {
            font-size: 12px !important
        }

        .text-\[11px\] {
            font-size: 12.5px !important
        }

        .text-\[11\.5px\] {
            font-size: 13px !important
        }

        .text-\[12px\],
        .text-xs {
            font-size: 13.5px !important
        }

        .text-\[12\.5px\] {
            font-size: 14px !important
        }

        .text-\[13px\] {
            font-size: 14.5px !important
        }

        /* C) Inline style="font-size:…" attributes (document rows, status badge, history heading) */
        [style*="font-size:10px"] {
            font-size: 11.5px !important
        }

        [style*="font-size:11px"] {
            font-size: 12.5px !important
        }

        [style*="font-size:12.5px"] {
            font-size: 14px !important
        }

        [style*="font-size:13px"] {
            font-size: 14.5px !important
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

    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>EDIT APPLICATION</p>
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

    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($app['position_title']) ?></h2>
                <p><?= htmlspecialchars($app['employer_name']) ?><?= $app['worksite_country'] ? ' · ' . htmlspecialchars($app['worksite_country']) : '' ?></p>
            </div>
        </div>
    </div>

    <main class="main">

        <div class="detail-header">
            <a href="my-applications.php"
                class="inline-flex items-center gap-1.5 shrink-0 no-underline
           text-xs font-bold px-3 py-1.5 rounded-lg border
           bg-blue-50 border-blue-200 text-[var(--blue-lt)]
           hover:bg-blue-100
           dark:bg-white/5 dark:border-white/15 dark:text-blue-300
           dark:hover:bg-blue-500/15 dark:hover:border-blue-400/40
           transition-colors">
                <i class="fas fa-arrow-left"></i> All Applications
            </a>
            <div style="min-width:0;">
                <div class="detail-job-name"><?= htmlspecialchars($app['position_title']) ?></div>
                <div class="detail-job-meta"><?= htmlspecialchars($app['rsf_number']) ?> &middot; <?= htmlspecialchars($app['employer_name']) ?></div>
            </div>
        </div>

        <div class="page-alerts">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($banner_message): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($banner_message) ?></div>
            <?php endif; ?>
        </div>

        <!--  Two-part tabbed panel — same component used for a submitted
              application's detail view in my-applications.php  -->
        <div class="two-part-wrapper fade-in" id="editAppWrapper">

            <div class="part-label-bar">
                <div class="part-segment" id="seg-part1">
                    <div class="part-segment-name"><i class="fas fa-pen-to-square" style="font-size:10px;margin-right:4px"></i>Application Phase</div>
                </div>
                <div class="part-segment part-active" id="seg-part2">
                    <div class="part-segment-name"><i class="fas fa-chart-line" style="font-size:10px;margin-right:4px"></i>Status Tracking</div>
                </div>
            </div>

            <div class="app-tabs">
                <button class="atab done" id="tabbtn-rsf" onclick="switchEditTab('tabc-rsf', this, 'seg-part1')">
                    <i class="fas fa-file-lines"></i> RSF
                </button>
                <button class="atab done" id="tabbtn-q" onclick="switchEditTab('tabc-q', this, 'seg-part1')">
                    <i class="fas fa-edit"></i> Questions
                </button>
                <button class="atab done" id="tabbtn-req" onclick="switchEditTab('tabc-req', this, 'seg-part1')">
                    <i class="fas fa-file-arrow-up"></i> Requirements
                </button>
                <div class="atab-divider"></div>
                <button class="atab active current-stage" id="tabbtn-status" onclick="switchEditTab('tabc-status', this, 'seg-part2')">
                    <i class="fas fa-chart-line"></i> Status
                </button>
            </div>

            <div class="tab-body">

                <!-- TAB: RSF -->
                <div id="tabc-rsf" class="atab-content">
                    <dl class="info-grid">
                        <dt class="info-dt">Position</dt>
                        <dd class="info-dd"><?= htmlspecialchars($app['position_title']) ?></dd>
                        <dt class="info-dt">RSF Number</dt>
                        <dd class="info-dd"><?= htmlspecialchars($app['rsf_number']) ?></dd>
                        <dt class="info-dt">Employer</dt>
                        <dd class="info-dd"><?= htmlspecialchars($app['employer_name']) ?></dd>
                        <dt class="info-dt">Country</dt>
                        <dd class="info-dd"><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></dd>
                        <dt class="info-dt">Status</dt>
                        <dd class="info-dd"><span class="status-pill <?= $status_info['pill'] ?>"><?= htmlspecialchars($status_info['label']) ?></span></dd>
                        <dt class="info-dt">Last Updated</dt>
                        <dd class="info-dd"><?= date('F d, Y • g:i a', strtotime($app['updated_at'])) ?></dd>
                    </dl>
                </div>

                <!-- TAB: Questions -->
                <div id="tabc-q" class="atab-content">
                    <?php if ($questions === []): ?>
                        <p class="wiz-muted">No evaluation questions are configured for this job order.</p>
                    <?php else: ?>
                        <form method="POST" id="answers-form" class="js-app-edit-form" data-editable="<?= $editable ? '1' : '0' ?>">
                            <input type="hidden" name="action" value="answers">
                            <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                            <?php foreach ($questions as $idx => $q): ?>
                                <?php
                                $qid         = (int) $q['id'];
                                $label       = trim((string) $q['question_text']);
                                $req         = !empty($q['is_required']);
                                $useTextarea = strlen($label) > 160;
                                $prev        = htmlspecialchars($answers_map[$qid] ?? '');
                                ?>
                                <div class="wiz-field">
                                    <label class="wiz-label" for="q_<?= $qid ?>">
                                        <?= ($idx + 1) ?>. <?= htmlspecialchars($label) ?>
                                        <?php if ($req): ?><span class="wiz-req">*</span><?php endif; ?>
                                    </label>
                                    <?php if ($useTextarea): ?>
                                        <textarea class="wiz-input" id="q_<?= $qid ?>" name="q_<?= $qid ?>" rows="4"
                                            <?= $req && $editable ? 'required' : '' ?>
                                            <?= $readonly_attr ?>><?= $prev ?></textarea>
                                    <?php else: ?>
                                        <input class="wiz-input" type="text" id="q_<?= $qid ?>" name="q_<?= $qid ?>"
                                            value="<?= $prev ?>"
                                            <?= $req && $editable ? 'required' : '' ?>
                                            <?= $readonly_attr ?>>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary" <?= $disabled_attr ?>>
                                <i class="fas fa-floppy-disk"></i> Save Answers
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- TAB: Requirements -->
                <div id="tabc-req" class="atab-content">
                    <p class="wiz-muted">Upload one file per required document (PDF, JPG, or PNG). Re-uploading a slot replaces the existing file.</p>

                    <div id="requiredDocsList">
                        <?php foreach ($required_docs as $doc):
                            $docType_i = (string) ($doc['document_type'] ?? '');
                            $reqId_i   = (int)    ($doc['id'] ?? 0);
                            $uploaded_i = null;
                            if ($reqId_i > 0 && isset($uploaded_by_req_id[$reqId_i])) {
                                $uploaded_i = $uploaded_by_req_id[$reqId_i];
                            } elseif (isset($uploaded_by_doc_type[$docType_i])) {
                                $uploaded_i = $uploaded_by_doc_type[$docType_i];
                            }
                            echo edit_render_doc_row($doc, $uploaded_i, $docCommentsByDocumentId, $editable, $app_id);
                        endforeach; ?>
                    </div>

                    <?php
                    // Show any extra uploaded docs that don't match a required slot
                    $extra_docs = [];
                    foreach ($docs as $d) {
                        $rid = (int)($d['required_doc_id'] ?? 0);
                        $matched = false;
                        foreach ($required_docs as $rd) {
                            if ($rid > 0 && $rid === (int)$rd['id']) {
                                $matched = true;
                                break;
                            }
                            if ($rid === 0 && $d['document_type'] === $rd['document_type']) {
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched) $extra_docs[] = $d;
                    }
                    ?>
                    <div id="extraDocsSection" style="<?= $extra_docs === [] ? 'display:none;' : '' ?>margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
                        <p class="wiz-muted" style="font-weight:700;color:var(--text2);">Other uploaded files</p>
                        <?php foreach ($extra_docs as $d):
                            $exExt = strtolower(pathinfo((string)($d['file_path'] ?: $d['file_name']), PATHINFO_EXTENSION));
                            $exComments = $docCommentsByDocumentId[(int)($d['id'] ?? 0)] ?? []; ?>
                            <div id="extra-doc-<?= (int)$d['id'] ?>" data-extra-doc-row>
                                <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($d['file_name']) ?></div>
                                        <div style="font-size:11px;color:var(--text3);"><?= htmlspecialchars($d['document_type']) ?></div>
                                    </div>
                                    <?php if (!empty($d['file_path'])): ?>
                                        <button type="button" class="btn btn-sm wiz-preview-btn" style="background:var(--surface);border:1px solid var(--border);color:var(--blue-lt);"
                                            data-preview-src="../<?= htmlspecialchars($d['file_path']) ?>"
                                            data-preview-name="<?= htmlspecialchars($d['file_name']) ?>"
                                            data-preview-ext="<?= htmlspecialchars($exExt) ?>">
                                            <i class="fas fa-eye"></i> Preview
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($editable): ?>
                                        <form method="POST" class="delete-doc-form">
                                            <input type="hidden" name="action" value="delete_doc">
                                            <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                                            <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove this document?">
                                                <i class="fas fa-trash-can"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($exComments)): ?>
                                    <div class="doc-comments" style="margin-top:-4px;margin-bottom:10px;">
                                        <div class="doc-comments-hd"><i class="fas fa-comment-dots"></i> Reviewer comments</div>
                                        <?php foreach ($exComments as $c): ?>
                                            <div class="doc-comment-item">
                                                <div class="doc-comment-meta">
                                                    <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?>
                                                    &middot; <?= date('F d, Y g:i a', strtotime($c['created_at'])) ?>
                                                </div>
                                                <div class="doc-comment-text"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- TAB: Status -->
                <div id="tabc-status" class="atab-content active">

                    <div class="status-current-badge" style="background:<?= $status_info['color'] ?>1a;color:<?= $status_info['color'] ?>;border:1.5px solid <?= $status_info['color'] ?>40;">
                        <i class="fas <?= $status_info['icon'] ?>" style="font-size:18px;"></i>
                        <div>
                            <div style="font-size:13px;font-weight:800;"><?= htmlspecialchars($status_info['label']) ?></div>
                            <div style="font-size:11px;font-weight:600;opacity:.75;">Last updated: <?= date('F d, Y • g:i a', strtotime($app['updated_at'])) ?> &middot; <?= htmlspecialchars(edit_relative_time($app['updated_at'])) ?></div>
                        </div>
                    </div>

                    <?php if ($status === 'disapproved'): ?>
                        <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);border-radius:12px;padding:14px 16px;margin-bottom:18px;">
                            <div style="font-size:13px;font-weight:800;color:var(--red);margin-bottom:4px;"><i class="fas fa-circle-xmark"></i> Application Disapproved</div>
                            <div style="font-size:12.5px;color:var(--text1);">Your application has been reviewed and disapproved. Please check the evaluator notes below for details.</div>
                        </div>
                    <?php else: ?>
                        <div class="progress-steps" style="margin-bottom:20px;">
                            <?php foreach ($statusOrder as $si_key => $sval):
                                $slabel = $statusLabels[$si_key];
                                $sicon  = $statusIcons[$si_key];
                                $isDone = $curIdx !== false && $si_key < $curIdx;
                                $isCur  = $sval === $cur;
                                $dotClass = $isDone ? 'done' : ($isCur ? 'current' : '');
                                if ($isCur && $cur === 'for_compliance') $dotClass = 'warn';
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

                    <?php if ($status === 'for_compliance'): ?>
                        <div class="action-required-card">
                            <div class="action-required-head">
                                <i class="fas fa-triangle-exclamation"></i> Action Required
                            </div>
                            <p class="action-required-desc">An evaluator has requested changes to your application. Review the notes below, update your answers or documents using the Questions / Requirements tabs, then resubmit.</p>
                        </div>
                    <?php endif; ?>

                    <?php if ($show_evaluator_notes && $notes_display !== ''): ?>
                        <div class="eval-note-box">
                            <div class="eval-note-label"><i class="fas fa-comment-dots"></i> Evaluator Notes</div>
                            <div class="eval-note-text"><?= nl2br(htmlspecialchars($notes_display)) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($show_resubmit): ?>
                        <?php if ($compliance_pending_changes): ?>
                            <div class="action-required-card" id="resubmit-update-needed" style="border-color:rgba(220,38,38,.35);background:rgba(220,38,38,.08);">
                                <div class="action-required-head" style="color:var(--red);">
                                    <i class="fas fa-circle-exclamation"></i> Update Needed Before Resubmitting
                                </div>
                                <p class="action-required-desc">You haven't made any changes yet. Update the required answers or documents based on the evaluator's comments above — Resubmit stays disabled until something changes.</p>
                            </div>
                        <?php endif; ?>
                        <form method="POST" id="resubmit-form" style="margin-bottom:18px;">
                            <input type="hidden" name="action" value="resubmit">
                            <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                            <button type="button" class="btn btn-amber" id="resubmit-btn" style="display:inline-flex;" <?= $compliance_pending_changes ? 'disabled title="Update your answers or documents first"' : '' ?>>
                                <i class="fas fa-rotate-right"></i> Resubmit Application
                            </button>
                        </form>
                    <?php endif; ?>

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
                                        default => ''
                                    }; ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot <?= $dotC ?>"></div>
                                        <div class="timeline-to"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['to_status'] ?? '—'))) ?></div>
                                        <?php if (!empty($sh['reason'])): ?><div class="timeline-reason"><?= htmlspecialchars($sh['reason']) ?></div><?php endif; ?>
                                        <div class="timeline-date"><?= date('F d, Y • g:i a', strtotime($sh['changed_at'])) ?> &middot; <?= htmlspecialchars(edit_relative_time($sh['changed_at'])) ?></div>
                                    </div>

                                <?php else:
                                    $c = $ev['data'];
                                    $doc_label = ucwords(str_replace('_', ' ', $ev['doc_type'] ?? 'Document')); ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot" style="background:var(--amber);box-shadow:0 0 0 2px rgba(245,158,11,.35);"></div>
                                        <div class="timeline-to"><i class="fas fa-comment-dots" style="color:var(--amber);margin-right:5px;"></i>Comment on <?= htmlspecialchars($doc_label) ?></div>
                                        <div class="timeline-reason">"<?= htmlspecialchars($c['comment']) ?>" &mdash; <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?></div>
                                        <div class="timeline-date"><?= date('F d, Y • g:i a', strtotime($c['created_at'])) ?> &middot; <?= htmlspecialchars(edit_relative_time($c['created_at'])) ?></div>
                                    </div>
                                <?php endif; ?>

                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="wiz-muted">No status changes recorded yet.</p>
                    <?php endif; ?>

                </div><!-- /tabc-status -->

            </div><!-- /tab-body -->
        </div><!-- /two-part-wrapper -->

    </main>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <a href="jobs.php" class="dk">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>
            <div class="dk-div"></div>
            <a href="my-applications.php" class="dk active">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?>
                    <span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span>
                <?php endif; ?>
            </a>
            <!--<a href="notifications.php" class="dk">
                <i class="fas fa-bell"></i>
                <span class="dk-lbl">Alerts</span>
            </a>-->
            <div class="dk-div"></div>
            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>
            <div class="dk" onclick="logout('Applicant')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <!-- Document preview modal (same component as my-applications.php) -->
    <div id="wizPreviewOverlay" class="wiz-preview-overlay" onclick="if(event.target===this) closeDocPreview()">
        <div class="wiz-preview-modal" role="dialog" aria-modal="true" aria-labelledby="wizPreviewName">
            <div class="wiz-preview-head">
                <span id="wizPreviewName" class="wiz-preview-name"><i class="fas fa-file-lines"></i> Preview</span>
                <a id="wizPreviewOpenNew" href="#" target="_blank" rel="noopener" class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--blue-lt);"><i class="fas fa-external-link-alt"></i> Open</a>
                <button type="button" class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--text2);" onclick="closeDocPreview()" title="Close"><i class="fas fa-xmark"></i></button>
            </div>
            <div id="wizPreviewBody" class="wiz-preview-body"></div>
        </div>
    </div>

    <script>
        /* ── Live date + time ── */
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

        /* ── Tab switching (same pattern as my-applications.php's switchAppTab) ── */
        function switchEditTab(contentId, btn, partSegId) {
            const wrapper = document.getElementById('editAppWrapper');
            if (!wrapper) return;
            wrapper.querySelectorAll('.atab-content').forEach(c => c.classList.remove('active'));
            wrapper.querySelectorAll('.atab').forEach(t => t.classList.remove('active'));
            wrapper.querySelectorAll('.part-segment').forEach(s => s.classList.remove('part-active'));
            const el = document.getElementById(contentId);
            if (el) el.classList.add('active');
            if (btn) btn.classList.add('active');
            const seg = document.getElementById(partSegId);
            if (seg) seg.classList.add('part-active');
        }

        <?php if ($initial_tab !== null): ?>
                (function() {
                    const [contentId, btnId, segId] = <?= json_encode($initial_tab) ?>;
                    switchEditTab(contentId, document.getElementById(btnId), segId);

                    if (contentId === 'tabc-req') {
                        const firstCommented = document.querySelector('#requiredDocsList .doc-comments');
                        const target = firstCommented ? firstCommented.closest('.wiz-upload-row') : null;
                        if (target) {
                            target.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                            target.style.transition = 'box-shadow .4s';
                            target.style.boxShadow = '0 0 0 3px rgba(245,158,11,.45)';
                            setTimeout(() => {
                                target.style.boxShadow = '';
                            }, 2200);
                        }
                    }
                })();
        <?php endif; ?>
            // Disable all inputs in non-editable forms
            (function() {
                document.querySelectorAll('.js-app-edit-form[data-editable="0"]').forEach(function(form) {
                    form.querySelectorAll('input, select, textarea, button').forEach(function(el) {
                        if (el.type === 'hidden') return;
                        el.disabled = true;
                    });
                });
            })();

        /* ── Toast helper (SweetAlert2), used for document upload/replace/remove ── */
        function showToast(message, icon) {
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
                icon: icon || 'success',
                title: message
            });
        }

        /* ── Swap in the freshly rendered row returned by the server ── */
        function replaceDocRow(data) {
            if (!data.html) return;
            const reqId = String(data.required_doc_id ?? 0);
            let row = null;
            if (reqId !== '0') {
                row = document.querySelector('.wiz-upload-row[data-required-doc-id="' + reqId + '"]');
            } else {
                row = document.querySelector('.wiz-upload-row[data-required-doc-id="0"][data-doc-type="' + (data.document_type || '') + '"]');
            }
            if (!row) return;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = data.html.trim();
            const newRow = wrapper.firstElementChild;
            if (newRow) row.replaceWith(newRow);
        }

        function hideExtraDocsSectionIfEmpty() {
            const section = document.getElementById('extraDocsSection');
            if (!section) return;
            if (section.querySelectorAll('[data-extra-doc-row]').length === 0) {
                section.style.display = 'none';
            }
        }

        /* ── Any successful document upload/replace/removal counts as a
              change for compliance purposes (server sets updated_at = NOW()
              on every one of these actions). Reflect that immediately in the
              Resubmit button so the applicant doesn't need to refresh. ── */
        function markComplianceChangeMade() {
            const btn = document.getElementById('resubmit-btn');
            if (btn) {
                btn.disabled = false;
                btn.removeAttribute('title');
            }
            const warn = document.getElementById('resubmit-update-needed');
            if (warn) warn.remove();
        }

        /* ── Upload / replace a required document without reloading the page ── */
        function handleDocUpload(form) {
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalHtml = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            }

            const formData = new FormData(form);
            fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        replaceDocRow(data);
                        markComplianceChangeMade();
                        showToast(data.message || 'Document uploaded successfully.', 'success');
                    } else {
                        showToast(data.message || 'Upload failed. Please try again.', 'error');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalHtml;
                        }
                    }
                })
                .catch(() => {
                    showToast('Upload failed. Please try again.', 'error');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalHtml;
                    }
                });
        }

        /* ── Remove a document without reloading the page ── */
        function handleDocDelete(form) {
            const formData = new FormData(form);
            fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (data.extra) {
                            const el = document.getElementById('extra-doc-' + data.doc_id);
                            if (el) el.remove();
                            hideExtraDocsSectionIfEmpty();
                        } else {
                            replaceDocRow(data);
                        }
                        markComplianceChangeMade();
                        showToast(data.message || 'Document removed.', 'success');
                    } else {
                        showToast(data.message || 'Could not remove document.', 'error');
                    }
                })
                .catch(() => showToast('Could not remove document. Please try again.', 'error'));
        }

        /* ── Delegated submit handling so dynamically-swapped rows keep working ── */
        document.addEventListener('submit', function(e) {
            const uploadForm = e.target.closest('.js-doc-upload-form');
            if (uploadForm) {
                e.preventDefault();
                handleDocUpload(uploadForm);
                return;
            }

            const deleteForm = e.target.closest('.delete-doc-form');
            if (deleteForm) {
                e.preventDefault();
                const btn = deleteForm.querySelector('button[data-confirm]');
                const msg = btn ? btn.getAttribute('data-confirm') : 'Remove this document?';
                Swal.fire({
                    title: msg,
                    text: 'This cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, remove',
                    cancelButtonText: 'Cancel'
                }).then(function(r) {
                    if (r.isConfirmed) handleDocDelete(deleteForm);
                });
            }
        });

        // Resubmit confirmation
        var rsBtn = document.getElementById('resubmit-btn');
        if (rsBtn) {
            rsBtn.addEventListener('click', function() {
                Swal.fire({
                    title: 'Resubmit application?',
                    text: 'Your status will change to Resubmitted and an evaluator will review your updates.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#d97706',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, resubmit',
                    cancelButtonText: 'Cancel'
                }).then(function(r) {
                    if (r.isConfirmed) document.getElementById('resubmit-form').submit();
                });
            });
        }

        /* ── Document preview modal (same JS as my-applications.php) ── */
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
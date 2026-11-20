<?php
// evaluator/request-reassignment.php – evaluator asks the admin to hand over their open cases
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];
$evaluator_id    = null;
$evaluator_name  = 'Evaluator';
$stmt_ev = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$row = $stmt_ev->get_result()->fetch_assoc();
$stmt_ev->close();
if (!$row) {
    header('Location: ../login.php');
    exit;
}
$evaluator_id   = (int) $row['eid'];
$evaluator_name = $row['full_name'] ?? 'Evaluator';

if (empty($_SESSION['reassign_csrf'])) {
    $_SESSION['reassign_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['reassign_csrf'];

$error = '';

// ── POST handlers (Post/Redirect/Get) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif ($action === 'submit_request') {
        $scope  = $_POST['scope'] ?? '';
        $reason = $_POST['reason_category'] ?? '';
        $ret    = trim($_POST['expected_return'] ?? '');
        $ids    = array_map('intval', (array) ($_POST['case_ids'] ?? []));

        $reason_other = trim((string) ($_POST['reason_other'] ?? ''));
        if ($reason === 'other') {
            if ($reason_other === '') {
                $error = 'Please specify your reason.';
            } elseif (mb_strlen($reason_other) > 255) {
                $error = 'Your reason must be 255 characters or fewer.';
            }
        } else {
            $reason_other = '';
        }

        $expected_return = null;
        if ($ret !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $ret);
            if (!$d || $d->format('Y-m-d') !== $ret) {
                $error = 'Please enter a valid expected return date.';
            } elseif ($ret < date('Y-m-d')) {
                $error = 'The expected return date cannot be in the past.';
            } else {
                $expected_return = $ret;
            }
        }

        if ($error === '') {
            $res = createReassignmentRequest($database, $evaluator_id, $scope, $reason, $expected_return, $ids, $reason_other);
            if ($res['ok']) {
                $label = EVALUATOR_LEAVE_REASONS[$reason];
                if ($reason_other !== '') {
                    $label .= ' — ' . $reason_other;
                }
                notify_admins(
                    $database,
                    'Reassignment Requested',
                    "{$evaluator_name} requested reassignment of {$res['count']} application(s). Reason: {$label}.",
                    'reassignment_request'
                );
                header('Location: request-reassignment.php?msg=sent');
                exit;
            }
            $error = match ($res['reason'] ?? '') {
                'pending_exists' => 'You already have a pending request. Cancel it first to submit a new one.',
                'no_cases'       => 'Please select at least one of your open applications.',
                'invalid'        => 'Please complete the form with valid values.',
                default          => 'Could not submit your request. Please try again.',
            };
        }
    } elseif ($action === 'cancel_request') {
        $rid = (int) ($_POST['request_id'] ?? 0);
        if (cancelReassignmentRequest($database, $rid, $evaluator_id)) {
            header('Location: request-reassignment.php?msg=cancelled');
            exit;
        }
        $error = 'That request can no longer be cancelled.';
    } elseif ($action === 'end_leave') {
        if (endActiveLeave($database, $evaluator_id, $evaluator_email)) {
            notify_admins(
                $database,
                'Evaluator Available Again',
                "{$evaluator_name} has ended their leave and can receive applications again.",
                'evaluator_availability'
            );
            header('Location: request-reassignment.php?msg=back');
            exit;
        }
        $error = 'You do not have an active leave to end.';
    } else {
        $error = 'Unknown action.';
    }
}

$flash = match ($_GET['msg'] ?? '') {
    'sent'      => 'Request sent. An admin will review it and you will be notified.',
    'cancelled' => 'Your request was cancelled.',
    'back'      => 'Welcome back. You can receive new applications again.',
    default     => '',
};

$active_leave    = getActiveLeave($database, $evaluator_id);
$pending_request = getPendingReassignmentRequest($database, $evaluator_id);
$open_cases      = getOpenCasesForEvaluator($database, $evaluator_id);
// Specified "Other" reason for the pending request (if any)
$pending_other = '';
if ($pending_request) {
    $pid = (int) $pending_request['id'];
    $st = $database->prepare("SELECT reason_other FROM reassignment_requests WHERE id = ? AND evaluator_id = ?");
    $st->bind_param("ii", $pid, $evaluator_id);
    $st->execute();
    $pending_other = (string) ($st->get_result()->fetch_assoc()['reason_other'] ?? '');
    $st->close();
}

// Latest admin decisions (approved / rejected) with the admin's reason
$decisions = [];
$st = $database->prepare("
    SELECT rr.id, rr.status, rr.reason_category, rr.reason_other, rr.admin_comment, rr.reviewed_at,
           COALESCE(ad.full_name, 'Admin') AS reviewer_name,
           (SELECT COUNT(*) FROM reassignment_request_items i WHERE i.request_id = rr.id) AS item_count,
           (SELECT COUNT(*) FROM reassignment_request_items i
             WHERE i.request_id = rr.id AND i.outcome = 'reassigned') AS reassigned_count
    FROM reassignment_requests rr
    LEFT JOIN admins ad ON ad.email = rr.reviewed_by
    WHERE rr.evaluator_id = ? AND rr.status IN ('approved','rejected')
    ORDER BY rr.reviewed_at DESC, rr.id DESC
    LIMIT 3
");
$st->bind_param("i", $evaluator_id);
$st->execute();
$decisions = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Request Reassignment – Evaluator Portal</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        .field {
            width: 100%;
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text1);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
        }

        .field:focus {
            outline: none;
            border-color: var(--navy);
        }

        .lbl {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 6px;
        }
    </style>
    <script>
        (function() {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('dmw_theme') || 'light');
        })();
    </script>
</head>

<body class="bg-[var(--bg,#f4f6fb)] font-['DM_Sans',sans-serif]">

    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; EVALUATOR PORTAL</p>
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

    <div class="subbar">
        <div class="subbar-top flex items-center justify-between gap-3 flex-wrap">
            <div class="subbar-greeting">
                <h2>Request Reassignment</h2>
                <p>Ask the admin to hand your open applications to another evaluator</p>
            </div>
            <a href="index.php" class="filter-pill flex-shrink-0" style="text-decoration:none">
                <i class="fas fa-house" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Back to Dashboard</span>
            </a>
        </div>
    </div>

    <main class="max-w-[760px] mx-auto px-3 sm:px-4 pt-4 pb-[calc(var(--dock-h)_+_28px)] space-y-4">

        <?php if ($flash): ?>
            <div class="flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                <i class="fas fa-circle-check"></i> <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="flex items-center gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php foreach ($decisions as $d):
            $ok = $d['status'] === 'approved';
            $reasonLabel = EVALUATOR_LEAVE_REASONS[$d['reason_category']] ?? '';
            if ($d['reason_category'] === 'other' && !empty($d['reason_other'])) {
                $reasonLabel .= ' — ' . $d['reason_other'];
            }
        ?>
            <div class="rounded-2xl border p-4 <?= $ok ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' ?>">
                <div class="flex items-start gap-3">
                    <i class="fas <?= $ok ? 'fa-circle-check text-emerald-600' : 'fa-circle-xmark text-red-600' ?> mt-0.5"></i>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-extrabold text-slate-800">
                            Request <?= $ok ? 'approved' : 'rejected' ?>
                            <span class="font-medium text-slate-500">· <?= date('M d, Y g:i a', strtotime($d['reviewed_at'])) ?></span>
                        </div>
                        <p class="text-[12px] text-slate-600 mt-0.5">
                            <?= (int) $d['item_count'] ?> application(s)
                            <?= $ok ? '· ' . (int) $d['reassigned_count'] . ' reassigned' : '' ?>
                            · <?= htmlspecialchars($reasonLabel) ?>
                        </p>
                        <p class="text-[12.5px] text-slate-800 mt-2">
                            <b><?= htmlspecialchars($d['reviewer_name']) ?>'s reason:</b>
                            <?= !empty($d['admin_comment'])
                                ? nl2br(htmlspecialchars($d['admin_comment']))
                                : '<span class="text-slate-500">No reason provided.</span>' ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($active_leave): ?>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex items-start gap-3">
                    <i class="fas fa-user-clock text-amber-600 mt-0.5"></i>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-extrabold text-slate-800">You are marked unavailable</div>
                        <p class="text-[12px] text-slate-600 mt-0.5">
                            Since <?= date('M d, Y', strtotime($active_leave['start_at'])) ?>
                            · <?= htmlspecialchars(EVALUATOR_LEAVE_REASONS[$active_leave['reason_category']] ?? 'Leave') ?>
                            <?= $active_leave['end_at'] ? ' · until ' . date('M d, Y', strtotime($active_leave['end_at'])) : '' ?>.
                            You will not receive or claim new applications.
                        </p>
                    </div>
                </div>
                <form method="POST" class="mt-3" id="endLeaveForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="end_leave">
                    <button type="button" onclick="confirmEndLeave()" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 text-xs font-semibold">
                        <i class="fas fa-rotate-left"></i> I'm back, make me available
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($pending_request): ?>
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4">
                <div class="flex items-start gap-3">
                    <i class="fas fa-hourglass-half text-blue-500 mt-0.5"></i>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-extrabold text-[var(--text1)]">Request pending admin approval</div>
                        <p class="text-[12px] text-[var(--text3)] mt-0.5">
                            Sent <?= date('M d, Y g:i a', strtotime($pending_request['requested_at'])) ?>
                            · <?= (int) $pending_request['item_count'] ?> application(s)
                            · <?= htmlspecialchars(EVALUATOR_LEAVE_REASONS[$pending_request['reason_category']] ?? '') ?><?= !empty($pending_request['reason_other']) ? ' — ' . htmlspecialchars($pending_request['reason_other']) : '' ?><?= $pending_other !== '' ? ' — ' . htmlspecialchars($pending_other) : '' ?>
                            <?= $pending_request['expected_return'] ? ' · expected back ' . date('M d, Y', strtotime($pending_request['expected_return'])) : '' ?>
                        </p>
                    </div>
                </div>
                <form method="POST" class="mt-3" id="cancelReqForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="cancel_request">
                    <input type="hidden" name="request_id" value="<?= (int) $pending_request['id'] ?>">
                    <button type="button" onclick="confirmCancel()" class="rounded-lg border border-[var(--border)] px-4 py-2 text-xs font-semibold text-[var(--text2)] hover:bg-[var(--surface)]">
                        <i class="fas fa-xmark"></i> Cancel request
                    </button>
                </form>
            </div>
        <?php else: ?>
            <form method="POST" id="requestForm" class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5 space-y-5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="submit_request">

                <div>
                    <span class="lbl">Which applications?</span>
                    <label class="flex items-start gap-2.5 text-[13px] text-[var(--text1)] mb-2 cursor-pointer">
                        <input type="radio" name="scope" value="all" checked class="mt-1">
                        <span><b>All my open applications</b> (<?= count($open_cases) ?>)<br>
                            <span class="text-[11.5px] text-[var(--text3)]">Also marks you unavailable once the admin approves.</span></span>
                    </label>
                    <label class="flex items-start gap-2.5 text-[13px] text-[var(--text1)] cursor-pointer">
                        <input type="radio" name="scope" value="selected" class="mt-1" <?= $open_cases ? '' : 'disabled' ?>>
                        <span><b>Only selected applications</b></span>
                    </label>

                    <div id="caseList" class="hidden mt-3 rounded-xl border border-[var(--border)] divide-y divide-[var(--border)] max-h-[260px] overflow-y-auto">
                        <?php foreach ($open_cases as $c): ?>
                            <label class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-[var(--surface)]">
                                <input type="checkbox" name="case_ids[]" value="<?= (int) $c['id'] ?>">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[12.5px] font-semibold text-[var(--text1)] truncate"><?= htmlspecialchars($c['applicant_name'] ?? '—') ?></span>
                                    <span class="block text-[11px] text-[var(--text3)] truncate">
                                        <?= htmlspecialchars($c['rsf_number'] ?? '—') ?> · <?= htmlspecialchars($c['position_title'] ?? '—') ?>
                                    </span>
                                </span>
                                <span class="text-[10px] font-bold uppercase text-[var(--text3)] shrink-0"><?= htmlspecialchars(str_replace('_', ' ', $c['status'])) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="lbl" for="reason_category">Reason</label>
                        <select name="reason_category" id="reason_category" class="field" required>
                            <?php foreach (EVALUATOR_LEAVE_REASONS as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[11px] text-[var(--text3)] mt-1">Only the category is recorded. No personal details are needed.</p>
                    </div>
                    <div>
                        <label class="lbl" for="expected_return">Expected return (optional)</label>
                        <input type="date" name="expected_return" id="expected_return" class="field" min="<?= date('Y-m-d') ?>">
                        <p class="text-[11px] text-[var(--text3)] mt-1">Leave blank if you don't know yet.</p>
                    </div>
                </div>

                <div id="otherWrap" class="hidden">
                    <label class="lbl" for="reason_other">Please specify your reason</label>
                    <input type="text" name="reason_other" id="reason_other" class="field" maxlength="255"
                        placeholder="Briefly state the exact reason">
                    <p class="text-[11px] text-[var(--text3)] mt-1">Required when "Other" is selected. Max 255 characters.</p>
                </div>

                <button type="button" onclick="confirmSubmit()" class="w-full rounded-xl bg-[var(--navy)] hover:opacity-90 text-white font-bold text-[13px] py-3">
                    <i class="fas fa-paper-plane"></i> Send request to Admin
                </button>
            </form>
        <?php endif; ?>
    </main>

    <div class="dock-wrap">
        <nav class="dock">
            <a href="index.php" class="dk"><i class="fas fa-house"></i><span class="dk-lbl">Home</span></a>
            <a href="applications.php" class="dk"><i class="fas fa-list"></i><span class="dk-lbl">Tasks</span></a>
            <div class="dk-div"></div>
            <a href="evaluator-reports.php" class="dk" data-tip="Applicant Reports"><i class="fas fa-file-contract"></i><span class="dk-lbl">Applicants</span></a>
            <a href="reports.php" class="dk"><i class="fas fa-chart-bar"></i><span class="dk-lbl">Reports</span></a>
            <div class="dk-div"></div>
            <a href="settings.php" class="dk"><i class="fas fa-gear"></i><span class="dk-lbl">Settings</span></a>
            <div class="dk" onclick="logout('Evaluator')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <script>
        function updateLiveDateTime() {
            const el = document.getElementById('live-date');
            if (!el) return;
            const now = new Date();
            const d = now.toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();
            const t = now.toLocaleTimeString('en-PH', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            }).toLowerCase();
            el.textContent = `${d} | ${t}`;
        }
        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000);

        const form = document.getElementById('requestForm');
        const caseList = document.getElementById('caseList');

        if (form) {
            form.querySelectorAll('input[name="scope"]').forEach(r =>
                r.addEventListener('change', () => {
                    caseList.classList.toggle('hidden', form.scope.value !== 'selected');
                })
            );
        }

        const reasonSel = document.getElementById('reason_category');
        const otherWrap = document.getElementById('otherWrap');
        const otherInput = document.getElementById('reason_other');
        if (reasonSel && otherWrap && otherInput) {
            const syncOther = () => {
                const isOther = reasonSel.value === 'other';
                otherWrap.classList.toggle('hidden', !isOther);
                otherInput.required = isOther;
                if (!isOther) otherInput.value = '';
            };
            reasonSel.addEventListener('change', syncOther);
            syncOther();
        }

        function confirmSubmit() {
            if (reasonSel.value === 'other' && !otherInput.value.trim()) {
                Swal.fire({
                    title: 'Specify your reason',
                    text: 'Please type the exact reason for your leave.',
                    icon: 'warning',
                    confirmButtonColor: '#dc2626'
                });
                otherInput.focus();
                return;
            }
            const scope = form.scope.value;
            if (scope === 'selected' && !form.querySelectorAll('input[name="case_ids[]"]:checked').length) {
                Swal.fire({
                    title: 'Select applications',
                    text: 'Please tick at least one application to hand over.',
                    icon: 'warning',
                    confirmButtonColor: '#dc2626'
                });
                return;
            }
            const text = scope === 'all' ?
                'All your open applications will be sent for reassignment, and you will be marked unavailable once the admin approves.' :
                'The selected applications will be sent for reassignment.';
            Swal.fire({
                title: 'Send this request?',
                text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, send',
                confirmButtonColor: '#1e40af',
                cancelButtonColor: '#94a3b8'
            }).then(r => {
                if (r.isConfirmed) form.submit();
            });
        }

        function confirmCancel() {
            Swal.fire({
                title: 'Cancel your request?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, cancel it',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#94a3b8'
            }).then(r => {
                if (r.isConfirmed) document.getElementById('cancelReqForm').submit();
            });
        }

        function confirmEndLeave() {
            Swal.fire({
                title: 'Make yourself available again?',
                text: 'You will be able to claim and receive new applications.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: "Yes, I'm back",
                confirmButtonColor: '#059669',
                cancelButtonColor: '#94a3b8'
            }).then(r => {
                if (r.isConfirmed) document.getElementById('endLeaveForm').submit();
            });
        }
    </script>
    <script type="module" src="../js/main.js"></script>
    <script>
        window.DMW_USER_EMAIL = <?= json_encode($evaluator_email) ?>;
        window.DMW_NOTIF_API = '../api/poll_notifications.php';
        window.DMW_APP_VIEW_URL = 'view-application.php?id=';
    </script>
    <script src="../js/live-notifications.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
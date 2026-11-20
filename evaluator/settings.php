<?php
// evaluator/settings.php – Evaluator settings (profile, security, leave/reassignment, account)
session_start();
require_once '../db.php';
require_once '../auth.php';
require_once '../includes/app-helpers.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

/* LOAD EVALUATOR PROFILE */

$evaluator_name  = 'Evaluator';
$evaluator_id    = null;
$evaluator_phone = '';
$evaluator_since = null;

$stmt_ev = $database->prepare("SELECT eid, full_name, phone, created_at FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();
if ($ev_result->num_rows === 1) {
    $row             = $ev_result->fetch_assoc();
    $evaluator_id    = (int) $row['eid'];
    $evaluator_name  = $row['full_name'] ?? 'Evaluator';
    $evaluator_phone = $row['phone'] ?? '';
    $evaluator_since = $row['created_at'] ?? null;
}
$stmt_ev->close();

/* CSRF (same session key as request-reassignment.php) */

if (empty($_SESSION['reassign_csrf'])) {
    $_SESSION['reassign_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['reassign_csrf'];

$is_post          = $_SERVER['REQUEST_METHOD'] === 'POST';
$post_action      = $is_post ? (string) ($_POST['action'] ?? '') : '';
$reassign_actions = ['submit_request', 'cancel_request', 'end_leave'];

/* LEAVE / REASSIGNMENT ACTIONS (Post/Redirect/Get) */

$reassign_error = '';

if (in_array($post_action, $reassign_actions, true)) {
    $back = fn(string $msg) => header("Location: settings.php?msg={$msg}#availability");

    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        $reassign_error = 'Your session expired. Please reload the page and try again.';
    } elseif (!$evaluator_id) {
        $reassign_error = 'Evaluator profile not found.';
    } elseif ($post_action === 'submit_request') {
        $scope        = $_POST['scope'] ?? '';
        $reason       = $_POST['reason_category'] ?? '';
        $ret          = trim($_POST['expected_return'] ?? '');
        $ids          = array_map('intval', (array) ($_POST['case_ids'] ?? []));
        $reason_other = trim((string) ($_POST['reason_other'] ?? ''));

        if ($reason === 'other') {
            if ($reason_other === '') {
                $reassign_error = 'Please specify your reason.';
            } elseif (mb_strlen($reason_other) > 255) {
                $reassign_error = 'Your reason must be 255 characters or fewer.';
            }
        } else {
            $reason_other = '';
        }

        $expected_return = null;
        if ($ret !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $ret);
            if (!$d || $d->format('Y-m-d') !== $ret) {
                $reassign_error = 'Please enter a valid expected return date.';
            } elseif ($ret < date('Y-m-d')) {
                $reassign_error = 'The expected return date cannot be in the past.';
            } else {
                $expected_return = $ret;
            }
        }

        if ($reassign_error === '') {
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
                $back('sent');
                exit;
            }
            $reassign_error = match ($res['reason'] ?? '') {
                'pending_exists' => 'You already have a pending request. Cancel it first to submit a new one.',
                'no_cases'       => 'Please select at least one of your open applications.',
                'invalid'        => 'Please complete the form with valid values.',
                default          => 'Could not submit your request. Please try again.',
            };
        }
    } elseif ($post_action === 'cancel_request') {
        if (cancelReassignmentRequest($database, (int) ($_POST['request_id'] ?? 0), $evaluator_id)) {
            $back('cancelled');
            exit;
        }
        $reassign_error = 'That request can no longer be cancelled.';
    } elseif ($post_action === 'end_leave') {
        if (endActiveLeave($database, $evaluator_id, $evaluator_email)) {
            notify_admins(
                $database,
                'Evaluator Available Again',
                "{$evaluator_name} has ended their leave and can receive applications again.",
                'evaluator_availability'
            );
            $back('back');
            exit;
        }
        $reassign_error = 'You do not have an active leave to end.';
    }
}

/* AJAX HANDLER (profile / password / deactivate) */

if ($is_post && $post_action !== '' && !in_array($post_action, $reassign_actions, true)) {
    header('Content-Type: application/json');

    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'Your session expired. Please reload the page and try again.']);
        exit;
    }
    $action = $post_action;

    // ── Update profile ──
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        if ($full_name === '') {
            echo json_encode(['ok' => false, 'error' => 'Full name is required.']);
            exit;
        }
        if (!preg_match('/^09\d{9}$/', $phone)) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid 11-digit phone number starting with 09.']);
            exit;
        }

        $stmt = $database->prepare("UPDATE evaluators SET full_name = ?, phone = ? WHERE email = ?");
        $stmt->bind_param("sss", $full_name, $phone, $evaluator_email);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            echo json_encode(['ok' => false, 'error' => 'Could not save changes. Please try again.']);
            exit;
        }

        $parts    = explode(' ', $full_name);
        $initials = count($parts) >= 2
            ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
            : strtoupper(substr($full_name, 0, 2));

        echo json_encode([
            'ok'        => true,
            'message'   => 'Profile updated.',
            'full_name' => $full_name,
            'phone'     => $phone,
            'initials'  => $initials,
        ]);
        exit;
    }

    // ── Change password ──
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            echo json_encode(['ok' => false, 'error' => 'Please fill in all password fields.']);
            exit;
        }
        if (
            strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new)
            || !preg_match('/\d/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)
        ) {
            echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number and a special character.']);
            exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['ok' => false, 'error' => 'New password and confirmation do not match.']);
            exit;
        }

        $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? AND usertype = 'evaluator' LIMIT 1");
        $stmt->bind_param("s", $evaluator_email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($current, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
            exit;
        }
        if (password_verify($new, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'New password must be different from your current password.']);
            exit;
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $database->prepare("UPDATE webuser SET password = ? WHERE email = ? AND usertype = 'evaluator'");
        $stmt->bind_param("ss", $hash, $evaluator_email);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode($ok
            ? ['ok' => true, 'message' => 'Password changed successfully.']
            : ['ok' => false, 'error' => 'Could not update password. Please try again.']);
        exit;
    }

    // ── Deactivate own account ──
    if ($action === 'deactivate_account') {
        $reason_preset = trim($_POST['reason_preset'] ?? '');
        $reason_other  = trim($_POST['reason_other'] ?? '');
        $password      = $_POST['password'] ?? '';

        $self_deactivation_reasons = [
            'Taking a break / temporary leave',
            'No longer serving as evaluator',
            'Privacy or security concern',
            'Other',
        ];

        if (!in_array($reason_preset, $self_deactivation_reasons, true)) {
            echo json_encode(['ok' => false, 'error' => 'Please select a valid reason.']);
            exit;
        }
        if ($reason_preset === 'Other' && mb_strlen($reason_other) < 5) {
            echo json_encode(['ok' => false, 'error' => 'Please specify a reason (at least 5 characters).']);
            exit;
        }
        if ($password === '') {
            echo json_encode(['ok' => false, 'error' => 'Please enter your password to confirm.']);
            exit;
        }

        $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? AND usertype = 'evaluator' LIMIT 1");
        $stmt->bind_param("s", $evaluator_email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($password, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'Incorrect password.']);
            exit;
        }

        if ($evaluator_id) {
            $stmt = $database->prepare("SELECT COUNT(*) AS c FROM assignments WHERE evaluator_id = ? AND status = 'active'");
            $stmt->bind_param("i", $evaluator_id);
            $stmt->execute();
            $active_assign = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            $stmt = $database->prepare("SELECT COUNT(*) AS c FROM applications WHERE current_evaluator_id = ? AND handling_party = 'evaluator'");
            $stmt->bind_param("i", $evaluator_id);
            $stmt->execute();
            $active_apps = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            if ($active_assign > 0 || $active_apps > 0) {
                echo json_encode(['ok' => false, 'error' => 'You have active assignments or applications. Complete them, or request reassignment in the "Leave & Reassignment" tab, before deactivating your account.']);
                exit;
            }
        }

        $final_reason = $reason_preset === 'Other' ? $reason_other : $reason_preset;

        $database->begin_transaction();
        try {
            $stmt = $database->prepare("UPDATE webuser
                SET is_active = 0, deactivated_at = NOW(), deactivated_by = NULL, deactivation_reason = ?, remember_token = NULL, remember_expires = NULL
                WHERE email = ?");
            $stmt->bind_param("ss", $final_reason, $evaluator_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("INSERT INTO account_status_history
                (email, usertype, action, reason, performed_by, previous_status, new_status)
                VALUES (?, 'evaluator', 'Account Deactivated', ?, ?, 'active', 'deactivated')");
            $stmt->bind_param("sss", $evaluator_email, $final_reason, $evaluator_email);
            $stmt->execute();
            $stmt->close();

            $admin_stmt = $database->prepare("SELECT email FROM admins");
            $admin_stmt->execute();
            $admins = $admin_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $admin_stmt->close();

            $notif_title = "Evaluator Self-Deactivated";
            $notif_msg   = htmlspecialchars($evaluator_name) . " (" . $evaluator_email . ") deactivated their own account. Reason: " . $final_reason;
            foreach ($admins as $adm) {
                $nstmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, 'admin', ?, ?, 'account_status')");
                $nstmt->bind_param("sss", $adm['email'], $notif_title, $notif_msg);
                $nstmt->execute();
                $nstmt->close();
            }

            $database->commit();
        } catch (\Throwable $e) {
            $database->rollback();
            echo json_encode(['ok' => false, 'error' => 'Failed to deactivate account. Please try again.']);
            exit;
        }

        echo json_encode(['ok' => true, 'message' => 'Your account has been deactivated. You will be logged out.']);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

/* PAGE DATA */

$my_assigned_count = 0;
$perf = ['evaluated_total' => 0, 'approved' => 0, 'disapproved' => 0, 'for_compliance' => 0, 'active_now' => 0];

$active_leave = $pending_request = null;
$open_cases   = $decisions = $recent_logins = [];
$pending_other = '';

if ($evaluator_id) {
    $stmt = $database->prepare("SELECT COUNT(*) AS cnt FROM applications WHERE current_evaluator_id = ? AND handling_party = 'evaluator'");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $my_assigned_count = $perf['active_now'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $database->prepare("SELECT decision, COUNT(*) AS cnt FROM evaluations WHERE evaluator_id = ? GROUP BY decision");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $cnt = (int) $r['cnt'];
        $perf['evaluated_total'] += $cnt;
        if (array_key_exists($r['decision'], $perf)) {
            $perf[$r['decision']] += $cnt;
        }
    }
    $stmt->close();

    $active_leave    = getActiveLeave($database, $evaluator_id);
    $pending_request = getPendingReassignmentRequest($database, $evaluator_id);
    $open_cases      = getOpenCasesForEvaluator($database, $evaluator_id);

    if ($pending_request) {
        $pid = (int) $pending_request['id'];
        $st  = $database->prepare("SELECT reason_other FROM reassignment_requests WHERE id = ? AND evaluator_id = ?");
        $st->bind_param("ii", $pid, $evaluator_id);
        $st->execute();
        $pending_other = (string) ($st->get_result()->fetch_assoc()['reason_other'] ?? '');
        $st->close();
    }

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
        LIMIT 20
    ");
    $st->bind_param("i", $evaluator_id);
    $st->execute();
    $decisions = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Own recent sign-in attempts (login_attempts: email, ip_address, user_agent, success, attempted_at)
$st = $database->prepare("
    SELECT success, ip_address, user_agent, attempted_at
    FROM login_attempts
    WHERE email = ?
    ORDER BY attempted_at DESC
    LIMIT 10
");
$st->bind_param("s", $evaluator_email);
$st->execute();
$recent_logins = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$flash = match ($_GET['msg'] ?? '') {
    'sent'      => 'Request sent. An admin will review it and you will be notified.',
    'cancelled' => 'Your request was cancelled.',
    'back'      => 'Welcome back. You can receive new applications again.',
    default     => '',
};
$initial_tab = ($reassign_error !== '' || $flash !== '') ? 'availability' : 'profile';

$member_since_label = $evaluator_since ? date('F j, Y', strtotime($evaluator_since)) : '—';

/* SHARED TAILWIND CLASS STRINGS (mirrors admin settings look) */

$k_card    = 'rounded-[14px] border border-slate-200 bg-white p-3.5 shadow-sm sm:p-5 dark:border-slate-700 dark:bg-slate-800 dark:shadow-none';
$k_label   = 'mb-1.5 block text-xs font-semibold text-slate-700 dark:text-slate-300';
$k_hint    = 'mt-1 text-[11px] text-slate-400 dark:text-slate-500';
$k_input   = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-[13px] text-slate-900 outline-none transition focus:border-blue-700 read-only:bg-slate-50 read-only:text-slate-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:read-only:bg-slate-800/60 dark:read-only:text-slate-500 dark:focus:border-blue-500 dark:[color-scheme:dark]';
$k_btn     = 'inline-flex items-center justify-center gap-2 rounded-lg px-[18px] py-2.5 text-xs font-bold transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60';
$k_primary = $k_btn . ' w-full bg-blue-700 text-white sm:w-auto dark:bg-blue-500';
$k_outline = $k_btn . ' w-full border border-slate-300 bg-white text-slate-700 sm:w-auto dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
$k_err     = 'hidden text-xs text-red-600 dark:text-red-400';
$k_ok      = 'flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
$k_bad     = 'flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300';
$k_info    = 'rounded-lg border border-blue-100 bg-sky-50 px-3 py-2 text-[11px] font-semibold text-blue-700 dark:border-blue-400/35 dark:bg-blue-500/10 dark:text-blue-400';
$k_th      = 'border-b-2 border-slate-100 px-2.5 py-2 text-left text-[10.5px] font-bold uppercase tracking-wide text-slate-400 dark:border-slate-700 dark:text-slate-500';
$k_td      = 'border-b border-slate-100 px-2.5 py-2.5 text-slate-700 dark:border-slate-700 dark:text-slate-300';
$k_toggle  = 'absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-xs leading-none text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300';
$k_nav     = 'flex w-full items-center gap-2.5 rounded-[9px] px-3 py-2.5 text-left text-[13px] font-semibold text-slate-600 transition data-[active=false]:hover:bg-slate-100 data-[active=true]:bg-blue-700 data-[active=true]:text-white dark:text-slate-400 dark:data-[active=false]:hover:bg-slate-700/60 dark:data-[active=true]:bg-blue-500';
$k_navlbl  = 'mb-1.5 ml-3 mt-3.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 first:mt-0 dark:text-slate-500';
$k_pill    = 'filter-pill group shrink-0 cursor-pointer select-none transition data-[active=true]:!border-blue-700 data-[active=true]:!bg-blue-700 dark:data-[active=true]:!border-blue-500 dark:data-[active=true]:!bg-blue-500';
$k_pilltxt = 'text-[11px] font-semibold group-data-[active=true]:!text-white';

function s_head(string $title, string $sub): string
{
    return '<div class="mb-4"><h3 class="text-[15px] font-bold text-slate-900 dark:text-slate-100">' . htmlspecialchars($title) . '</h3>'
        . '<p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">' . htmlspecialchars($sub) . '</p></div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Settings – DMW RO IV-A Evaluator</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]']
        };
        document.documentElement.setAttribute('data-theme', localStorage.getItem('dmw_theme') || 'light');
    </script>
    <style>
        /* Overrides dashboard.css logo sizing */
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Keep autofilled inputs dark in dark mode */
        [data-theme="dark"] input:-webkit-autofill,
        [data-theme="dark"] input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #0f172a inset !important;
            -webkit-text-fill-color: #e2e8f0 !important;
            caret-color: #e2e8f0;
            transition: background-color 9999s ease-in-out 0s;
        }
    </style>
</head>

<body class="bg-[var(--bg,#f4f6fb)] font-['DM_Sans',sans-serif] dark:bg-slate-900">

    <!-- TOP BAR -->
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
            <span class="topbar-date max-[420px]:hidden" id="live-date"></span>
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

    <!-- SUBBAR (fixed under topbar; pills are the tab switcher on mobile) -->
    <div class="subbar !fixed inset-x-0 z-40 !bg-white dark:!bg-slate-800">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2 class="max-sm:!text-base">Account &amp; Availability Settings</h2>
                <p id="subbar-section-label" class="max-sm:!text-[11px]">Manage your personal account information.</p>
            </div>
        </div>
        <div class="filter-scroll md:!hidden">
            <button type="button" data-tab="profile" class="<?= $k_pill ?>">
                <i class="fas fa-user text-blue-600 group-data-[active=true]:!text-white"></i>
                <span class="<?= $k_pilltxt ?>">Profile</span>
            </button>
            <button type="button" data-tab="security" class="<?= $k_pill ?>">
                <i class="fas fa-shield-halved text-emerald-500 group-data-[active=true]:!text-white"></i>
                <span class="<?= $k_pilltxt ?>">Security</span>
            </button>
            <button type="button" data-tab="availability" class="<?= $k_pill ?>">
                <i class="fas fa-user-clock text-amber-500 group-data-[active=true]:!text-white"></i>
                <span class="<?= $k_pilltxt ?>">Leave &amp; Reassignment</span>
            </button>
            <button type="button" data-tab="account" class="<?= $k_pill ?>">
                <i class="fas fa-user-slash text-red-500 group-data-[active=true]:!text-white"></i>
                <span class="<?= $k_pilltxt ?>">Account</span>
            </button>
        </div>
    </div>

    <!-- SETTINGS SHELL -->
    <div id="settings-shell" class="mx-auto grid w-full max-w-[1440px] grid-cols-1 gap-3.5 px-3.5 pb-[110px] pt-40 md:grid-cols-[224px_minmax(0,1fr)] md:gap-[22px] md:px-6">

        <!-- Side nav (hidden on mobile) -->
        <nav class="sticky top-[var(--sticky-top,160px)] hidden min-w-0 self-start md:block" aria-label="Settings sections">
            <div class="<?= $k_card ?> !p-3">
                <div class="<?= $k_navlbl ?>">Account</div>
                <button type="button" data-tab="profile" class="<?= $k_nav ?>"><i class="fas fa-user w-4 text-center text-xs"></i> Profile</button>
                <button type="button" data-tab="security" class="<?= $k_nav ?>"><i class="fas fa-shield-halved w-4 text-center text-xs"></i> Security</button>

                <div class="<?= $k_navlbl ?>">Workload</div>
                <button type="button" data-tab="availability" class="<?= $k_nav ?>">
                    <i class="fas fa-user-clock w-4 text-center text-xs"></i> Leave &amp; Reassignment
                    <?php if ($pending_request): ?>
                        <span class="ml-auto h-2 w-2 rounded-full bg-amber-400" title="Request pending"></span>
                    <?php endif; ?>
                </button>

                <div class="<?= $k_navlbl ?>">Danger Zone</div>
                <button type="button" data-tab="account" class="<?= $k_nav ?>"><i class="fas fa-user-slash w-4 text-center text-xs"></i> Deactivate Account</button>
            </div>
        </nav>

        <!-- Panels -->
        <div class="min-w-0">

            <!-- PROFILE -->
            <section id="panel-profile" class="space-y-4">
                <div class="<?= $k_card ?>">
                    <?= s_head('Profile', 'Update your personal account information.') ?>
                    <form id="profile-form" class="space-y-3.5">
                        <div class="grid gap-x-4 gap-y-3.5 sm:grid-cols-2">
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>" for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" maxlength="100" required
                                    value="<?= htmlspecialchars($evaluator_name) ?>" class="<?= $k_input ?>">
                            </div>
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>" for="phone">Phone</label>
                                <input type="tel" id="phone" name="phone" required inputmode="numeric" maxlength="11"
                                    pattern="09[0-9]{9}" autocomplete="tel" placeholder="09123456789"
                                    title="Enter an 11-digit mobile number starting with 09 (e.g., 09123456789)"
                                    value="<?= htmlspecialchars($evaluator_phone) ?>" class="<?= $k_input ?>">
                                <p id="phone-msg" data-state="" class="<?= $k_hint ?> data-[state=bad]:!text-red-600 data-[state=ok]:!text-emerald-600 dark:data-[state=bad]:!text-red-400 dark:data-[state=ok]:!text-emerald-400">Format: 09123456789 (11 digits, numbers only)</p>
                            </div>
                        </div>

                        <div>
                            <label class="<?= $k_label ?>" for="email_ro">Email Address</label>
                            <input type="email" id="email_ro" readonly value="<?= htmlspecialchars($evaluator_email) ?>" class="<?= $k_input ?>">
                            <p class="<?= $k_hint ?>">Email is tied to your login and can't be changed here. Contact an administrator if it needs to be updated.</p>
                        </div>

                        <div class="grid gap-x-4 gap-y-3.5 sm:grid-cols-3">
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>">Account Type</label>
                                <input type="text" readonly value="EVALUATOR" class="<?= $k_input ?>">
                            </div>
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>">Member Since</label>
                                <input type="text" readonly value="<?= htmlspecialchars($member_since_label) ?>" class="<?= $k_input ?>">
                            </div>
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>">Availability</label>
                                <input type="text" readonly value="<?= $active_leave ? 'On leave' : 'Available' ?>" class="<?= $k_input ?>">
                            </div>
                        </div>

                        <p id="profile-err" class="<?= $k_err ?>"></p>
                        <button type="submit" id="profile-save-btn" class="<?= $k_primary ?>"><i class="fas fa-save"></i> Save Changes</button>
                    </form>
                </div>

                <div class="<?= $k_card ?>">
                    <?= s_head('My Performance', 'A snapshot of your evaluation activity.') ?>
                    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                        <?php
                        $tiles = [
                            ['Active assignments', $perf['active_now'], 'text-slate-900 dark:text-slate-100'],
                            ['Total evaluations', $perf['evaluated_total'], 'text-slate-900 dark:text-slate-100'],
                            ['Approved', $perf['approved'], 'text-emerald-600 dark:text-emerald-400'],
                            ['Disapproved', $perf['disapproved'], 'text-red-700 dark:text-red-400'],
                        ];
                        foreach ($tiles as [$lbl, $val, $cls]): ?>
                            <div class="rounded-xl bg-slate-50 px-3.5 py-3 dark:bg-slate-900/50">
                                <div class="text-xl font-extrabold <?= $cls ?>"><?= number_format($val) ?></div>
                                <div class="mt-0.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400"><?= $lbl ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- SECURITY -->
            <section id="panel-security" class="hidden space-y-4">
                <div class="<?= $k_card ?>">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <?= s_head('Recent Sign-ins', 'Your latest sign-in attempts, so you can spot anything unusual.') ?>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                            Last <?= count($recent_logins) ?> <?= count($recent_logins) === 1 ? 'attempt' : 'attempts' ?>
                        </span>
                    </div>
                    <div class="overflow-x-auto [-webkit-overflow-scrolling:touch]">
                        <table class="w-full min-w-[520px] border-collapse text-xs max-sm:text-[11.5px]">
                            <thead>
                                <tr>
                                    <th class="<?= $k_th ?>">Date/Time</th>
                                    <th class="<?= $k_th ?>">Result</th>
                                    <th class="<?= $k_th ?>">IP</th>
                                    <th class="<?= $k_th ?>">Device</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recent_logins): ?>
                                    <tr>
                                        <td colspan="4" class="px-2.5 py-4 text-center text-slate-400">No sign-in activity found</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($recent_logins as $lg): $okLogin = (int) $lg['success'] === 1; ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                        <td class="<?= $k_td ?> whitespace-nowrap"><?= htmlspecialchars(date('M d, Y g:i a', strtotime($lg['attempted_at']))) ?></td>
                                        <td class="<?= $k_td ?>">
                                            <span class="inline-block rounded-full px-2 py-0.5 text-[10.5px] font-bold <?= $okLogin
                                                                                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
                                                                                                                            : 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' ?>">
                                                <?= $okLogin ? 'Success' : 'Failed' ?>
                                            </span>
                                        </td>
                                        <td class="<?= $k_td ?> whitespace-nowrap"><?= htmlspecialchars($lg['ip_address'] ?? '—') ?></td>
                                        <td class="<?= $k_td ?> max-w-[320px] truncate" title="<?= htmlspecialchars($lg['user_agent'] ?? '') ?>"><?= htmlspecialchars($lg['user_agent'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="<?= $k_card ?>">
                    <?= s_head('Change Password', "Choose a strong password you don't use elsewhere.") ?>
                    <form id="password-form" class="space-y-3.5">
                        <div>
                            <label class="<?= $k_label ?>" for="current_password">Current Password</label>
                            <div class="relative">
                                <input type="password" id="current_password" name="current_password" required autocomplete="current-password" class="<?= $k_input ?> !pr-10">
                                <button type="button" data-toggle="current_password" tabindex="-1" class="<?= $k_toggle ?>" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>

                        <div class="grid gap-x-4 gap-y-3.5 sm:grid-cols-2">
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>" for="new_password">New Password</label>
                                <div class="relative">
                                    <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password" class="<?= $k_input ?> !pr-10">
                                    <button type="button" data-toggle="new_password" tabindex="-1" class="<?= $k_toggle ?>" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                                </div>
                                <p class="<?= $k_hint ?>">Minimum 8 characters.</p>
                            </div>
                            <div class="min-w-0">
                                <label class="<?= $k_label ?>" for="confirm_password">Confirm New Password</label>
                                <div class="relative">
                                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password" class="<?= $k_input ?> !pr-10">
                                    <button type="button" data-toggle="confirm_password" tabindex="-1" class="<?= $k_toggle ?>" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                                </div>
                                <p id="password-match" data-state="" class="<?= $k_hint ?> min-h-[1.1em] data-[state=match]:!text-emerald-600 data-[state=no]:!text-red-600 dark:data-[state=match]:!text-emerald-400 dark:data-[state=no]:!text-red-400"></p>
                            </div>
                        </div>

                        <ul id="password-requirements" class="hidden gap-x-2.5 gap-y-0.5 text-[11px] sm:grid sm:grid-cols-2">
                            <?php foreach (
                                [
                                    'length'  => 'At least 8 characters',
                                    'upper'   => 'At least one uppercase letter',
                                    'lower'   => 'At least one lowercase letter',
                                    'number'  => 'At least one number',
                                    'special' => 'At least one special character',
                                ] as $k => $t
                            ): ?>
                                <li data-req="<?= $k ?>" data-valid="false"
                                    class="text-slate-400 transition-colors data-[valid=true]:text-emerald-600 dark:text-slate-500 dark:data-[valid=true]:text-emerald-400">• <?= $t ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <p id="password-err" class="<?= $k_err ?>"></p>
                        <button type="submit" id="password-save-btn" class="<?= $k_primary ?>"><i class="fas fa-key"></i> Update Password</button>
                    </form>
                </div>
            </section>

            <!-- LEAVE & REASSIGNMENT -->
            <section id="panel-availability" class="hidden space-y-4">
                <?php
                // Presentation-only values (no new queries)
                $leaveReasonLabel = $active_leave
                    ? (EVALUATOR_LEAVE_REASONS[$active_leave['reason_category']] ?? 'Leave')
                    : '';
                $pendingReasonLabel = '';
                if ($pending_request) {
                    $pendingReasonLabel = EVALUATOR_LEAVE_REASONS[$pending_request['reason_category']] ?? '';
                    if ($pending_other !== '') {
                        $pendingReasonLabel .= ' — ' . $pending_other;
                    }
                }
                $openCount = count($open_cases);

                // Status summary: leave > pending request > available
                if ($active_leave) {
                    $st_tone  = 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10';
                    $st_icon  = 'fa-user-clock text-amber-600';
                    $st_title = 'You are on leave';
                    $st_badge = 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300';
                    $st_blab  = 'On leave';
                    $st_text  = 'You will not receive or claim new applications until you end your leave.';
                } elseif ($pending_request) {
                    $st_tone  = 'border-blue-200 bg-sky-50 dark:border-blue-400/35 dark:bg-blue-500/10';
                    $st_icon  = 'fa-hourglass-half text-blue-500';
                    $st_title = 'Reassignment request pending';
                    $st_badge = 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300';
                    $st_blab  = 'Pending';
                    $st_text  = 'You are still available. An admin is reviewing your request.';
                } else {
                    $st_tone  = 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10';
                    $st_icon  = 'fa-circle-check text-emerald-600';
                    $st_title = 'You are available';
                    $st_badge = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300';
                    $st_blab  = 'Available';
                    $st_text  = 'You can receive and claim new applications.';
                }

                $returnDate = $active_leave['end_at'] ?? ($pending_request['expected_return'] ?? null);

                // Small helpers for the numbered steps
                $step = fn(int $n, string $title, string $sub = '') =>
                '<div class="mb-3 flex items-start gap-2.5">'
                    . '<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-700 text-[11px] font-extrabold text-white dark:bg-blue-500">' . $n . '</span>'
                    . '<div class="min-w-0"><div class="text-[13px] font-bold text-slate-900 dark:text-slate-100">' . htmlspecialchars($title) . '</div>'
                    . ($sub !== '' ? '<p class="text-[11.5px] text-slate-500 dark:text-slate-400">' . htmlspecialchars($sub) . '</p>' : '')
                    . '</div></div>';
                ?>

                <!-- 1. ALERTS -->
                <?php if ($flash): ?>
                    <div class="<?= $k_ok ?>" role="status"><i class="fas fa-circle-check mt-0.5"></i><span><?= htmlspecialchars($flash) ?></span></div>
                <?php endif; ?>
                <?php if ($reassign_error): ?>
                    <div class="<?= $k_bad ?>" role="alert"><i class="fas fa-circle-exclamation mt-0.5"></i><span><?= htmlspecialchars($reassign_error) ?></span></div>
                <?php endif; ?>

                <!-- 2. YOUR STATUS -->
                <div class="rounded-[14px] border p-4 sm:p-5 <?= $st_tone ?>">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <i class="fas <?= $st_icon ?> mt-1 text-lg"></i>
                            <div class="min-w-0">
                                <h3 class="text-[15px] font-bold text-slate-900 dark:text-slate-100"><?= $st_title ?></h3>
                                <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-300"><?= $st_text ?></p>
                            </div>
                        </div>
                        <span class="rounded-full px-3 py-1 text-[11px] font-bold <?= $st_badge ?>"><?= $st_blab ?></span>
                    </div>

                    <dl class="mt-4 grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                        <div class="rounded-xl bg-white/70 px-3.5 py-2.5 dark:bg-slate-900/40">
                            <dt class="text-[10.5px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Open applications</dt>
                            <dd class="mt-0.5 text-lg font-extrabold text-slate-900 dark:text-slate-100"><?= number_format($openCount) ?></dd>
                        </div>
                        <div class="rounded-xl bg-white/70 px-3.5 py-2.5 dark:bg-slate-900/40">
                            <dt class="text-[10.5px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                <?= $active_leave ? 'On leave since' : 'Request sent' ?>
                            </dt>
                            <dd class="mt-0.5 text-[13px] font-bold text-slate-900 dark:text-slate-100">
                                <?php if ($active_leave): ?>
                                    <?= date('M d, Y', strtotime($active_leave['start_at'])) ?>
                                <?php elseif ($pending_request): ?>
                                    <?= date('M d, Y', strtotime($pending_request['requested_at'])) ?>
                                <?php else: ?>
                                    <span class="font-semibold text-slate-400 dark:text-slate-500">No active request</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="rounded-xl bg-white/70 px-3.5 py-2.5 dark:bg-slate-900/40">
                            <dt class="text-[10.5px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Expected return</dt>
                            <dd class="mt-0.5 text-[13px] font-bold text-slate-900 dark:text-slate-100">
                                <?= $returnDate
                                    ? date('M d, Y', strtotime($returnDate))
                                    : '<span class="font-semibold text-slate-400 dark:text-slate-500">Not set</span>' ?>
                            </dd>
                        </div>
                    </dl>

                    <?php if ($active_leave): ?>
                        <p class="mt-3 text-xs text-slate-600 dark:text-slate-300">
                            <b>Reason:</b> <?= htmlspecialchars($leaveReasonLabel) ?>
                        </p>
                        <form method="POST" id="endLeaveForm" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="end_leave">
                            <button type="button" id="end-leave-btn" class="<?= $k_btn ?> w-full bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto">
                                <i class="fas fa-rotate-left"></i> I'm back, make me available
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- 3. REASSIGNMENT REQUEST -->
                <div class="<?= $k_card ?>">
                    <?= s_head('Reassignment Request', 'Ask the admin to hand your open applications to another evaluator.') ?>

                    <?php if ($pending_request): ?>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <div class="mb-3 flex items-center gap-2 text-[13px] font-extrabold text-slate-900 dark:text-slate-100">
                                <i class="fas fa-hourglass-half text-blue-500"></i> Waiting for admin approval
                            </div>
                            <dl class="grid gap-x-6 gap-y-2.5 text-xs sm:grid-cols-2">
                                <div>
                                    <dt class="font-bold text-slate-400 dark:text-slate-500">Sent</dt>
                                    <dd class="mt-0.5 text-slate-700 dark:text-slate-300"><?= date('M d, Y g:i a', strtotime($pending_request['requested_at'])) ?></dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400 dark:text-slate-500">Applications</dt>
                                    <dd class="mt-0.5 text-slate-700 dark:text-slate-300"><?= (int) $pending_request['item_count'] ?></dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="font-bold text-slate-400 dark:text-slate-500">Reason</dt>
                                    <dd class="mt-0.5 break-words text-slate-700 dark:text-slate-300"><?= htmlspecialchars($pendingReasonLabel) ?></dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400 dark:text-slate-500">Expected return</dt>
                                    <dd class="mt-0.5 text-slate-700 dark:text-slate-300">
                                        <?= $pending_request['expected_return'] ? date('M d, Y', strtotime($pending_request['expected_return'])) : 'Not set' ?>
                                    </dd>
                                </div>
                            </dl>
                            <form method="POST" id="cancelReqForm" class="mt-4">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="cancel_request">
                                <input type="hidden" name="request_id" value="<?= (int) $pending_request['id'] ?>">
                                <button type="button" id="cancel-req-btn" class="<?= $k_outline ?>"><i class="fas fa-xmark"></i> Cancel request</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="<?= $k_info ?> mb-5">
                            <i class="fas fa-circle-info mr-1.5"></i>Once approved, the selected applications move to another available evaluator. Choosing all applications also marks you unavailable until you end your leave.
                        </div>

                        <form method="POST" id="requestForm" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="submit_request">

                            <!-- Step 1 -->
                            <fieldset>
                                <legend class="sr-only">Which applications?</legend>
                                <?= $step(1, 'Choose applications', 'Select what you want to hand over.') ?>
                                <div class="space-y-2 pl-0 sm:pl-8">
                                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-[13px] text-slate-800 has-[:checked]:border-blue-700 has-[:checked]:bg-blue-50/50 dark:border-slate-700 dark:text-slate-200 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-500/10">
                                        <input type="radio" name="scope" value="all" checked class="mt-1 accent-blue-700">
                                        <span><b>All my open applications</b> (<?= $openCount ?>)<br>
                                            <span class="text-[11.5px] text-slate-500 dark:text-slate-400">Also marks you unavailable once the admin approves.</span></span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-[13px] text-slate-800 has-[:checked]:border-blue-700 has-[:checked]:bg-blue-50/50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-500/10">
                                        <input type="radio" name="scope" value="selected" class="mt-1 accent-blue-700" <?= $open_cases ? '' : 'disabled' ?>>
                                        <span><b>Only selected applications</b><br>
                                            <span class="text-[11.5px] text-slate-500 dark:text-slate-400">You stay available for new applications.</span></span>
                                    </label>

                                    <div id="caseList" class="hidden max-h-64 divide-y divide-slate-200 overflow-y-auto rounded-xl border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
                                        <?php foreach ($open_cases as $c): ?>
                                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                                <input type="checkbox" name="case_ids[]" value="<?= (int) $c['id'] ?>" class="accent-blue-700">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-[12.5px] font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($c['applicant_name'] ?? '—') ?></span>
                                                    <span class="block truncate text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($c['rsf_number'] ?? '—') ?> · <?= htmlspecialchars($c['position_title'] ?? '—') ?></span>
                                                </span>
                                                <span class="shrink-0 text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500"><?= htmlspecialchars(str_replace('_', ' ', $c['status'])) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!$open_cases): ?>
                                        <p class="<?= $k_hint ?>">You have no open applications to hand over.</p>
                                    <?php endif; ?>
                                </div>
                            </fieldset>

                            <!-- Step 2 -->
                            <div>
                                <?= $step(2, 'Reason and return date', 'Only the category is recorded. No personal details needed.') ?>
                                <div class="space-y-3.5 pl-0 sm:pl-8">
                                    <div class="grid gap-x-4 gap-y-3.5 sm:grid-cols-2">
                                        <div class="min-w-0">
                                            <label class="<?= $k_label ?>" for="reason_category">Reason</label>
                                            <select name="reason_category" id="reason_category" required class="<?= $k_input ?>">
                                                <?php foreach (EVALUATOR_LEAVE_REASONS as $key => $label): ?>
                                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="min-w-0">
                                            <label class="<?= $k_label ?>" for="expected_return">Expected return <span class="font-normal text-slate-400">(optional)</span></label>
                                            <input type="date" name="expected_return" id="expected_return" min="<?= date('Y-m-d') ?>" class="<?= $k_input ?>">
                                            <p class="<?= $k_hint ?>">Leave blank if you don't know yet.</p>
                                        </div>
                                    </div>

                                    <div id="otherWrap" class="hidden">
                                        <label class="<?= $k_label ?>" for="reason_other">Please specify your reason</label>
                                        <input type="text" name="reason_other" id="reason_other" maxlength="255" placeholder="Briefly state the exact reason" class="<?= $k_input ?>">
                                        <p class="<?= $k_hint ?>">Required when "Other" is selected. Max 255 characters.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3 -->
                            <div>
                                <?= $step(3, 'Send to admin', 'You can cancel the request while it is still pending.') ?>
                                <div class="pl-0 sm:pl-8">
                                    <button type="button" id="submit-request-btn" class="<?= $k_primary ?>" <?= $open_cases ? '' : 'disabled' ?>>
                                        <i class="fas fa-paper-plane"></i> Send request to Admin
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- 4. RECENT DECISIONS (history, tabbed) -->
                <?php
                $dec_counts  = ['approved' => 0, 'rejected' => 0];
                foreach ($decisions as $d) {
                    if (isset($dec_counts[$d['status']])) {
                        $dec_counts[$d['status']]++;
                    }
                }
                $dec_default = $decisions ? ($decisions[0]['status'] === 'rejected' ? 'rejected' : 'approved') : 'approved';
                $k_dtab = 'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs font-bold text-slate-600 transition data-[active=false]:hover:bg-slate-100 data-[active=true]:bg-blue-700 data-[active=true]:text-white dark:text-slate-400 dark:data-[active=false]:hover:bg-slate-700/60 dark:data-[active=true]:bg-blue-500';
                $k_dcnt = 'rounded-full bg-slate-200 px-1.5 py-px text-[10px] font-extrabold text-slate-600 group-data-[active=true]:bg-white/25 group-data-[active=true]:text-white dark:bg-slate-700 dark:text-slate-300';
                ?>
                <div class="<?= $k_card ?>" id="decisions-card" data-default="<?= $dec_default ?>">
                    <?= s_head('Recent Decisions', 'The admin\'s response to your last 20 reviewed requests.') ?>

                    <div role="tablist" aria-label="Decision type" class="mb-4 inline-flex gap-1 rounded-xl bg-slate-50 p-1 dark:bg-slate-900/50">
                        <button type="button" role="tab" data-dtab="approved" data-active="false" class="group <?= $k_dtab ?>">
                            <i class="fas fa-circle-check"></i> Approved
                            <span class="<?= $k_dcnt ?>"><?= $dec_counts['approved'] ?></span>
                        </button>
                        <button type="button" role="tab" data-dtab="rejected" data-active="false" class="group <?= $k_dtab ?>">
                            <i class="fas fa-circle-xmark"></i> Rejected
                            <span class="<?= $k_dcnt ?>"><?= $dec_counts['rejected'] ?></span>
                        </button>
                    </div>

                    <ul class="space-y-2.5">
                        <?php foreach ($decisions as $d):
                            $ok          = $d['status'] === 'approved';
                            $reasonLabel = EVALUATOR_LEAVE_REASONS[$d['reason_category']] ?? '';
                            if ($d['reason_category'] === 'other' && !empty($d['reason_other'])) {
                                $reasonLabel .= ' — ' . $d['reason_other'];
                            }
                        ?>
                            <li data-dstatus="<?= htmlspecialchars($d['status']) ?>"
                                class="hidden rounded-xl border p-3.5 <?= $ok
                                                                            ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                                                                            : 'border-red-200 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10' ?>">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-900 dark:text-slate-100">
                                        <i class="fas <?= $ok ? 'fa-circle-check text-emerald-600' : 'fa-circle-xmark text-red-600' ?>"></i>
                                        <?= $ok ? 'Approved' : 'Rejected' ?>
                                    </span>
                                    <span class="text-[11px] font-medium text-slate-500 dark:text-slate-400"><?= date('M d, Y g:i a', strtotime($d['reviewed_at'])) ?></span>
                                </div>
                                <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                    <?= (int) $d['item_count'] ?> application(s)<?= $ok ? ' · ' . (int) $d['reassigned_count'] . ' reassigned' : '' ?>
                                    · <?= htmlspecialchars($reasonLabel) ?>
                                </p>
                                <p class="mt-2 break-words border-t border-black/5 pt-2 text-[12.5px] text-slate-800 dark:border-white/10 dark:text-slate-200">
                                    <b><?= htmlspecialchars($d['reviewer_name']) ?>:</b>
                                    <?= !empty($d['admin_comment'])
                                        ? nl2br(htmlspecialchars($d['admin_comment']))
                                        : '<span class="text-slate-500 dark:text-slate-400">No reason provided.</span>' ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p data-dempty="approved" class="hidden rounded-xl bg-slate-50 px-4 py-5 text-center text-xs text-slate-400 dark:bg-slate-900/40 dark:text-slate-500">
                        <i class="fas fa-circle-check mb-1.5 block text-lg"></i>No approved requests yet.
                    </p>
                    <p data-dempty="rejected" class="hidden rounded-xl bg-slate-50 px-4 py-5 text-center text-xs text-slate-400 dark:bg-slate-900/40 dark:text-slate-500">
                        <i class="fas fa-circle-xmark mb-1.5 block text-lg"></i>No rejected requests yet.
                    </p>
                </div>
            </section>
            </section>

            <!-- ACCOUNT / DANGER ZONE -->
            <section id="panel-account" class="hidden space-y-4">
                <div class="<?= $k_card ?> !border-red-200 dark:!border-red-500/35">
                    <?= s_head('Deactivate Account', 'Permanently lock this account until it is reactivated.') ?>
                    <p class="mb-3.5 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                        Deactivating your account will immediately log you out and prevent you from logging back in.
                        You'll need to submit a reactivation request (or ask an admin) to regain access later.
                    </p>
                    <?php if ($my_assigned_count > 0): ?>
                        <p class="mb-3.5 rounded-lg bg-amber-50 px-3 py-2 text-[11.5px] text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            <i class="fas fa-circle-info"></i>
                            You still have <?= (int) $my_assigned_count ?> open application(s). Use
                            <a href="#availability" data-goto="availability" class="font-bold underline">Leave &amp; Reassignment</a> first.
                        </p>
                    <?php endif; ?>
                    <button type="button" id="deactivate-account-btn"
                        class="<?= $k_btn ?> w-full border border-red-200 bg-red-50 text-red-600 hover:bg-red-100 sm:w-auto dark:border-red-500/35 dark:bg-red-500/15 dark:text-red-400">
                        <i class="fas fa-user-slash"></i> Deactivate My Account
                    </button>
                </div>
            </section>
        </div>
    </div>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="index.php" class="dk"><i class="fas fa-house"></i><span class="dk-lbl">Home</span></a>
            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
                <?php if ($my_assigned_count > 0): ?>
                    <span class="dk-badge"><?= $my_assigned_count > 99 ? '99+' : $my_assigned_count ?></span>
                <?php endif; ?>
            </a>
            <div class="dk-div"></div>
            <a href="evaluator-reports.php" class="dk" data-tip="Applicant Reports"><i class="fas fa-file-contract"></i><span class="dk-lbl">Applicants</span></a>
            <a href="reports.php" class="dk"><i class="fas fa-chart-bar"></i><span class="dk-lbl">Reports</span></a>
            <div class="dk-div"></div>
            <a href="settings.php" class="dk active"><i class="fas fa-gear"></i><span class="dk-lbl">Settings</span></a>
            <div class="dk" onclick="logout('Evaluator')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <script>
        const CSRF = <?= json_encode($csrf) ?>;
        const INITIAL_TAB = <?= json_encode($initial_tab) ?>;
        const $ = id => document.getElementById(id);

        /* ── Helpers ── */
        function showErr(id, msg) {
            const el = $(id);
            if (!el) return;
            el.textContent = msg || '';
            el.classList.toggle('hidden', !msg);
        }

        function postForm(fd) {
            fd.append('csrf_token', CSRF);
            return fetch('settings.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json());
        }

        function confirmThen(opts, onConfirm) {
            Swal.fire({
                icon: 'question',
                showCancelButton: true,
                cancelButtonColor: '#94a3b8',
                ...opts
            }).then(r => {
                if (r.isConfirmed) onConfirm();
            });
        }

        function toast(icon, title) {
            Swal.fire({
                icon,
                title,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2500
            });
        }

        /* ── Live date ── */
        function updateLiveDateTime() {
            const el = $('live-date');
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

        /* ── Tabs (sidebar + subbar pills stay in sync) ── */
        const TABS = ['profile', 'security', 'availability', 'account'];
        const SECTION_SUBTITLES = {
            profile: 'Manage your personal account information.',
            security: 'Review sign-in activity and change your password.',
            availability: 'Request leave and hand your open applications to another evaluator.',
            account: 'Deactivate your evaluator account.'
        };

        function switchTab(tab) {
            if (!TABS.includes(tab)) tab = 'profile';
            document.querySelectorAll('[data-tab]').forEach(el => el.dataset.active = String(el.dataset.tab === tab));
            TABS.forEach(t => $('panel-' + t).classList.toggle('hidden', t !== tab));
            const label = $('subbar-section-label');
            if (label) label.textContent = SECTION_SUBTITLES[tab];
            // keep the active pill visible in the horizontal pill bar
            const pill = document.querySelector('.filter-scroll [data-tab="' + tab + '"]');
            if (pill && pill.scrollIntoView) pill.scrollIntoView({
                block: 'nearest',
                inline: 'center'
            });
            history.replaceState(null, '', '#' + tab);
        }

        document.querySelectorAll('[data-tab]').forEach(el => el.addEventListener('click', () => switchTab(el.dataset.tab)));
        document.querySelectorAll('[data-goto]').forEach(el => el.addEventListener('click', e => {
            e.preventDefault();
            switchTab(el.dataset.goto);
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }));

        const hashTab = location.hash.replace('#', '');
        switchTab(TABS.includes(hashTab) && !location.search.includes('msg=') ? hashTab : INITIAL_TAB);

        /* ── Pin subbar under the topbar & reserve space (same approach as admin) ── */
        function pinSubbar() {
            const topbar = document.querySelector('.topbar');
            const subbar = document.querySelector('.subbar');
            const shell = $('settings-shell');
            if (!topbar || !subbar || !shell) return;
            const topH = topbar.offsetHeight;
            subbar.style.top = topH + 'px';
            const total = topH + subbar.offsetHeight + 16;
            shell.style.paddingTop = total + 'px';
            document.documentElement.style.setProperty('--sticky-top', total + 'px');
        }
        window.addEventListener('load', pinSubbar);
        window.addEventListener('resize', pinSubbar);
        setTimeout(pinSubbar, 300);

        /* ── Phone: digits only, 09XXXXXXXXX ── */
        (function() {
            const phone = $('phone'),
                msg = $('phone-msg');
            const hint = 'Format: 09123456789 (11 digits, numbers only)';
            const set = (t, s) => {
                msg.textContent = t;
                msg.dataset.state = s || '';
            };

            function validate() {
                const v = phone.value;
                if (v === '') {
                    phone.setCustomValidity('Phone number is required.');
                    return set(hint);
                }
                if (v.length >= 2 && !v.startsWith('09')) {
                    phone.setCustomValidity('Number must start with 09.');
                    return set('✗ Number must start with 09.', 'bad');
                }
                if (v.length < 11) {
                    phone.setCustomValidity('Phone number must be 11 digits.');
                    return set('✗ ' + v.length + '/11 digits entered.', 'bad');
                }
                phone.setCustomValidity('');
                set('✓ Valid phone number', 'ok');
            }
            phone.addEventListener('input', () => {
                phone.value = phone.value.replace(/\D/g, '').slice(0, 11);
                validate();
            });
            phone.addEventListener('keydown', e => {
                const allowed = ['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter'];
                if (allowed.includes(e.key) || e.ctrlKey || e.metaKey) return;
                if (!/^\d$/.test(e.key)) e.preventDefault();
            });
            validate();
        })();

        /* ── Update profile ── */
        $('profile-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            showErr('profile-err', '');
            const btn = $('profile-save-btn');
            btn.disabled = true;
            const fd = new FormData(this);
            fd.append('action', 'update_profile');

            postForm(fd).then(data => {
                btn.disabled = false;
                if (!data.ok) return showErr('profile-err', data.error || 'Something went wrong.');
                const tbAvatar = $('avatarMenuBtn');
                if (tbAvatar) tbAvatar.textContent = data.initials;
                const menuName = $('avatarMenuName');
                if (menuName) menuName.textContent = data.full_name;
                toast('success', data.message);
            }).catch(() => {
                btn.disabled = false;
                showErr('profile-err', 'Network error. Please try again.');
            });
        });

        /* ── Password: eye toggles, checklist, match ── */
        document.querySelectorAll('[data-toggle]').forEach(btn => btn.addEventListener('click', () => {
            const inp = $(btn.dataset.toggle),
                icon = btn.querySelector('i');
            const show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        }));

        (function() {
            const pwd = $('new_password'),
                conf = $('confirm_password');
            const box = $('password-requirements'),
                msg = $('password-match');
            const rules = {
                length: v => v.length >= 8,
                upper: v => /[A-Z]/.test(v),
                lower: v => /[a-z]/.test(v),
                number: v => /[0-9]/.test(v),
                special: v => /[^A-Za-z0-9]/.test(v)
            };
            const showBox = () => box.classList.remove('hidden');
            const checkMatch = () => {
                if (!conf.value) {
                    msg.textContent = '';
                    msg.dataset.state = '';
                    return;
                }
                const same = pwd.value === conf.value;
                msg.textContent = same ? '✓ Passwords match' : '✗ Passwords do not match';
                msg.dataset.state = same ? 'match' : 'no';
            };
            [pwd, conf].forEach(i => {
                i.addEventListener('focus', showBox);
                i.addEventListener('blur', () => setTimeout(() => {
                    if (document.activeElement !== pwd && document.activeElement !== conf) box.classList.add('hidden');
                }, 100));
            });
            pwd.addEventListener('input', () => {
                document.querySelectorAll('[data-req]').forEach(el => {
                    el.dataset.valid = rules[el.dataset.req](pwd.value) ? 'true' : 'false';
                });
                showBox();
                checkMatch();
            });
            conf.addEventListener('input', checkMatch);
        })();

        $('password-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            showErr('password-err', '');
            if ($('new_password').value !== $('confirm_password').value) {
                return showErr('password-err', 'New password and confirmation do not match.');
            }
            const btn = $('password-save-btn');
            btn.disabled = true;
            const fd = new FormData(this);
            fd.append('action', 'change_password');

            postForm(fd).then(data => {
                btn.disabled = false;
                if (!data.ok) return showErr('password-err', data.error || 'Something went wrong.');
                this.reset();
                $('password-match').textContent = '';
                $('password-match').dataset.state = '';
                $('password-requirements').classList.add('hidden');
                document.querySelectorAll('[data-req]').forEach(el => el.dataset.valid = 'false');
                toast('success', data.message);
            }).catch(() => {
                btn.disabled = false;
                showErr('password-err', 'Network error. Please try again.');
            });
        });

        /* ── Leave & reassignment ── */
        (function() {
            const form = $('requestForm');
            if (form) {
                const caseList = $('caseList'),
                    reasonSel = $('reason_category');
                const otherWrap = $('otherWrap'),
                    otherInput = $('reason_other');

                form.querySelectorAll('input[name="scope"]').forEach(r =>
                    r.addEventListener('change', () =>
                        caseList.classList.toggle('hidden', form.elements['scope'].value !== 'selected')));

                const syncOther = () => {
                    const isOther = reasonSel.value === 'other';
                    otherWrap.classList.toggle('hidden', !isOther);
                    otherInput.required = isOther;
                    if (!isOther) otherInput.value = '';
                };
                reasonSel.addEventListener('change', syncOther);
                syncOther();

                $('submit-request-btn').addEventListener('click', () => {
                    if (reasonSel.value === 'other' && !otherInput.value.trim()) {
                        Swal.fire({
                            title: 'Specify your reason',
                            text: 'Please type the exact reason for your leave.',
                            icon: 'warning',
                            confirmButtonColor: '#dc2626'
                        });
                        return otherInput.focus();
                    }
                    const scope = form.elements['scope'].value;
                    if (scope === 'selected' && !form.querySelectorAll('input[name="case_ids[]"]:checked').length) {
                        return Swal.fire({
                            title: 'Select applications',
                            text: 'Please tick at least one application to hand over.',
                            icon: 'warning',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                    confirmThen({
                        title: 'Send this request?',
                        text: scope === 'all' ?
                            'All your open applications will be sent for reassignment, and you will be marked unavailable once the admin approves.' : 'The selected applications will be sent for reassignment.',
                        confirmButtonText: 'Yes, send',
                        confirmButtonColor: '#1d4ed8'
                    }, () => form.submit());
                });
            }

            const cancelBtn = $('cancel-req-btn');
            if (cancelBtn) cancelBtn.addEventListener('click', () => confirmThen({
                title: 'Cancel your request?',
                icon: 'warning',
                confirmButtonText: 'Yes, cancel it',
                confirmButtonColor: '#dc2626'
            }, () => $('cancelReqForm').submit()));

            const endBtn = $('end-leave-btn');
            if (endBtn) endBtn.addEventListener('click', () => confirmThen({
                title: 'Make yourself available again?',
                text: 'You will be able to claim and receive new applications.',
                confirmButtonText: "Yes, I'm back",
                confirmButtonColor: '#059669'
            }, () => $('endLeaveForm').submit()));
        })();

        /* ── Recent Decisions tabs ── */
        (function() {
            const card = $('decisions-card');
            if (!card) return;
            const tabs = card.querySelectorAll('[data-dtab]');

            function showDecisions(type) {
                tabs.forEach(t => {
                    const on = t.dataset.dtab === type;
                    t.dataset.active = String(on);
                    t.setAttribute('aria-selected', String(on));
                });
                let shown = 0;
                card.querySelectorAll('[data-dstatus]').forEach(li => {
                    const match = li.dataset.dstatus === type;
                    li.classList.toggle('hidden', !match);
                    if (match) shown++;
                });
                card.querySelectorAll('[data-dempty]').forEach(p =>
                    p.classList.toggle('hidden', p.dataset.dempty !== type || shown > 0));
            }

            tabs.forEach(t => t.addEventListener('click', () => showDecisions(t.dataset.dtab)));
            showDecisions(card.dataset.default || 'approved');
        })();

        /* ── Self-service deactivation ── */
        $('deactivate-account-btn').addEventListener('click', function() {
            const reasons = [
                'Taking a break / temporary leave',
                'No longer serving as evaluator',
                'Privacy or security concern',
                'Other'
            ];
            const optionsHtml = reasons.map(r => `<option value="${r}">${r}</option>`).join('');
            const lbl = 'mb-1.5 block text-[11.5px] font-bold text-slate-600';

            Swal.fire({
                title: 'Deactivate Your Account',
                html: `
                <div class="text-left text-[13px]">
                    <label class="${lbl}">Reason</label>
                    <div class="group relative mb-3">
                        <select id="swal-reason" class="w-full appearance-none rounded-lg border-[1.5px] border-slate-300 bg-white py-2.5 pl-3 pr-9 text-[13px] text-slate-800 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/15">
                            <option value="">— Select a reason —</option>${optionsHtml}
                        </select>
                        <i class="fas fa-chevron-down pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400 transition group-focus-within:rotate-180 group-focus-within:text-blue-500"></i>
                    </div>
                    <div id="swal-other-wrap" class="hidden">
                        <label class="${lbl}">Please specify</label>
                        <input id="swal-other" class="swal2-input !m-0 !mb-3 !w-full" placeholder="Enter specific reason...">
                    </div>
                    <label class="${lbl}">Confirm Password</label>
                    <input id="swal-password" type="password" class="swal2-input !m-0 !w-full" placeholder="Enter your password">
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Deactivate Account',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel',
                focusConfirm: false,
                didOpen: () => {
                    $('swal-reason').addEventListener('change', function() {
                        $('swal-other-wrap').classList.toggle('hidden', this.value !== 'Other');
                    });
                },
                preConfirm: () => {
                    const reason = $('swal-reason').value;
                    const other = $('swal-other').value.trim();
                    const password = $('swal-password').value;
                    if (!reason) return Swal.showValidationMessage('Please select a reason.'), false;
                    if (reason === 'Other' && other.length < 5) return Swal.showValidationMessage('Please specify a reason (at least 5 characters).'), false;
                    if (!password) return Swal.showValidationMessage('Please enter your password to confirm.'), false;
                    return {
                        reason,
                        other,
                        password
                    };
                }
            }).then(result => {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('action', 'deactivate_account');
                fd.append('reason_preset', result.value.reason);
                fd.append('reason_other', result.value.other);
                fd.append('password', result.value.password);

                Swal.fire({
                    title: 'Deactivating...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                postForm(fd).then(data => {
                    if (!data.ok) return Swal.fire('Unable to deactivate', data.error || 'Something went wrong.', 'error');
                    Swal.fire({
                        icon: 'success',
                        title: 'Account Deactivated',
                        text: data.message,
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        window.location.href = '../login.php';
                    });
                }).catch(() => Swal.fire('Network error', 'Please try again.', 'error'));
            });
        });
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
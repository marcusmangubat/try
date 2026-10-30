<?php
// evaluator/profile.php – DMW Evaluator Profile & Settings
session_start();
require_once '../db.php';
require_once '../auth.php';
date_default_timezone_set('Asia/Manila');

// Require evaluator login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

/* LOAD EVALUATOR PROFILE (moved above AJAX handlers — deactivate_account needs eid/name) */

$evaluator_name  = 'Evaluator';
$evaluator_id    = null;
$evaluator_phone = '';
$evaluator_since = null;

$stmt_ev = $database->prepare("
    SELECT eid, full_name, phone, created_at
    FROM evaluators
    WHERE email = ?
    LIMIT 1
");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();

if ($ev_result->num_rows === 1) {
    $row             = $ev_result->fetch_assoc();
    $evaluator_id    = (int)$row['eid'];
    $evaluator_name  = $row['full_name'] ?? 'Evaluator';
    $evaluator_phone = $row['phone'] ?? '';
    $evaluator_since = $row['created_at'] ?? null;
}
$stmt_ev->close();

/* AJAX HANDLER */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Update profile (full name, phone) ──
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

        $parts    = explode(' ', trim($full_name));
        $initials = count($parts) >= 2
            ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
            : strtoupper(substr($full_name, 0, 2));

        echo json_encode([
            'ok'        => true,
            'message'   => 'Profile updated.',
            'full_name' => htmlspecialchars($full_name),
            'phone'     => htmlspecialchars($phone),
            'initials'  => htmlspecialchars($initials),
        ]);
        exit;
    }

    // ── Change password ──
    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            echo json_encode(['ok' => false, 'error' => 'Please fill in all password fields.']);
            exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']);
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

        if (!$ok) {
            echo json_encode(['ok' => false, 'error' => 'Could not update password. Please try again.']);
            exit;
        }

        echo json_encode(['ok' => true, 'message' => 'Password changed successfully.']);
        exit;
    }

    // ── Deactivate own account (self-service) ──
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

        // Block if there are active assignments/applications (mirrors admin's evaluator check)
        if ($evaluator_id) {
            $stmt = $database->prepare("SELECT COUNT(*) AS c FROM assignments WHERE evaluator_id = ? AND status = 'active'");
            $stmt->bind_param("i", $evaluator_id);
            $stmt->execute();
            $active_assign = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            $stmt = $database->prepare("SELECT COUNT(*) AS c FROM applications WHERE current_evaluator_id = ? AND handling_party = 'evaluator'");
            $stmt->bind_param("i", $evaluator_id);
            $stmt->execute();
            $active_apps = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            if ($active_assign > 0 || $active_apps > 0) {
                echo json_encode(['ok' => false, 'error' => 'You have active assignments or applications. Please complete them or ask an administrator to reassign them before deactivating your account.']);
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

            // Notify all admins
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

$my_assigned_count = 0;
if ($evaluator_id) {
    $stmt_cnt = $database->prepare("
        SELECT COUNT(*) as cnt 
        FROM applications 
        WHERE current_evaluator_id = ? 
          AND handling_party = 'evaluator'
    ");
    $stmt_cnt->bind_param("i", $evaluator_id);
    $stmt_cnt->execute();
    $my_assigned_count = (int)$stmt_cnt->get_result()->fetch_assoc()['cnt'];
    $stmt_cnt->close();
}
/* PERFORMANCE STATS */

$perf = [
    'evaluated_total' => 0,
    'approved'        => 0,
    'disapproved'     => 0,
    'for_compliance'  => 0,
    'active_now'      => 0,
];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT decision, COUNT(*) AS cnt
        FROM evaluations
        WHERE evaluator_id = ?
        GROUP BY decision
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $cnt = (int)$r['cnt'];
        $perf['evaluated_total'] += $cnt;
        if (array_key_exists($r['decision'], $perf)) {
            $perf[$r['decision']] += $cnt;
        }
    }
    $stmt->close();

    $stmt = $database->prepare("
        SELECT COUNT(*) AS cnt FROM applications
        WHERE current_evaluator_id = ? AND handling_party = 'evaluator'
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $perf['active_now'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

$member_since_label = $evaluator_since ? date('F Y', strtotime($evaluator_since)) : '—';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Profile &amp; Settings – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>

    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Section label (mini uppercase eyebrow) — mirrors index.php */
        .sec-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        .fade-in {
            opacity: 0;
            transform: translateY(10px);
            animation: fi2 .4s ease forwards;
        }

        @keyframes fi2 {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .d1 {
            animation-delay: .05s
        }

        .d2 {
            animation-delay: .1s
        }

        .d3 {
            animation-delay: .15s
        }

        .d4 {
            animation-delay: .2s
        }

        .d5 {
            animation-delay: .25s
        }

        /* ── Profile header card ── */
        .profile-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 14px;
        }

        .profile-avatar {
            width: 64px;
            height: 64px;
            flex-shrink: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--blue-lt), var(--indigo));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-name {
            font-size: 16.5px;
            font-weight: 800;
            color: var(--text1);
        }

        .profile-role {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text3);
            margin-top: 2px;
        }

        .profile-email {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Custom animated dropdown for the deactivate-account modal */
        .dmw-select-wrap {
            position: relative;
            margin: 0 0 12px;
        }

        .dmw-select {
            width: 100%;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding: 10px 36px 10px 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: #1e293b;
            background: #fff;
            cursor: pointer;
            transition: border-color .18s, box-shadow .18s;
        }

        .dmw-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .15);
        }

        .dmw-select-arrow {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            font-size: 11px;
            color: #94a3b8;
            pointer-events: none;
            transition: transform .2s ease, color .18s ease;
        }

        .dmw-select-wrap:focus-within .dmw-select-arrow {
            transform: translateY(-50%) rotate(180deg);
            color: #3b82f6;
        }

        /*  Card wrapper for forms (mirrors index.php's .card)  */
        .card {
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 14px;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
        }

        .ctdot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }

        .card-action-btn {
            background: none;
            border: none;
            color: var(--blue-lt);
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .card-action-btn:hover {
            text-decoration: underline;
        }

        /* ── Form elements ── */
        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: var(--text1);
            background: var(--card);
            transition: border-color .18s;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--blue-lt);
        }

        .form-input:disabled {
            background: var(--surface);
            color: var(--text3);
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 10.5px;
            color: var(--text3);
            margin-top: 5px;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }

        .btn {
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid transparent;
            transition: opacity .18s, transform .12s;
        }

        .btn:active {
            transform: scale(.97);
        }

        .btn-primary {
            background: var(--blue-lt);
            color: #fff;
        }

        .btn-primary:hover {
            opacity: .92;
        }

        .btn-secondary {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text2);
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .form-err {
            font-size: 11.5px;
            color: #b91c1c;
            margin-top: 8px;
            display: none;
        }

        /* ── Performance stats grid ── */
        .perf-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .perf-item {
            background: var(--surface);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .perf-value {
            font-size: 19px;
            font-weight: 800;
            color: var(--text1);
        }

        .perf-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text3);
            margin-top: 2px;
        }

        @media (min-width: 640px) {
            .settings-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
                align-items: start;
            }
        }

        /* Password requirements checklist (mirrors create-account.php) */
        .password-requirements {
            margin-top: 8px;
            font-size: 11px;
            line-height: 1.8;
        }

        .password-requirements.hidden {
            display: none;
        }

        .req-item {
            color: var(--text3);
            transition: color .15s;
        }

        .req-item.valid {
            color: #059669;
        }

        .password-match-msg {
            font-size: 11.5px;
            font-weight: 700;
            margin-top: 6px;
            min-height: 15px;
        }

        .password-match-msg.match {
            color: #059669;
        }

        .password-match-msg.no-match {
            color: #b91c1c;
        }

        [data-theme="dark"] .req-item.valid {
            color: #34d399;
        }

        [data-theme="dark"] .password-match-msg.match {
            color: #34d399;
        }

        [data-theme="dark"] .password-match-msg.no-match {
            color: #f87171;
        }

        [data-theme="dark"] .form-err {
            color: #f87171;
        }

        [data-theme="dark"] .card[style*="border-color:#fecaca"] {
            border-color: rgba(220, 38, 38, .35) !important;
        }

        [data-theme="dark"] #deactivate-account-btn {
            background: rgba(220, 38, 38, .15) !important;
            border-color: rgba(220, 38, 38, .35) !important;
            color: #f87171 !important;
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

    <!--  TOPBAR -->
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
                <h2>Profile &amp; Settings</h2>
                <p>Manage your account details and password</p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- Profile header -->
        <div class="profile-card fade-in d1">
            <div class="profile-avatar" id="profile-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="profile-info">
                <div class="profile-name" id="profile-name-display"><?= htmlspecialchars($evaluator_name) ?></div>
                <div class="profile-role">Evaluator &middot; Member since <?= htmlspecialchars($member_since_label) ?></div>
                <div class="profile-email">
                    <i class="fas fa-envelope" style="font-size:10px;"></i>
                    <?= htmlspecialchars($evaluator_email) ?>
                </div>
            </div>
        </div>

        <div class="settings-grid">
            <div>
                <!-- Performance snapshot -->
                <p class="sec-label fade-in d2">Snapshot</p>
                <div class="card fade-in d2">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            My Performance
                        </span>
                    </div>
                    <div class="perf-grid">
                        <div class="perf-item">
                            <div class="perf-value"><?= number_format($perf['active_now']) ?></div>
                            <div class="perf-label">Active assignments</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value"><?= number_format($perf['evaluated_total']) ?></div>
                            <div class="perf-label">Total evaluations made</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value" style="color:#059669;"><?= number_format($perf['approved']) ?></div>
                            <div class="perf-label">Approved</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value" style="color:#b91c1c;"><?= number_format($perf['disapproved']) ?></div>
                            <div class="perf-label">Disapproved</div>
                        </div>
                    </div>
                </div>

                <!-- Profile info form -->
                <p class="sec-label fade-in d3">Account Details</p>
                <div class="card fade-in d3">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--blue-lt)"></span>
                            Personal Information
                        </span>
                    </div>

                    <form id="profile-form">
                        <div class="form-group">
                            <label class="form-label" for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-input"
                                value="<?= htmlspecialchars($evaluator_name) ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="form-input"
                                    value="<?= htmlspecialchars($evaluator_phone) ?>" placeholder="e.g. 09171234567"
                                    maxlength="11" inputmode="numeric" pattern="\d{11}"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)"
                                    required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email_ro">Email Address</label>
                                <input type="text" id="email_ro" class="form-input" value="<?= htmlspecialchars($evaluator_email) ?>" disabled>
                            </div>
                        </div>
                        <div class="form-hint" style="margin-top:-8px; margin-bottom:14px;">
                            Email is tied to your login and can't be changed here. Contact an administrator if it needs to be updated.
                        </div>

                        <div id="profile-err" class="form-err"></div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="profile-save-btn">
                                <i class="fas fa-check"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div>
                <!-- Change password -->
                <p class="sec-label fade-in d4">Security</p>
                <div class="card fade-in d4">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:#d97706"></span>
                            Change Password
                        </span>
                    </div>

                    <form id="password-form">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-input" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-input" required autocomplete="new-password">
                            <div id="password-requirements" class="password-requirements hidden">
                                <div id="req-length" class="req-item">• At least 8 characters</div>
                                <div id="req-upper" class="req-item">• At least one uppercase letter</div>
                                <div id="req-lower" class="req-item">• At least one lowercase letter</div>
                                <div id="req-number" class="req-item">• At least one number</div>
                                <div id="req-special" class="req-item">• At least one special character</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required autocomplete="new-password">
                            <div id="password-match" class="password-match-msg"></div>
                        </div>

                        <div id="password-err" class="form-err"></div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="password-save-btn">
                                <i class="fas fa-key"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>
                <!-- Danger zone -->
                <p class="sec-label fade-in d5">Danger Zone</p>
                <div class="card fade-in d5" style="border-color:#fecaca;">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:#dc2626"></span>
                            Deactivate Account
                        </span>
                    </div>
                    <p style="font-size:12px;color:var(--text2);line-height:1.6;margin-bottom:14px;">
                        Deactivating your account will immediately log you out and prevent you from logging back in.
                        You'll need to submit a reactivation request (or ask an admin) to regain access later.
                    </p>
                    <div class="form-actions">
                        <button type="button" class="btn" id="deactivate-account-btn"
                            style="background:#fef2f2;border-color:#fecaca;color:#dc2626;">
                            <i class="fas fa-user-slash"></i> Deactivate My Account
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /main -->

    <!-- FLOATING DOCK  (exact index.php structure -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
                <?php if ($my_assigned_count > 0): ?>
                    <span class="dk-badge"><?= $my_assigned_count > 99 ? '99+' : $my_assigned_count ?></span>
                <?php endif; ?>
            </a>
            <div class="dk-div"></div>

            <a href="evaluator-reports.php" class="dk" data-tip="Applicant Reports">
                <i class="fas fa-file-contract"></i>
                <span class="dk-lbl">Applicants</span>
            </a>

            <a href="reports.php" class="dk">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>

            <div class="dk-div"></div>

            <a href="settings.php" class="dk active">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>

            <div class="dk" onclick="logout('Evaluator')">
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
        setInterval(updateLiveDateTime, 1000); // refresh every second

        function showErr(id, msg) {
            const el = document.getElementById(id);
            if (!el) return;
            if (!msg) {
                el.textContent = '';
                el.style.display = 'none';
            } else {
                el.textContent = msg;
                el.style.display = 'block';
            }
        }

        /*  Update profile  */
        document.getElementById('profile-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            showErr('profile-err', '');
            const btn = document.getElementById('profile-save-btn');
            btn.disabled = true;

            const fd = new FormData(this);
            fd.append('action', 'update_profile');

            fetch('settings.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.ok) {
                        showErr('profile-err', data.error || 'Something went wrong.');
                        return;
                    }
                    document.getElementById('profile-name-display').textContent = data.full_name;
                    document.getElementById('profile-avatar').textContent = data.initials;
                    const tbAvatar = document.getElementById('avatarMenuBtn');
                    if (tbAvatar) tbAvatar.textContent = data.initials;
                    const menuName = document.getElementById('avatarMenuName');
                    if (menuName) menuName.textContent = data.full_name;
                    Swal.fire({
                        icon: 'success',
                        title: 'Profile updated',
                        text: data.message,
                        confirmButtonColor: '#3b82f6',
                        timer: 2200,
                        timerProgressBar: true
                    });
                })
                .catch(() => {
                    btn.disabled = false;
                    showErr('profile-err', 'Network error. Please try again.');
                });
        });

        /* Change password  */
        document.getElementById('password-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            showErr('password-err', '');

            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            if (newPass !== confirmPass) {
                showErr('password-err', 'New password and confirmation do not match.');
                return;
            }

            const btn = document.getElementById('password-save-btn');
            btn.disabled = true;

            const fd = new FormData(this);
            fd.append('action', 'change_password');

            fetch('settings.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.ok) {
                        showErr('password-err', data.error || 'Something went wrong.');
                        return;
                    }
                    document.getElementById('password-form').reset();
                    Swal.fire({
                        icon: 'success',
                        title: 'Password updated',
                        text: data.message,
                        confirmButtonColor: '#3b82f6',
                        timer: 2200,
                        timerProgressBar: true
                    });
                })
                .catch(() => {
                    btn.disabled = false;
                    showErr('password-err', 'Network error. Please try again.');
                });
        });

        /*  Self-service deactivation  */
        document.getElementById('deactivate-account-btn').addEventListener('click', function() {
            const reasons = [
                'Taking a break / temporary leave',
                'No longer serving as evaluator',
                'Privacy or security concern',
                'Other'
            ];
            const optionsHtml = reasons.map(r => `<option value="${r}">${r}</option>`).join('');

            Swal.fire({
                title: 'Deactivate Your Account',
                html: `
            <div style="text-align:left;font-size:13px;">
                <label style="display:block;font-weight:700;font-size:11.5px;margin-bottom:6px;color:#475569;">Reason</label>
                <div class="dmw-select-wrap">
                    <select id="swal-reason" class="dmw-select">
                        <option value="">— Select a reason —</option>
                        ${optionsHtml}
                    </select>
                    <i class="fas fa-chevron-down dmw-select-arrow"></i>
                </div>
                <div id="swal-other-wrap" style="display:none;">
                    <label style="display:block;font-weight:700;font-size:11.5px;margin-bottom:6px;color:#475569;">Please specify</label>
                    <input id="swal-other" class="swal2-input" style="margin:0 0 12px;width:100%;" placeholder="Enter specific reason...">
                </div>
                <label style="display:block;font-weight:700;font-size:11.5px;margin-bottom:6px;color:#475569;">Confirm Password</label>
                <input id="swal-password" type="password" class="swal2-input" style="margin:0;width:100%;" placeholder="Enter your password">
            </div>
        `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Deactivate Account',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel',
                focusConfirm: false,
                didOpen: () => {
                    const select = document.getElementById('swal-reason');
                    select.addEventListener('change', function() {
                        document.getElementById('swal-other-wrap').style.display = this.value === 'Other' ? 'block' : 'none';
                    });
                },
                preConfirm: () => {
                    const reason = document.getElementById('swal-reason').value;
                    const other = document.getElementById('swal-other').value.trim();
                    const password = document.getElementById('swal-password').value;

                    if (!reason) {
                        Swal.showValidationMessage('Please select a reason.');
                        return false;
                    }
                    if (reason === 'Other' && other.length < 5) {
                        Swal.showValidationMessage('Please specify a reason (at least 5 characters).');
                        return false;
                    }
                    if (!password) {
                        Swal.showValidationMessage('Please enter your password to confirm.');
                        return false;
                    }
                    return {
                        reason,
                        other,
                        password
                    };
                }
            }).then((result) => {
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

                fetch('settings.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.ok) {
                            Swal.fire('Unable to deactivate', data.error || 'Something went wrong.', 'error');
                            return;
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Account Deactivated',
                            text: data.message,
                            confirmButtonColor: '#3b82f6'
                        }).then(() => {
                            window.location.href = '../login.php';
                        });
                    })
                    .catch(() => {
                        Swal.fire('Network error', 'Please try again.', 'error');
                    });
            });
        });
    </script>
    <script>
        /*  Password strength & match indicators (mirrors create-account.php)  */
        (function() {
            const pwdInput = document.getElementById('new_password');
            const confirmInput = document.getElementById('confirm_password');
            const requirementsBox = document.getElementById('password-requirements');
            const matchMsg = document.getElementById('password-match');

            if (!pwdInput || !confirmInput) return;

            const reqElements = {
                length: document.getElementById('req-length'),
                upper: document.getElementById('req-upper'),
                lower: document.getElementById('req-lower'),
                number: document.getElementById('req-number'),
                special: document.getElementById('req-special')
            };

            function updateRequirements(value) {
                const checks = {
                    length: value.length >= 8,
                    upper: /[A-Z]/.test(value),
                    lower: /[a-z]/.test(value),
                    number: /[0-9]/.test(value),
                    special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(value)
                };
                for (const [key, ok] of Object.entries(checks)) {
                    reqElements[key].className = ok ? 'req-item valid' : 'req-item';
                }
                requirementsBox.classList.remove('hidden');
            }

            function checkMatch() {
                if (!confirmInput.value) {
                    matchMsg.textContent = '';
                    matchMsg.className = 'password-match-msg';
                    return;
                }
                if (pwdInput.value === confirmInput.value) {
                    matchMsg.textContent = '✓ Passwords match';
                    matchMsg.className = 'password-match-msg match';
                } else {
                    matchMsg.textContent = '✗ Passwords do not match';
                    matchMsg.className = 'password-match-msg no-match';
                }
            }

            pwdInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));
            confirmInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));

            [pwdInput, confirmInput].forEach(input => {
                input.addEventListener('blur', () => {
                    setTimeout(() => {
                        if (document.activeElement !== pwdInput && document.activeElement !== confirmInput) {
                            requirementsBox.classList.add('hidden');
                        }
                    }, 100);
                });
            });

            pwdInput.addEventListener('input', () => {
                updateRequirements(pwdInput.value);
                checkMatch();
            });
            confirmInput.addEventListener('input', checkMatch);
        })();
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
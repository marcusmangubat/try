<?php
// login.php
// Department of Migrant Workers – Application Management System
// Regional Office IV-A CALABARZON

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

require_once 'db.php';

date_default_timezone_set('Asia/Manila');

// LOGIN ATTEMPTS CONFIG
const MAX_LOGIN_ATTEMPTS   = 5;   // failed attempts allowed
const LOCKOUT_WINDOW_MIN   = 15;  // minutes the failed attempts are counted over
const LOCKOUT_DURATION_MIN = 15;  // minutes the account/IP stays locked once the limit is hit

// Only set this if your host is behind a proxy you trust, e.g. 'HTTP_CF_CONNECTING_IP' (Cloudflare).
// Leave empty to use REMOTE_ADDR (cannot be spoofed by the client).
const TRUSTED_IP_HEADER = '';

/**
 * Get the client IP. Forwarded headers are only trusted when explicitly configured.
 */
function get_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (TRUSTED_IP_HEADER !== '' && !empty($_SERVER[TRUSTED_IP_HEADER])) {
        $candidate = trim(explode(',', $_SERVER[TRUSTED_IP_HEADER])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Record a login attempt (success or failure) in the login_attempts table.
 */
function record_login_attempt(mysqli $database, string $email, string $ip, bool $success): void
{
    $stmt = $database->prepare(
        "INSERT INTO login_attempts (email, ip_address, user_agent, success, attempted_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $userAgent  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $successInt = $success ? 1 : 0;
    $stmt->bind_param("sssi", $email, $ip, $userAgent, $successInt);
    $stmt->execute();
    $stmt->close();
}

/**
 * Count failed attempts for this email+IP within the lockout window.
 * Only counts failures AFTER the most recent success, so a good login resets the counter.
 */
function count_recent_failed_attempts(mysqli $database, string $email, string $ip): int
{
    $stmt = $database->prepare(
        "SELECT COUNT(*) AS cnt FROM login_attempts
         WHERE email = ? AND ip_address = ? AND success = 0
           AND attempted_at >= (NOW() - INTERVAL ? MINUTE)
           AND attempted_at > COALESCE((
                SELECT MAX(attempted_at) FROM login_attempts
                WHERE email = ? AND ip_address = ? AND success = 1
           ), '1970-01-01')"
    );
    $windowMin = LOCKOUT_WINDOW_MIN;
    // email, ip, minutes, email, ip  ->  s s i s s
    $stmt->bind_param("ssiss", $email, $ip, $windowMin, $email, $ip);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

/**
 * Returns minutes remaining on the lockout, or 0 if not locked out.
 * The time math is done in MySQL so PHP/MySQL timezone differences cannot break it.
 */
function get_lockout_minutes_remaining(mysqli $database, string $email, string $ip): int
{
    if (count_recent_failed_attempts($database, $email, $ip) < MAX_LOGIN_ATTEMPTS) {
        return 0;
    }

    $stmt = $database->prepare(
        "SELECT TIMESTAMPDIFF(
                    SECOND,
                    NOW(),
                    DATE_ADD(MAX(attempted_at), INTERVAL ? MINUTE)
                ) AS seconds_left
         FROM login_attempts
         WHERE email = ? AND ip_address = ? AND success = 0
           AND attempted_at >= (NOW() - INTERVAL ? MINUTE)"
    );
    $duration  = LOCKOUT_DURATION_MIN;
    $windowMin = LOCKOUT_WINDOW_MIN;
    // duration, email, ip, window  ->  i s s i
    $stmt->bind_param("issi", $duration, $email, $ip, $windowMin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $secondsLeft = (int) ($row['seconds_left'] ?? 0);
    return $secondsLeft > 0 ? (int) ceil($secondsLeft / 60) : 0;
}

/**
 * Check if this email already has a pending reactivation request.
 */
function has_pending_reactivation_request(mysqli $database, string $email): bool
{
    $stmt = $database->prepare("SELECT id FROM reactivation_requests WHERE email = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

$error = '';
$email_value = '';
$show_reactivation_modal = false;
$deactivated_email = '';
$reactivation_success = false;
$reactivation_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // REACTIVATION REQUEST SUBMISSION
    if (($_POST['form_type'] ?? '') === 'reactivation') {

        $r_email    = trim($_POST['reactivation_email'] ?? '');
        $r_password = $_POST['reactivation_password'] ?? '';
        $r_reason   = trim($_POST['reactivation_reason'] ?? '');

        if (empty($r_email) || empty($r_password)) {
            $error = "Please enter your email and password to verify your identity.";
            $show_reactivation_modal = true;
            $deactivated_email = $r_email;
        } elseif (mb_strlen($r_reason) < 10) {
            $error = "Please provide a reason for reactivation (at least 10 characters).";
            $show_reactivation_modal = true;
            $deactivated_email = $r_email;
        } else {
            $stmt = $database->prepare("SELECT email, usertype, password, is_active FROM webuser WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $r_email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->num_rows === 1 ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user || !password_verify($r_password, $user['password'])) {
                $error = "Invalid email or password.";
                $show_reactivation_modal = true;
                $deactivated_email = $r_email;
            } elseif (!in_array($user['usertype'], ['client', 'evaluator'], true)) {
                $error = "This account type cannot request reactivation.";
            } elseif ((int)$user['is_active'] === 1) {
                $error = "This account is already active. Please log in normally.";
            } elseif (has_pending_reactivation_request($database, $r_email)) {
                $error = "You already have a pending reactivation request. Please wait for admin review.";
            } else {
                $stmt = $database->prepare("INSERT INTO reactivation_requests (email, usertype, reason) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $r_email, $user['usertype'], $r_reason);

                if ($stmt->execute()) {
                    $stmt->close();

                    // Look up a display name for the admin notification
                    $display_name = $r_email;
                    if ($user['usertype'] === 'evaluator') {
                        $n = $database->prepare("SELECT full_name FROM evaluators WHERE email = ? LIMIT 1");
                    } else {
                        $n = $database->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM clients WHERE email = ? LIMIT 1");
                    }
                    $n->bind_param("s", $r_email);
                    $n->execute();
                    $nrow = $n->get_result()->fetch_assoc();
                    $n->close();
                    if (!empty($nrow['full_name'])) {
                        $display_name = trim($nrow['full_name']);
                    }

                    // Notify all admins
                    $admin_stmt = $database->prepare("SELECT email FROM admins");
                    $admin_stmt->execute();
                    $admins = $admin_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $admin_stmt->close();

                    $notif_title = "New Reactivation Request";
                    $notif_msg   = htmlspecialchars($display_name) . " (" . $r_email . ") has requested account reactivation.";
                    foreach ($admins as $adm) {
                        $nstmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, 'admin', ?, ?, 'account_status')");
                        $nstmt->bind_param("sss", $adm['email'], $notif_title, $notif_msg);
                        $nstmt->execute();
                        $nstmt->close();
                    }

                    $reactivation_success = true;
                    $reactivation_message = "Your reactivation request has been submitted. An administrator will review it shortly.";
                } else {
                    $stmt->close();
                    $error = "Something went wrong while submitting your request. Please try again.";
                    $show_reactivation_modal = true;
                    $deactivated_email = $r_email;
                }
            }
        }
    }

    // NORMAL LOGIN SUBMISSION
    elseif (($_POST['form_type'] ?? 'login') === 'login') {

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip       = get_client_ip();

        $email_value = $email;

        if (empty($email) || empty($password)) {
            $error = "Please enter both email and password.";
        } else {

            $lockoutMinutes = get_lockout_minutes_remaining($database, $email, $ip);

            if ($lockoutMinutes > 0) {
                $error = "Too many failed login attempts. Please try again in "
                    . $lockoutMinutes . " minute" . ($lockoutMinutes === 1 ? "" : "s") . ".";
            } else {

                $stmt = $database->prepare("SELECT email, usertype, password, is_active, deactivation_reason FROM webuser WHERE email = ? LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($password, $user['password'])) {

                        record_login_attempt($database, $email, $ip, true);

                        // ── Blocked: account deactivated ──
                        if ((int)$user['is_active'] === 0) {
                            if (has_pending_reactivation_request($database, $email)) {
                                $error = "Your account is deactivated and you already have a reactivation request pending admin review.";
                            } else {
                                $error = "Your account has been deactivated"
                                    . (!empty($user['deactivation_reason']) ? " (Reason: " . $user['deactivation_reason'] . ")" : "")
                                    . ". You may request reactivation below.";
                                $show_reactivation_modal = true;
                                $deactivated_email = $email;
                            }
                        } else {

                            session_regenerate_id(true);
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_type']  = $user['usertype'];
                            $_SESSION['login_success'] = true;

                            $stmt_name = $database->prepare("
                                SELECT CONCAT(first_name, ' ', last_name) AS full_name 
                                FROM clients WHERE email = ? LIMIT 1
                            ");
                            $stmt_name->bind_param("s", $email);
                            $stmt_name->execute();
                            $name_result = $stmt_name->get_result();
                            if ($name_result->num_rows === 1) {
                                $_SESSION['full_name'] = $name_result->fetch_assoc()['full_name'];
                            }
                            $stmt_name->close();

                            if (isset($_POST['remember']) && $_POST['remember'] === 'on') {
                                $token   = bin2hex(random_bytes(32));
                                $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
                                $stmt_token = $database->prepare("UPDATE webuser SET remember_token = ?, remember_expires = ? WHERE email = ?");
                                $stmt_token->bind_param("sss", $token, $expires, $email);
                                $stmt_token->execute();
                                $stmt_token->close();

                                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

                                setcookie('remember_token', $token, [
                                    'expires'  => time() + (30 * 24 * 60 * 60),
                                    'path'     => '/',
                                    'secure'   => $isHttps,
                                    'httponly' => true,
                                    'samesite' => 'Strict'
                                ]);
                            }

                            $redirect_map = [
                                'client'    => 'client/index.php',
                                'admin'     => 'admin/dashboard.php',
                                'evaluator' => 'evaluator/index.php',
                            ];

                            if (isset($redirect_map[$user['usertype']])) {
                                header("Location: " . $redirect_map[$user['usertype']]);
                                exit;
                            } else {
                                $error = "Account type not supported.";
                            }
                        }
                    } else {
                        record_login_attempt($database, $email, $ip, false);
                        $error = "Invalid email or password.";

                        $remaining = MAX_LOGIN_ATTEMPTS - count_recent_failed_attempts($database, $email, $ip);
                        if ($remaining > 0 && $remaining <= 2) {
                            $error .= " You have " . $remaining . " attempt" . ($remaining === 1 ? "" : "s") . " left before your account is temporarily locked.";
                        }
                    }
                } else {
                    record_login_attempt($database, $email, $ip, false);
                    $error = "Invalid email or password.";
                }
                $stmt->close();
            }
        }
    }
}

// ── Attempts used so far, for display under the password field ──
$attemptsUsed = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($email_value)) {
    $attemptsUsed = min(
        MAX_LOGIN_ATTEMPTS,
        count_recent_failed_attempts($database, $email_value, get_client_ip())
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMW RO IV-A – Sign In</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <style>
        :root {
            --navy: #0b1f4b;
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }

        .serif {
            font-family: 'DM Serif Display', serif;
        }

        /* ─── BACKGROUND ─── */
        .bg-image {
            background-image: url('pictures/dmw.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .overlay {
            background: linear-gradient(135deg, rgba(11, 31, 75, 0.90), rgba(22, 45, 106, 0.85));
            position: fixed;
            inset: 0;
            z-index: 10;
        }

        /* ─── INPUTS & BUTTONS ─── */
        .input-field {
            transition: all 0.25s ease;
        }

        .input-field:focus {
            border-color: var(--blue-lt);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18);
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--navy), var(--blue));
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px -8px rgb(11 31 75 / 0.5);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /*MOBILE / PORTRAIT (default, < lg)
           Single-column card */
        .page-wrapper {
            position: relative;
            z-index: 20;
            width: 100%;
            max-width: 32rem;
            padding: 1rem;
        }

        .layout-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 25px 70px -15px rgb(11 31 75 / 0.45);
            border-radius: 1.5rem;
            overflow: hidden;
        }

        /* left brand panel — hidden on mobile */
        .brand-panel {
            display: none;
        }

        /* mobile top bar */
        .mobile-top-bar {
            display: flex;
            background: var(--navy);
            padding: 1.1rem 1.5rem;
            align-items: center;
            gap: 0.9rem;
        }

        /* form panel */
        .form-panel {
            padding: 2rem;
        }

        /*   DESKTOP / LANDSCAPE (≥ 1024 px)
           Two-column: brand left, form right */
        @media (min-width: 1024px) {
            .bg-image {
                padding: 1.5rem;
            }

            .page-wrapper {
                max-width: 64rem;
                /* narrower than register since login is simpler */
                padding: 0;
            }

            .layout-card {
                display: flex;
                flex-direction: row;
                border-radius: 2rem;
                min-height: 70vh;
            }

            /*  Left brand panel  */
            .brand-panel {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                width: 42%;
                flex-shrink: 0;
                background: linear-gradient(160deg, var(--navy) 0%, #1a3a7c 60%, #1d4ed8 100%);
                padding: 3rem 2.5rem;
                position: relative;
                overflow: hidden;
            }

            .brand-panel::before,
            .brand-panel::after {
                content: '';
                position: absolute;
                border-radius: 50%;
                opacity: 0.08;
                background: white;
            }

            .brand-panel::before {
                width: 320px;
                height: 320px;
                bottom: -70px;
                right: -90px;
            }

            .brand-panel::after {
                width: 180px;
                height: 180px;
                top: -45px;
                left: -55px;
            }

            .brand-panel .brand-dots {
                position: absolute;
                inset: 0;
                background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
                background-size: 28px 28px;
            }

            /* ── Right form panel ── */
            .mobile-top-bar {
                display: none;
            }

            .form-panel {
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: 3rem 3.5rem;
            }
        }

        @media (min-width: 1280px) {
            .brand-panel {
                padding: 3.5rem 3rem;
            }

            .form-panel {
                padding: 3.5rem 4rem;
            }
        }
    </style>
</head>

<body class="bg-image">

    <div class="overlay"></div>

    <div class="page-wrapper">
        <div class="layout-card">

            <!--  LEFT BRAND PANEL (desktop only)  -->
            <div class="brand-panel">
                <div class="brand-dots"></div>

                <div class="relative z-10">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                        alt="DMW Logo" class="h-16 w-auto mb-6">
                    <h1 class="serif text-white text-3xl leading-tight mb-2">
                        Department of<br>Migrant Workers
                    </h1>
                    <p class="text-blue-200 text-sm tracking-widest uppercase mb-8">
                        Regional Office IV-A<br>CALABARZON
                    </p>

                    <div class="border-t border-white/20 pt-6 space-y-5">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-user-shield text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Secure Access</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Your session is protected and encrypted.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-clock-rotate-left text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Track Applications</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Monitor the status of your DMW applications in real time.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-bell text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Get Notified</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Receive updates and alerts about your account activity.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative z-10 mt-8">
                    <p class="text-blue-300 text-xs">© <?= date("Y") ?> Department of Migrant Workers</p>
                </div>
            </div>

            <!--  RIGHT FORM PANEL  -->
            <div class="form-panel">

                <!-- Mobile top bar (hidden on desktop) -->
                <div class="mobile-top-bar -mx-8 -mt-8 mb-6 px-6 py-4">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                        alt="DMW Logo" class="h-9 w-auto">
                    <div>
                        <h1 class="serif text-white text-base font-semibold leading-tight">Department of Migrant Workers</h1>
                        <p class="text-blue-200 text-xs tracking-widest">Regional Office IV-A CALABARZON</p>
                    </div>
                </div>

                <h2 class="serif text-2xl font-semibold text-[var(--navy)] mb-1">Welcome!</h2>
                <p class="text-slate-500 text-sm mb-8">Sign in to your Portal</p>

                <?php if ($error): ?>
                    <div class="mb-6 rounded-2xl bg-red-50 border border-red-200 px-5 py-3 text-red-700 text-sm">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php $isLockedOut = isset($lockoutMinutes) && $lockoutMinutes > 0; ?>

                <form method="POST" action="" class="space-y-5">
                    <input type="hidden" name="form_type" value="login">

                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">
                            EMAIL ADDRESS
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input type="email" name="email" id="email" required
                                value="<?= htmlspecialchars($email_value) ?>"
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                placeholder="juandelacruz@example.com">
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">
                            PASSWORD
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input type="password" name="password" id="password" required
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-12 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                placeholder="••••••••">
                            <button type="button" id="togglePassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                                <i class="fas fa-eye text-sm" id="eyeIcon"></i>
                            </button>
                        </div>
                        <?php if ($attemptsUsed > 0): ?>
                            <p class="mt-2 text-xs font-medium <?= $attemptsUsed >= MAX_LOGIN_ATTEMPTS ? 'text-red-600' : 'text-amber-600' ?>">
                                Login attempts: <?= $attemptsUsed ?>/<?= MAX_LOGIN_ATTEMPTS ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Remember me / Forgot password -->
                    <div class="flex items-center justify-between text-sm pt-1">
                        <label class="flex items-center gap-2 cursor-pointer text-slate-600 select-none">
                            <input type="checkbox" name="remember"
                                class="w-4 h-4 rounded border-slate-300 accent-[var(--blue)]">
                            <span class="font-medium">Remember me</span>
                        </label>
                        <a href="reset-password.php"
                            class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] transition-colors text-xs">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" <?= $isLockedOut ? 'disabled' : '' ?>
                        class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider mt-2">
                        SIGN IN
                    </button>

                </form>

                <div class="text-center mt-7 text-sm text-slate-600">
                    New applicant?
                    <a href="create-account.php"
                        class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] transition-colors">
                        Create account
                    </a>
                </div>

                <div class="text-center mt-3 text-xs text-slate-500">
                    Account deactivated?
                    <button type="button" id="openReactivationModal"
                        class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] transition-colors underline bg-transparent border-none cursor-pointer">
                        Request reactivation
                    </button>
                </div>

                <!-- Mobile copyright -->
                <p class="lg:hidden text-center mt-6 text-xs text-slate-400">
                    © <?= date("Y") ?> Department of Migrant Workers • Regional Office IV-A
                </p>

            </div><!-- /form-panel -->
        </div><!-- /layout-card -->
    </div><!-- /page-wrapper -->

    <script>
        document.getElementById('togglePassword').addEventListener('click', function() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    </script>

    <!--  REACTIVATION REQUEST MODAL  -->
    <div id="reactivationModal" style="display:<?= $show_reactivation_modal ? 'flex' : ($reactivation_success ? 'flex' : 'none') ?>;
        position:fixed; inset:0; background:rgba(11,31,75,.6); backdrop-filter:blur(4px); z-index:500;
        align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:22px; width:100%; max-width:440px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.2);">
            <div style="padding:22px 26px 18px; background:linear-gradient(135deg, var(--navy) 0%, #1e3a8a 100%); position:relative;">
                <h3 style="font-size:17px; font-weight:800; color:#fff; margin:0;">
                    <i class="fas fa-rotate-left" style="margin-right:8px; opacity:.8;"></i>Request Account Reactivation
                </h3>
                <p style="font-size:12px; color:rgba(255,255,255,.65); margin:4px 0 0;">
                    Verify your identity and tell us why you'd like your account reactivated.
                </p>
                <button type="button" onclick="closeReactivationModal()"
                    style="position:absolute; top:18px; right:20px; width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,.15); border:none; cursor:pointer; color:#fff; font-size:16px; display:flex; align-items:center; justify-content:center;">×</button>
            </div>

            <?php if ($reactivation_success): ?>
                <div style="padding:26px; text-align:center;">
                    <i class="fas fa-circle-check" style="font-size:32px; color:#059669; margin-bottom:10px;"></i>
                    <p style="font-size:13.5px; color:#0f172a; font-weight:600; margin-bottom:6px;">Request Submitted</p>
                    <p style="font-size:12.5px; color:#64748b;"><?= htmlspecialchars($reactivation_message) ?></p>
                    <button type="button" onclick="closeReactivationModal()"
                        style="margin-top:16px; padding:10px 24px; border-radius:10px; background:var(--blue); color:#fff; border:none; font-weight:700; font-size:13px; cursor:pointer;">
                        Close
                    </button>
                </div>
            <?php else: ?>
                <form method="POST" action="" style="padding:24px 26px;">
                    <input type="hidden" name="form_type" value="reactivation">

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:7px;">Email Address</label>
                        <input type="email" name="reactivation_email" required
                            value="<?= htmlspecialchars($deactivated_email) ?>"
                            style="width:100%; padding:11px 14px; border:1.5px solid #e2e8f0; border-radius:11px; font-family:'DM Sans',sans-serif; font-size:13px;">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:7px;">Password</label>
                        <input type="password" name="reactivation_password" required
                            style="width:100%; padding:11px 14px; border:1.5px solid #e2e8f0; border-radius:11px; font-family:'DM Sans',sans-serif; font-size:13px;">
                        <p style="font-size:10.5px; color:#94a3b8; margin-top:5px;">Used to verify this is your account.</p>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:7px;">Reason for Reactivation</label>
                        <textarea name="reactivation_reason" required rows="3"
                            style="width:100%; padding:11px 14px; border:1.5px solid #e2e8f0; border-radius:11px; font-family:'DM Sans',sans-serif; font-size:13px; resize:vertical;"
                            placeholder="Tell the admin why you'd like your account reactivated (at least 10 characters)..."></textarea>
                    </div>

                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                        <button type="button" onclick="closeReactivationModal()"
                            style="padding:10px 20px; border-radius:10px; border:1.5px solid #e2e8f0; background:#f8fafc; color:#475569; font-weight:600; font-size:13px; cursor:pointer;">Cancel</button>
                        <button type="submit"
                            style="padding:10px 24px; border-radius:10px; background:linear-gradient(135deg, var(--blue), #4f46e5); color:#fff; border:none; font-weight:700; font-size:13px; cursor:pointer;">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function closeReactivationModal() {
            document.getElementById('reactivationModal').style.display = 'none';
        }
        document.getElementById('openReactivationModal').addEventListener('click', function() {
            document.getElementById('reactivationModal').style.display = 'flex';
        });
        document.getElementById('reactivationModal').addEventListener('click', function(e) {
            if (e.target === this) closeReactivationModal();
        });
    </script>
</body>

</html>
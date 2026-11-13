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
require_once 'auth.php';

$authenticated_redirects = [
    'client'    => 'client/index.php',
    'admin'     => 'admin/dashboard.php',
    'evaluator' => 'evaluator/dashboard.php',
];
if (
    isset($_SESSION['user_email'], $_SESSION['user_type'])
    && isset($authenticated_redirects[$_SESSION['user_type']])
) {
    header('Location: ' . $authenticated_redirects[$_SESSION['user_type']]);
    exit;
}


date_default_timezone_set('Asia/Manila');

// LOGIN ATTEMPTS CONFIG
const MAX_LOGIN_ATTEMPTS   = 5;   // failed attempts allowed
const LOCKOUT_WINDOW_MIN   = 15;  // minutes the failed attempts are counted over
const LOCKOUT_DURATION_MIN = 15;  // minutes the account/IP stays locked once the limit is hit

/**
 * Get the real client IP, accounting for common proxy headers.
 */
function get_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $successInt = $success ? 1 : 0;
    $stmt->bind_param("sssi", $email, $ip, $userAgent, $successInt);
    $stmt->execute();
    $stmt->close();
}

/**
 * Count failed attempts for this email+IP within the lockout window.
 * Only counts failures that happened AFTER the most recent success,
 * so a good login resets the counter naturally.
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
    $stmt->bind_param("sisis", $email, $ip, $windowMin, $email, $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = (int) $result->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

/**
 * Returns minutes remaining on the lockout, or 0 if not locked out.
 */
function get_lockout_minutes_remaining(mysqli $database, string $email, string $ip): int
{
    $failedCount = count_recent_failed_attempts($database, $email, $ip);

    if ($failedCount < MAX_LOGIN_ATTEMPTS) {
        return 0;
    }

    // Find when the (MAX_LOGIN_ATTEMPTS)-th most recent qualifying failure happened,
    // lockout counts down from that attempt.
    $stmt = $database->prepare(
        "SELECT attempted_at FROM login_attempts
         WHERE email = ? AND ip_address = ? AND success = 0
           AND attempted_at >= (NOW() - INTERVAL ? MINUTE)
         ORDER BY attempted_at DESC
         LIMIT 1"
    );
    $windowMin = LOCKOUT_WINDOW_MIN;
    $stmt->bind_param("sis", $email, $ip, $windowMin);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return 0;
    }

    $lastFailedAt = strtotime($row['attempted_at']);
    $lockoutEnds  = $lastFailedAt + (LOCKOUT_DURATION_MIN * 60);
    $secondsLeft  = $lockoutEnds - time();

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

// Flash message set by client/profile.php after self-deactivation
$info_message = '';
if (isset($_SESSION['login_message'])) {
    $info_message = $_SESSION['login_message'];
    unset($_SESSION['login_message']);
}

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
                    $reactivation_message = "Your reactivation request has been submitted. The administrator will review it and notify you by email whether it is approved or declined.";
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
                                $error = "Your account is deactivated and you already have a reactivation request pending admin review. You will be notified by email once it is approved or declined.";
                            } else {
                                $error = "Your account has been deactivated"
                                    . (!empty($user['deactivation_reason']) ? " (Reason: " . $user['deactivation_reason'] . ")" : "")
                                    . ". You may submit a reactivation request below. The administrator will review it and notify you by email whether it is approved or declined.";
                                $show_reactivation_modal = true;
                                $deactivated_email = $email;
                            }
                        } else {

                            session_regenerate_id(true);
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_type']  = $user['usertype'];
                            $_SESSION['login_success'] = true;
                            $_SESSION['login_time'] = time();

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
                                setcookie('remember_token', $token, [
                                    'expires'  => time() + (30 * 24 * 60 * 60),
                                    'path'     => '/',
                                    'secure'   => false,
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
    <script>
        // Same palette as index.php
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: {
                            50: '#F2F5FA',
                            100: '#E3EAF5',
                            200: '#C5D2E8',
                            300: '#9DB1D3',
                            400: '#6C88B8',
                            500: '#44639A',
                            600: '#2A4577',
                            700: '#1B3563',
                            800: '#122A55',
                            900: '#0A1F44',
                            950: '#061330'
                        },
                        blue: {
                            50: '#F2F5FA',
                            100: '#E3EAF5',
                            200: '#C5D2E8',
                            300: '#9DB1D3',
                            400: '#6C88B8',
                            500: '#44639A',
                            600: '#2A4577',
                            700: '#1B3563',
                            800: '#122A55',
                            900: '#0A1F44',
                            950: '#061330'
                        },
                        gray: {
                            50: '#F5F7FA',
                            100: '#EAEEF4',
                            200: '#D8DFEA',
                            300: '#BCC6D6',
                            400: '#8E9BB1',
                            500: '#64718A',
                            600: '#4A5670',
                            700: '#36415A',
                            800: '#222C44',
                            900: '#121B30'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="pictures/dmw_logo.png">
    <style>
        :root {
            --primary: #0A1F44;
            --primary-dark: #00205f;
            --primary-light: #e6efff;
            --accent: #00a0df;
            --text: #1f2937;
            --navy: #0A1F44;
            --navy-700: #1B3563;
            --blue: #2A4577;
            --blue-lt: #44639A;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text);
            margin: 0;
            padding: 0;
        }

        .serif {
            font-family: 'Playfair Display', serif;
        }

        /* ─── BACKGROUND (same overlay as index hero) ─── */
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
            background: linear-gradient(rgba(10, 31, 68, 0.78), rgba(10, 31, 68, 0.9));
            position: fixed;
            inset: 0;
            z-index: 10;
        }

        @media (max-width: 1024px),
        (prefers-reduced-motion: reduce) {
            .bg-image {
                background-attachment: scroll;
            }
        }

        /* ─── INPUTS & BUTTONS ─── */
        .input-field {
            transition: all 0.25s ease;
        }

        .input-field:focus {
            border-color: var(--blue-lt);
            box-shadow: 0 0 0 4px rgba(68, 99, 154, 0.18);
        }

        .btn-primary {
            background: var(--navy);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--navy-700);
            transform: translateY(-2px);
            box-shadow: 0 16px 30px -8px rgb(10 31 68 / 0.5);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* MOBILE / PORTRAIT (default, < lg) */
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
            box-shadow: 0 25px 70px -15px rgb(10 31 68 / 0.45);
            border-radius: 1.5rem;
            overflow: hidden;
        }

        .brand-panel {
            display: none;
        }

        .mobile-top-bar {
            display: flex;
            background: var(--navy);
            padding: 1.1rem 1.5rem;
            align-items: center;
            gap: 0.9rem;
        }

        .form-panel {
            padding: 2rem;
        }

        /* DESKTOP / LANDSCAPE (≥ 1024 px) */
        @media (min-width: 1024px) {
            .bg-image {
                padding: 1.5rem;
            }

            .page-wrapper {
                max-width: 64rem;
                padding: 0;
            }

            .layout-card {
                display: flex;
                flex-direction: row;
                border-radius: 2rem;
                min-height: 70vh;
            }

            .brand-panel {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                width: 42%;
                flex-shrink: 0;
                background: linear-gradient(160deg, #0A1F44 0%, #122A55 60%, #1B3563 100%);
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
                    <img src="pictures/dmw_logo.png"
                        alt="DMW Logo" class="h-16 w-auto mb-6">
                    <h1 class="serif text-white text-3xl leading-tight mb-2">
                        Department of<br>Migrant Workers
                    </h1>
                    <p class="text-navy-200 text-sm tracking-widest uppercase mb-8">
                        Regional Office IV-A<br>CALABARZON
                    </p>

                    <div class="border-t border-white/20 pt-6 space-y-5">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-user-shield text-navy-200 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Secure Access</p>
                                <p class="text-navy-200 text-xs leading-relaxed">Your session is protected and encrypted.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-clock-rotate-left text-navy-200 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Track Applications</p>
                                <p class="text-navy-200 text-xs leading-relaxed">Monitor the status of your DMW applications in real time.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-bell text-navy-200 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Get Notified</p>
                                <p class="text-navy-200 text-xs leading-relaxed">Receive updates and alerts about your account activity.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative z-10 mt-8">
                    <p class="text-navy-300 text-xs">© <?= date("Y") ?> Department of Migrant Workers</p>
                </div>
            </div>

            <!--  RIGHT FORM PANEL  -->
            <div class="form-panel">

                <!-- Mobile top bar (hidden on desktop) -->
                <div class="mobile-top-bar -mx-8 -mt-8 mb-6 px-6 py-4">
                    <img src="pictures/dmw_logo.png"
                        alt="DMW Logo" class="h-9 w-auto">
                    <div>
                        <h1 class="serif text-white text-base font-semibold leading-tight">Department of Migrant Workers</h1>
                        <p class="text-navy-200 text-xs tracking-widest">Regional Office IV-A CALABARZON</p>
                    </div>
                </div>

                <h2 class="serif text-2xl font-bold text-[var(--navy)] mb-1">Welcome!</h2>
                <p class="text-gray-500 text-sm mb-8">Sign in to your Portal</p>

                <?php if ($info_message): ?>
                    <div class="mb-6 rounded-2xl bg-navy-50 border border-navy-200 px-5 py-3 text-navy-800 text-sm">
                        <?= htmlspecialchars($info_message) ?>
                    </div>
                <?php endif; ?>

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
                        <label class="block text-xs font-semibold tracking-widest text-gray-500 mb-2">
                            EMAIL ADDRESS
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input type="email" name="email" id="email" required
                                value="<?= htmlspecialchars($email_value) ?>"
                                class="input-field w-full bg-white border border-gray-300 rounded-2xl py-3.5 pl-11 pr-5 text-[var(--navy)] placeholder:text-gray-400 focus:outline-none text-sm"
                                placeholder="juandelacruz@example.com">
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-xs font-semibold tracking-widest text-gray-500 mb-2">
                            PASSWORD
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input type="password" name="password" id="password" required
                                class="input-field w-full bg-white border border-gray-300 rounded-2xl py-3.5 pl-11 pr-12 text-[var(--navy)] placeholder:text-gray-400 focus:outline-none text-sm"
                                placeholder="••••••••">
                            <button type="button" id="togglePassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
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
                        <label class="flex items-center gap-2 cursor-pointer text-gray-600 select-none">
                            <input type="checkbox" name="remember"
                                class="w-4 h-4 rounded border-gray-300 accent-[var(--navy)]">
                            <span class="font-medium">Remember me</span>
                        </label>
                        <a href="reset-password.php"
                            class="font-semibold text-[var(--blue-lt)] hover:text-[var(--navy)] transition-colors text-xs">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" <?= $isLockedOut ? 'disabled' : '' ?>
                        class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider mt-2 shadow-lg shadow-blue-900/30">
                        SIGN IN
                    </button>

                </form>

                <div class="text-center mt-7 text-sm text-gray-600">
                    New applicant?
                    <a href="create-account.php"
                        class="font-semibold text-[var(--blue-lt)] hover:text-[var(--navy)] transition-colors">
                        Create account
                    </a>
                </div>

                <div class="text-center mt-3 text-xs text-gray-500">
                    Account deactivated?
                    <button type="button" id="openReactivationModal"
                        class="font-semibold text-[var(--blue-lt)] hover:text-[var(--navy)] transition-colors underline bg-transparent border-none cursor-pointer">
                        Request reactivation
                    </button>
                </div>

                <!-- Mobile copyright -->
                <p class="lg:hidden text-center mt-6 text-xs text-gray-400">
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
        position:fixed; inset:0; background:rgba(10,31,68,.6); backdrop-filter:blur(4px); z-index:500;
        align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:22px; width:100%; max-width:440px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.2);">
            <div style="padding:22px 26px 18px; background:linear-gradient(135deg, #0A1F44 0%, #1B3563 100%); position:relative;">
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
                    <p style="font-size:13.5px; color:#121B30; font-weight:600; margin-bottom:6px;">Request Submitted</p>
                    <p style="font-size:12.5px; color:#64718A;"><?= htmlspecialchars($reactivation_message) ?></p>
                    <button type="button" onclick="closeReactivationModal()"
                        style="margin-top:16px; padding:10px 24px; border-radius:10px; background:#0A1F44; color:#fff; border:none; font-weight:700; font-size:13px; cursor:pointer;">
                        Close
                    </button>
                </div>
            <?php else: ?>
                <form method="POST" action="" style="padding:24px 26px;">
                    <input type="hidden" name="form_type" value="reactivation">

                    <div style="margin-bottom:16px; padding:11px 14px; background:#F2F5FA; border:1px solid #C5D2E8; border-radius:11px; font-size:12px; line-height:1.55; color:#1B3563;">
                        <i class="fas fa-circle-info" style="margin-right:6px;"></i>
                        The administrator will review your request. You will be notified by email whether it is <strong>approved</strong> or <strong>declined</strong>. Until then, you will not be able to log in.
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#8E9BB1; margin-bottom:7px;">Email Address</label>
                        <input type="email" name="reactivation_email" required
                            value="<?= htmlspecialchars($deactivated_email) ?>"
                            style="width:100%; padding:11px 14px; border:1.5px solid #D8DFEA; border-radius:11px; font-family:'Inter',sans-serif; font-size:13px;">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#8E9BB1; margin-bottom:7px;">Password</label>
                        <input type="password" name="reactivation_password" required
                            style="width:100%; padding:11px 14px; border:1.5px solid #D8DFEA; border-radius:11px; font-family:'Inter',sans-serif; font-size:13px;">
                        <p style="font-size:10.5px; color:#8E9BB1; margin-top:5px;">Used to verify this is your account.</p>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#8E9BB1; margin-bottom:7px;">Reason for Reactivation</label>
                        <textarea name="reactivation_reason" required rows="3"
                            style="width:100%; padding:11px 14px; border:1.5px solid #D8DFEA; border-radius:11px; font-family:'Inter',sans-serif; font-size:13px; resize:vertical;"
                            placeholder="Tell the admin why you'd like your account reactivated (at least 10 characters)..."></textarea>
                    </div>

                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                        <button type="button" onclick="closeReactivationModal()"
                            style="padding:10px 20px; border-radius:10px; border:1.5px solid #D8DFEA; background:#F5F7FA; color:#4A5670; font-weight:600; font-size:13px; cursor:pointer;">Cancel</button>
                        <button type="submit"
                            style="padding:10px 24px; border-radius:10px; background:linear-gradient(135deg, #0A1F44, #1B3563); color:#fff; border:none; font-weight:700; font-size:13px; cursor:pointer;">
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
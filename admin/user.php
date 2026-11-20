<?php
// user.php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';
require_once '../includes/handover-helpers.php';

if (!defined('DMW_LOGIN_URL')) {
    define('DMW_LOGIN_URL', 'http://localhost/try/login.php');
}
if (!defined('DMW_SET_PASSWORD_URL')) {
    define('DMW_SET_PASSWORD_URL', 'http://localhost/try/set-password.php');
}
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$admin_email = $_SESSION['user_email'] ?? '';
$admin_name  = $_SESSION['admin_name'] ?? '';
if (!$admin_name && $admin_email) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

$error = '';
$success = '';

// Post/Redirect/Get (PRG) helper — prevents duplicate form submissions
// (deactivation, reactivation, invites, etc.) when the user refreshes the page.
function pg_redirect_with_flash($success = '', $error = '', $tab = '')
{
    if ($success !== '') $_SESSION['flash_success'] = $success;
    if ($error !== '')   $_SESSION['flash_error']   = $error;
    if ($tab !== '')     $_SESSION['flash_tab']     = $tab;
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

// Adds rsf_number, submitted_at and updated_at to reassignment cases (read-only lookup)
function reassign_enrich_cases(mysqli $db, array $cases): array
{
    $ids = array_values(array_unique(array_map('intval', array_column($cases, 'id'))));
    if (!$ids) return $cases;

    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT a.id, a.submitted_at, a.updated_at, r.rsf_number
                          FROM applications a
                          JOIN rsf_job_orders r ON r.id = a.rsf_id
                          WHERE a.id IN ($ph)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $map = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $map[(int)$row['id']] = $row;
    }
    $stmt->close();

    foreach ($cases as &$c) {
        $m = $map[(int)$c['id']] ?? null;
        $c['rsf_number']   = $m['rsf_number']   ?? null;
        $c['submitted_at'] = $m['submitted_at'] ?? null;
        $c['updated_at']   = $m['updated_at']   ?? null;
    }
    unset($c);
    return $cases;
}

// CSRF: one token per session, required on every POST
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($csrf, $sent)) {
        pg_redirect_with_flash('', 'Your session expired. Please try again.');
    }
}

$leave_reasons = [
    'emergency' => 'Emergency',
    'sick_leave' => 'Sick leave',
    'vacation'  => 'Vacation',
    'training'  => 'Training',
    'other'     => 'Other',
];

// Preset reasons allowed for account deactivation, split by account type
$deactivation_reasons_by_type = [
    'evaluator' => [
        'Policy or conduct violation',
        'Resigned / offboarded',
        'Prolonged inactivity',
        'Duplicate or erroneous account',
        'Reassignment of duties',
        'Security concern',
        'Requested by evaluator',
        'Other',
    ],
    'client' => [
        'Violation of terms',
        'Fraudulent or duplicate application',
        'Confirmed deployment / employment secured',
        'Prolonged inactivity',
        'Duplicate account',
        'Security concern',
        'Requested by applicant',
        'Other',
    ],
];

// Flattened, deduplicated list — kept for backward compatibility if referenced elsewhere
$allowed_deactivation_reasons = array_values(array_unique(array_merge(
    $deactivation_reasons_by_type['evaluator'],
    $deactivation_reasons_by_type['client']
)));

//  SEND EVALUATOR INVITATION 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'invite') {
    $email     = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (empty($full_name)) {
        $error = "Full name is required.";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = "Phone number must be exactly 11 digits and start with 09 (e.g., 09171234567).";
    } else {
        $stmt = $database->prepare("SELECT email FROM webuser WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "This email is already registered.";
        } else {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $database->prepare("INSERT INTO invitations 
                (email, usertype, full_name, phone, token, expires_at, created_by) 
                VALUES (?, 'evaluator', ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $email, $full_name, $phone, $token, $expires, $_SESSION['user_email']);

            if ($stmt->execute()) {
                $invite_link = DMW_SET_PASSWORD_URL . '?token=' . urlencode($token);
                $safe_name   = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');

                $html = "
                <div style='font-family: Inter, system-ui, sans-serif; max-width: 600px; margin: 0 auto; padding: 30px; background:#f8fafc; border-radius:16px;'>
                    <h2 style='color: #1e40af;'>Hello {$safe_name},</h2>
                    <p>You have been invited to join the <strong>DMW Application Management System</strong> as an <strong>Evaluator</strong>.</p>
                    <p>Please click the button below to set your password and activate your account:</p>
                    <a href='" . htmlspecialchars($invite_link) . "' style='display:inline-block; background:#1e40af; color:white; padding:14px 32px; text-decoration:none; border-radius:12px; font-weight:600; margin:25px 0;'>
                        Set My Password
                    </a>
                    <p style='color:#666; font-size:14px;'>This invitation link will expire in 24 hours.</p>
                    <hr style='margin:30px 0; border-color:#e5e7eb;'>
                    <p style='font-size:12px; color:#64748b;'>Department of Migrant Workers - Regional Office IV-A</p>
                </div>";

                if (send_html_mail($database, $email, $full_name, 'Invitation to DMW Application Management System - Evaluator', $html)) {
                    $success = "Invitation email successfully sent to <strong>" . htmlspecialchars($email) . "</strong>!";
                } else {
                    $error = "Invitation saved, but the email failed to send. Check Email Configuration in Settings.";
                }
            } else {
                $error = "Failed to save invitation record.";
            }
        }
    }

    pg_redirect_with_flash($success, $error);
}

// DEACTIVATE ACCOUNT (Applicant/Client or Evaluator) — Admin action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deactivate_user') {
    $target_email    = trim($_POST['target_email'] ?? '');
    $target_usertype = trim($_POST['target_usertype'] ?? '');
    $reason_preset   = trim($_POST['reason_preset'] ?? '');
    $reason_other    = trim($_POST['reason_other'] ?? '');

    if (!in_array($target_usertype, ['client', 'evaluator'], true)) {
        $error = "Invalid account type.";
    } elseif (!filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid account.";
    } elseif (!in_array($reason_preset, $deactivation_reasons_by_type[$target_usertype] ?? [], true)) {
        $error = "Please select a valid reason for deactivation.";
    } elseif ($reason_preset === 'Other' && mb_strlen($reason_other) < 5) {
        $error = "Please specify a reason (at least 5 characters).";
    } else {
        $final_reason = $reason_preset === 'Other' ? $reason_other : $reason_preset;

        $stmt = $database->prepare("SELECT usertype, is_active FROM webuser WHERE email = ?");
        $stmt->bind_param("s", $target_email);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();

        if (!$target || $target['usertype'] !== $target_usertype) {
            $error = "Account not found.";
        } elseif ($target['usertype'] === 'admin') {
            $error = "Admin accounts cannot be deactivated.";
        } elseif ((int)$target['is_active'] === 0) {
            $error = "This account is already deactivated.";
        } else {
            $blocked = false;

            if ($target_usertype === 'evaluator') {
                $stmt = $database->prepare("SELECT eid FROM evaluators WHERE email = ?");
                $stmt->bind_param("s", $target_email);
                $stmt->execute();
                $ev = $stmt->get_result()->fetch_assoc();
                $eid = $ev['eid'] ?? null;

                if ($eid) {
                    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM assignments WHERE evaluator_id = ? AND status = 'active'");
                    $stmt->bind_param("i", $eid);
                    $stmt->execute();
                    $active_assign = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

                    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM applications WHERE current_evaluator_id = ? AND status IN (" . evaluator_open_status_sql() . ")");
                    $stmt->bind_param("i", $eid);
                    $stmt->execute();
                    $active_apps = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

                    if ($active_assign > 0 || $active_apps > 0) {
                        $blocked = true;
                        $error = "This evaluator has active assignments/applications. Please reassign them first before deactivating.";
                    }
                }
            }

            if (!$blocked) {
                $database->begin_transaction();
                try {
                    $stmt = $database->prepare("UPDATE webuser 
                        SET is_active = 0, deactivated_at = NOW(), deactivated_by = ?, deactivation_reason = ?, remember_token = NULL, remember_expires = NULL 
                        WHERE email = ?");
                    $stmt->bind_param("sss", $_SESSION['user_email'], $final_reason, $target_email);
                    $stmt->execute();

                    $stmt = $database->prepare("INSERT INTO account_status_history 
                        (email, usertype, action, reason, performed_by, previous_status, new_status) 
                        VALUES (?, ?, 'Account Deactivated', ?, ?, 'active', 'deactivated')");
                    $stmt->bind_param("ssss", $target_email, $target_usertype, $final_reason, $_SESSION['user_email']);
                    $stmt->execute();

                    $notif_title = "Account Deactivated";
                    $notif_msg   = "Your account has been deactivated by the Admin. Reason: " . $final_reason;
                    $stmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, ?, ?, ?, 'account_deactivated')");
                    $stmt->bind_param("ssss", $target_email, $target_usertype, $notif_title, $notif_msg);
                    $stmt->execute();

                    $database->commit();
                    $success = "Account for <strong>" . htmlspecialchars($target_email) . "</strong> has been deactivated.";

                    send_html_mail($database, $target_email, null, 'DMW AMS - Account Deactivated', "
                    <div style='font-family: Inter, system-ui, sans-serif; max-width:600px; margin:0 auto; padding:30px; background:#f8fafc; border-radius:16px;'>
                        <h2 style='color:#dc2626;'>Account Deactivated</h2>
                        <p>Your DMW AMS account has been deactivated by the Admin.</p>
                        <p><strong>Reason:</strong> " . htmlspecialchars($final_reason) . "</p>
                        <p>If you wish to use the system again, you may submit a request for account reactivation the next time you attempt to log in.</p>
                        <a href='" . htmlspecialchars(DMW_LOGIN_URL) . "' style='display:inline-block; background:#1e40af; color:white; padding:12px 28px; text-decoration:none; border-radius:10px; font-weight:600; margin:20px 0;'>
                            Go to Login Page
                        </a>
                        <hr style='margin:30px 0; border-color:#e5e7eb;'>
                        <p style='font-size:12px; color:#64748b;'>Department of Migrant Workers - Regional Office IV-A</p>
                    </div>");
                } catch (\Throwable $e) {
                    $database->rollback();
                    $error = "Failed to deactivate account. Please try again.";
                }
            }
        }
    }

    pg_redirect_with_flash($success, $error);
}

// REACTIVATE ACCOUNT — Admin direct reactivation (not tied to a submitted request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reactivate_user') {
    $target_email    = trim($_POST['target_email'] ?? '');
    $target_usertype = trim($_POST['target_usertype'] ?? '');

    if (!in_array($target_usertype, ['client', 'evaluator'], true) || !filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid account.";
    } else {
        $stmt = $database->prepare("SELECT usertype, is_active FROM webuser WHERE email = ?");
        $stmt->bind_param("s", $target_email);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();

        if (!$target || $target['usertype'] !== $target_usertype) {
            $error = "Account not found.";
        } elseif ((int)$target['is_active'] === 1) {
            $error = "This account is already active.";
        } else {
            $database->begin_transaction();
            try {
                $stmt = $database->prepare("UPDATE webuser 
                    SET is_active = 1, deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL 
                    WHERE email = ?");
                $stmt->bind_param("s", $target_email);
                $stmt->execute();

                // If a reactivation request is pending for this account, auto-approve it.
                $stmt = $database->prepare("UPDATE reactivation_requests 
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() 
                    WHERE email = ? AND status = 'pending'");
                $stmt->bind_param("ss", $_SESSION['user_email'], $target_email);
                $stmt->execute();

                $stmt = $database->prepare("INSERT INTO account_status_history 
                    (email, usertype, action, performed_by, previous_status, new_status) 
                    VALUES (?, ?, 'Account Reactivated', ?, 'deactivated', 'active')");
                $stmt->bind_param("sss", $target_email, $target_usertype, $_SESSION['user_email']);
                $stmt->execute();

                $notif_title = "Account Reactivated";
                $notif_msg   = "Your account has been reactivated by the Admin. You may now log in again.";
                $stmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, ?, ?, ?, 'account_reactivated')");
                $stmt->bind_param("ssss", $target_email, $target_usertype, $notif_title, $notif_msg);
                $stmt->execute();

                $database->commit();
                $success = "Account for <strong>" . htmlspecialchars($target_email) . "</strong> has been reactivated.";

                send_html_mail($database, $target_email, null, 'DMW AMS - Account Reactivated', "
                <div style='font-family: Inter, system-ui, sans-serif; max-width:600px; margin:0 auto; padding:30px; background:#f8fafc; border-radius:16px;'>
                    <h2 style='color:#059669;'>Account Reactivated</h2>
                    <p>Good news! Your DMW AMS account has been reactivated by the Admin.</p>
                    <p>You may now log in and continue using the system.</p>
                    <a href='" . htmlspecialchars(DMW_LOGIN_URL) . "' style='display:inline-block; background:#059669; color:white; padding:12px 28px; text-decoration:none; border-radius:10px; font-weight:600; margin:20px 0;'>
                        Go to Login Page
                    </a>
                    <hr style='margin:30px 0; border-color:#e5e7eb;'>
                    <p style='font-size:12px; color:#64748b;'>Department of Migrant Workers - Regional Office IV-A</p>
                </div>");
            } catch (\Throwable $e) {
                $database->rollback();
                $error = "Failed to reactivate account. Please try again.";
            }
        }
    }

    pg_redirect_with_flash($success, $error);
}

// APPROVE REACTIVATION REQUEST — Admin decision on a user-submitted request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_reactivation') {
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {
        $error = "Invalid reactivation request.";
    } else {
        $stmt = $database->prepare("SELECT id, email, usertype, status FROM reactivation_requests WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();

        if (!$req) {
            $error = "Reactivation request not found.";
        } elseif ($req['status'] !== 'pending') {
            $error = "This request has already been reviewed.";
        } else {
            $target_email    = $req['email'];
            $target_usertype = $req['usertype'];

            $stmt = $database->prepare("SELECT usertype, is_active FROM webuser WHERE email = ?");
            $stmt->bind_param("s", $target_email);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();

            if (!$target || $target['usertype'] !== $target_usertype) {
                $error = "Account not found.";
            } elseif ((int)$target['is_active'] === 1) {
                // Account is already active — just close out the stale request.
                $stmt = $database->prepare("UPDATE reactivation_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $_SESSION['user_email'], $request_id);
                $stmt->execute();
                $success = "This account was already active. Request has been closed as approved.";
            } else {
                $database->begin_transaction();
                try {
                    $stmt = $database->prepare("UPDATE webuser 
                        SET is_active = 1, deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL 
                        WHERE email = ?");
                    $stmt->bind_param("s", $target_email);
                    $stmt->execute();

                    $stmt = $database->prepare("UPDATE reactivation_requests 
                        SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() 
                        WHERE id = ?");
                    $stmt->bind_param("si", $_SESSION['user_email'], $request_id);
                    $stmt->execute();

                    $stmt = $database->prepare("INSERT INTO account_status_history 
                        (email, usertype, action, performed_by, previous_status, new_status) 
                        VALUES (?, ?, 'Account Reactivated', ?, 'deactivated', 'active')");
                    $stmt->bind_param("sss", $target_email, $target_usertype, $_SESSION['user_email']);
                    $stmt->execute();

                    $notif_title = "Reactivation Request Approved";
                    $notif_msg   = "Your account reactivation request has been approved. You may now log in again.";
                    $stmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, ?, ?, ?, 'account_reactivated')");
                    $stmt->bind_param("ssss", $target_email, $target_usertype, $notif_title, $notif_msg);
                    $stmt->execute();

                    $database->commit();
                    $success = "Reactivation request for <strong>" . htmlspecialchars($target_email) . "</strong> has been approved.";

                    send_html_mail($database, $target_email, null, 'DMW AMS - Reactivation Request Approved', "
                    <div style='font-family: Inter, system-ui, sans-serif; max-width:600px; margin:0 auto; padding:30px; background:#f8fafc; border-radius:16px;'>
                        <h2 style='color:#059669;'>Reactivation Approved</h2>
                        <p>Good news! Your request to reactivate your DMW AMS account has been approved.</p>
                        <p>You may now log in and continue using the system.</p>
                        <a href='" . htmlspecialchars(DMW_LOGIN_URL) . "' style='display:inline-block; background:#059669; color:white; padding:12px 28px; text-decoration:none; border-radius:10px; font-weight:600; margin:20px 0;'>
                            Go to Login Page
                        </a>
                        <hr style='margin:30px 0; border-color:#e5e7eb;'>
                        <p style='font-size:12px; color:#64748b;'>Department of Migrant Workers - Regional Office IV-A</p>
                    </div>");
                } catch (\Throwable $e) {
                    $database->rollback();
                    $error = "Failed to approve reactivation request. Please try again.";
                }
            }
        }
    }

    pg_redirect_with_flash($success, $error);
}

// REJECT REACTIVATION REQUEST — Admin decision on a user-submitted request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_reactivation') {
    $request_id    = (int)($_POST['request_id'] ?? 0);
    $admin_comment = trim($_POST['admin_comment'] ?? '');

    if ($request_id <= 0) {
        $error = "Invalid reactivation request.";
    } elseif (mb_strlen($admin_comment) < 5) {
        $error = "Please provide a reason for rejecting this request (at least 5 characters).";
    } else {
        $stmt = $database->prepare("SELECT id, email, usertype, status FROM reactivation_requests WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();

        if (!$req) {
            $error = "Reactivation request not found.";
        } elseif ($req['status'] !== 'pending') {
            $error = "This request has already been reviewed.";
        } else {
            $target_email    = $req['email'];
            $target_usertype = $req['usertype'];

            $database->begin_transaction();
            try {
                $stmt = $database->prepare("UPDATE reactivation_requests 
                    SET status = 'rejected', admin_comment = ?, reviewed_by = ?, reviewed_at = NOW() 
                    WHERE id = ?");
                $stmt->bind_param("ssi", $admin_comment, $_SESSION['user_email'], $request_id);
                $stmt->execute();

                $stmt = $database->prepare("INSERT INTO account_status_history 
                    (email, usertype, action, reason, performed_by, previous_status, new_status) 
                    VALUES (?, ?, 'Reactivation Request Rejected', ?, ?, 'deactivated', 'deactivated')");
                $stmt->bind_param("ssss", $target_email, $target_usertype, $admin_comment, $_SESSION['user_email']);
                $stmt->execute();

                $notif_title = "Reactivation Request Rejected";
                $notif_msg   = "Your account reactivation request was not approved. Reason: " . $admin_comment;
                $stmt = $database->prepare("INSERT INTO notifications (user_email, usertype, title, message, type) VALUES (?, ?, ?, ?, 'reactivation_rejected')");
                $stmt->bind_param("ssss", $target_email, $target_usertype, $notif_title, $notif_msg);
                $stmt->execute();

                $database->commit();
                $success = "Reactivation request for <strong>" . htmlspecialchars($target_email) . "</strong> has been rejected.";

                send_html_mail($database, $target_email, null, 'DMW AMS - Reactivation Request Update', "
                <div style='font-family: Inter, system-ui, sans-serif; max-width:600px; margin:0 auto; padding:30px; background:#f8fafc; border-radius:16px;'>
                    <h2 style='color:#dc2626;'>Reactivation Request Not Approved</h2>
                    <p>Your request to reactivate your DMW AMS account was reviewed and was not approved at this time.</p>
                    <p><strong>Reason:</strong> " . htmlspecialchars($admin_comment) . "</p>
                    <p>If you believe this was in error, you may submit another request the next time you attempt to log in.</p>
                    <hr style='margin:30px 0; border-color:#e5e7eb;'>
                    <p style='font-size:12px; color:#64748b;'>Department of Migrant Workers - Regional Office IV-A</p>
                </div>");
            } catch (\Throwable $e) {
                $database->rollback();
                $error = "Failed to reject reactivation request. Please try again.";
            }
        }
    }

    pg_redirect_with_flash($success, $error);
}

// ── LEAVE / REASSIGNMENT ──────────────────────────────────────────────

// Admin starts a leave on behalf of an evaluator (with handover request, or leave only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create_leave_request', 'set_unavailability'], true)) {
    $act    = $_POST['action'];
    $eid    = (int)($_POST['evaluator_id'] ?? 0);
    $reason = (string)($_POST['reason_category'] ?? '');
    $return = handover_parse_return_date($_POST['expected_return'] ?? '');
    $ev     = $eid > 0 ? handover_get_evaluator($database, $eid) : null;

    if (!$ev) {
        $error = "Evaluator not found.";
    } elseif (!isset($leave_reasons[$reason])) {
        $error = "Please select a valid reason category.";
    } elseif ($return === false) {
        $error = "Expected return must be a valid date, today or later.";
    } elseif ((int)$ev['is_active'] !== 1) {
        $error = "This evaluator is deactivated.";
    } elseif (!isEvaluatorAvailable($database, $eid)) {
        $error = "This evaluator is already on leave.";
    } elseif ($act === 'create_leave_request') {
        $stmt = $database->prepare("SELECT id FROM reassignment_requests WHERE evaluator_id = ? AND status = 'pending' LIMIT 1");
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) {
            $error = "This evaluator already has a pending reassignment request.";
        } else {
            $stmt = $database->prepare("INSERT INTO reassignment_requests (evaluator_id, scope, reason_category, expected_return, status)
                                        VALUES (?, 'all', ?, ?, 'pending')");
            $stmt->bind_param('iss', $eid, $reason, $return);
            $stmt->execute();
            $stmt->close();
            $success = "Request created for <strong>" . htmlspecialchars($ev['full_name']) . "</strong>. Review the proposed split below.";
        }
    } else {
        if (handover_create_unavailability($database, $eid, $reason, $return, $_SESSION['user_email'])) {
            $until = $return ? date('M d, Y', strtotime($return)) : 'further notice';
            handover_notify(
                $database,
                $ev['email'],
                'evaluator',
                null,
                'Marked as Unavailable',
                "You are marked as unavailable until {$until}. You will not receive new cases during this time.",
                'account_status'
            );
            $success = "<strong>" . htmlspecialchars($ev['full_name']) . "</strong> is now marked as on leave.";
        } else {
            $error = "Failed to record leave. Please try again.";
        }
    }
    pg_redirect_with_flash($success, $error, 'leave');
}

// Admin ends an evaluator's leave early
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'end_unavailability') {
    $eid = (int)($_POST['evaluator_id'] ?? 0);
    $ev  = $eid > 0 ? handover_get_evaluator($database, $eid) : null;
    if (!$ev) {
        $error = "Evaluator not found.";
    } else {
        $stmt = $database->prepare("UPDATE evaluator_unavailability SET status = 'ended', ended_by = ?, ended_at = NOW()
                                    WHERE evaluator_id = ? AND status = 'active'");
        $stmt->bind_param('si', $_SESSION['user_email'], $eid);
        $stmt->execute();
        $stmt->close();
        handover_notify(
            $database,
            $ev['email'],
            'evaluator',
            null,
            'Leave Ended',
            'Your leave has been ended by the Admin. You can claim and evaluate cases again.',
            'account_status'
        );
        $success = "Leave ended for <strong>" . htmlspecialchars($ev['full_name']) . "</strong>.";
    }
    pg_redirect_with_flash($success, $error, 'leave');
}

// Approve a reassignment request: each case moves in its own transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_reassignment') {
    $rid     = (int)($_POST['request_id'] ?? 0);
    $assign  = is_array($_POST['assign'] ?? null) ? $_POST['assign'] : [];
    $comment = trim($_POST['admin_comment'] ?? '');

    $stmt = $database->prepare("SELECT rr.*, e.full_name, e.email AS ev_email
                                FROM reassignment_requests rr JOIN evaluators e ON e.eid = rr.evaluator_id
                                WHERE rr.id = ?");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        $error = "Request not found.";
    } elseif ($req['status'] !== 'pending') {
        $error = "This request has already been reviewed.";
    } elseif (!getAvailableEvaluatorsWithWorkload($database, (int)$req['evaluator_id']) && getOpenCasesForEvaluator($database, (int)$req['evaluator_id'])) {
        $error = "No other evaluator is available right now, so these cases cannot be reassigned yet.";
    } else {
        // Claim the request first so two admins cannot approve it twice
        $cmt = $comment !== '' ? $comment : null;
        $stmt = $database->prepare("UPDATE reassignment_requests
                                    SET status = 'approved', reviewed_by = ?, admin_comment = ?, reviewed_at = NOW()
                                    WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('ssi', $_SESSION['user_email'], $cmt, $rid);
        $stmt->execute();
        $claimed = $stmt->affected_rows > 0;
        $stmt->close();

        if (!$claimed) {
            $error = "This request has already been reviewed.";
        } else {
            $src = (int)$req['evaluator_id'];
            if ($req['scope'] === 'all') {
                handover_sync_items($database, $rid, $src);
            }
            $stmt = $database->prepare("SELECT id, application_id FROM reassignment_request_items WHERE request_id = ? AND outcome = 'pending'");
            $stmt->bind_param('i', $rid);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $reason_text = 'Handover (' . ($leave_reasons[$req['reason_category']] ?? 'Other') . ') approved by admin';
            $moved = 0;
            $skipped = 0;
            $per_target = [];
            $names = [];

            foreach ($items as $it) {
                $app_id = (int)$it['application_id'];
                $to     = (int)($assign[$app_id] ?? 0);
                $res    = $to > 0 ? handoverApplication($database, $app_id, $src, $to, $_SESSION['user_email'], $reason_text)
                    : ['ok' => false];

                if (!empty($res['ok'])) {
                    $moved++;
                    $per_target[$to] = ($per_target[$to] ?? 0) + 1;
                    if (!isset($names[$to])) {
                        $names[$to] = handover_get_evaluator($database, $to)['full_name'] ?? 'another evaluator';
                    }
                    handover_notify(
                        $database,
                        $res['client_email'],
                        'client',
                        $app_id,
                        'Application Reassigned',
                        'Your application has been reassigned to evaluator ' . $names[$to] . '.',
                        'application_status'
                    );
                    $stmt = $database->prepare("UPDATE reassignment_request_items SET new_evaluator_id = ?, outcome = 'reassigned' WHERE id = ?");
                    $stmt->bind_param('ii', $to, $it['id']);
                } else {
                    $skipped++;
                    $stmt = $database->prepare("UPDATE reassignment_request_items SET outcome = 'skipped' WHERE id = ?");
                    $stmt->bind_param('i', $it['id']);
                }
                $stmt->execute();
                $stmt->close();
            }

            $leave_note = '';
            if ($req['scope'] === 'all') {
                handover_create_unavailability($database, $src, $req['reason_category'], $req['expected_return'], $_SESSION['user_email']);
                $leave_note = ' You are now marked as unavailable and will not receive new cases.';
            }
            foreach ($per_target as $to => $n) {
                notify_evaluator_by_id(
                    $database,
                    $to,
                    'Cases Reassigned to You',
                    "{$n} case(s) from {$req['full_name']} have been reassigned to you by Admin.",
                    'application_update'
                );
            }
            handover_notify(
                $database,
                $req['ev_email'],
                'evaluator',
                null,
                'Reassignment Request Approved',
                "Your request was approved: {$moved} case(s) reassigned, {$skipped} skipped." . $leave_note,
                'application_update'
            );

            $success = "Request approved: <strong>{$moved}</strong> case(s) reassigned, <strong>{$skipped}</strong> skipped.";
        }
    }
    pg_redirect_with_flash($success, $error, 'leave');
}

// Reject a reassignment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_reassignment') {
    $rid     = (int)($_POST['request_id'] ?? 0);
    $comment = trim($_POST['admin_comment'] ?? '');

    if (mb_strlen($comment) < 5) {
        $error = "Please provide a reason (at least 5 characters).";
    } else {
        $stmt = $database->prepare("SELECT e.email AS ev_email FROM reassignment_requests rr
                                    JOIN evaluators e ON e.eid = rr.evaluator_id WHERE rr.id = ?");
        $stmt->bind_param('i', $rid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $database->prepare("UPDATE reassignment_requests
                                    SET status = 'rejected', reviewed_by = ?, admin_comment = ?, reviewed_at = NOW()
                                    WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('ssi', $_SESSION['user_email'], $comment, $rid);
        $stmt->execute();
        $done = $stmt->affected_rows > 0;
        $stmt->close();

        if ($done && $row) {
            handover_notify(
                $database,
                $row['ev_email'],
                'evaluator',
                null,
                'Reassignment Request Rejected',
                'Your reassignment request was not approved. Reason: ' . $comment,
                'application_update'
            );
            $success = "Request rejected.";
        } else {
            $error = "This request has already been reviewed.";
        }
    }
    pg_redirect_with_flash($success, $error, 'leave');
}

// Recover flash messages set by the PRG redirect above (if any)
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
$flash_tab = $_SESSION['flash_tab'] ?? '';
unset($_SESSION['flash_tab']);

// Fetch Evaluators (with derived account status)
$open_st = evaluator_open_status_sql();
$leave_cond = "eu.evaluator_id = e.eid AND eu.status = 'active' AND eu.start_at <= NOW() AND (eu.end_at IS NULL OR eu.end_at > NOW())";
$stmt = $database->prepare("SELECT e.eid, e.full_name, e.email, e.phone, e.created_at,
                            w.is_active, w.deactivated_at, w.deactivation_reason, w.deactivated_by,
                            rr.status AS pending_status,
                            (SELECT COUNT(*) FROM applications a WHERE a.current_evaluator_id = e.eid AND a.status IN ($open_st)) AS open_cases,
                            (SELECT eu.id FROM evaluator_unavailability eu WHERE $leave_cond ORDER BY eu.start_at DESC LIMIT 1) AS leave_id,
                            (SELECT eu.end_at FROM evaluator_unavailability eu WHERE $leave_cond ORDER BY eu.start_at DESC LIMIT 1) AS leave_end
                            FROM evaluators e
                            JOIN webuser w ON e.email = w.email
                            LEFT JOIN reactivation_requests rr ON rr.email = e.email AND rr.status = 'pending'
                            ORDER BY e.created_at DESC");
$stmt->execute();
$evaluators = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Applicants / Clients (with derived account status)
$stmt = $database->prepare("SELECT c.cid, c.first_name, c.middle_name, c.last_name, c.email, c.phone, c.created_at,
                            c.profile_picture,
                            w.is_active, w.deactivated_at, w.deactivation_reason, w.deactivated_by,
                            rr.status AS pending_status
                            FROM clients c
                            JOIN webuser w ON c.email = w.email
                            LEFT JOIN reactivation_requests rr ON rr.email = c.email AND rr.status = 'pending'
                            ORDER BY c.created_at DESC");
$stmt->execute();
$applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Pending reassignment requests, each with a proposed least-workload split
$stmt = $database->prepare("SELECT rr.*, e.full_name, e.email AS ev_email
                            FROM reassignment_requests rr JOIN evaluators e ON e.eid = rr.evaluator_id
                            WHERE rr.status = 'pending' ORDER BY rr.requested_at ASC");
$stmt->execute();
$leave_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($leave_requests as &$lr) {
    $lr['cases']    = reassign_enrich_cases($database, handover_request_cases($database, $lr));
    $lr['targets']  = getAvailableEvaluatorsWithWorkload($database, (int)$lr['evaluator_id']);
    $lr['proposal'] = proposeHandoverSplit($database, array_column($lr['cases'], 'id'), (int)$lr['evaluator_id']);
}
unset($lr);
$leave_requests_count = count($leave_requests);

// Evaluators currently on leave
$stmt = $database->prepare("SELECT eu.evaluator_id, eu.reason_category, eu.start_at, eu.end_at, e.full_name, e.email,
                            (SELECT rr.reason_other FROM reassignment_requests rr
                              WHERE rr.evaluator_id = eu.evaluator_id AND rr.status = 'approved'
                                AND rr.reason_category = eu.reason_category AND rr.reason_other IS NOT NULL
                              ORDER BY rr.reviewed_at DESC, rr.id DESC LIMIT 1) AS reason_other
                            FROM evaluator_unavailability eu JOIN evaluators e ON e.eid = eu.evaluator_id
                            WHERE eu.status = 'active' AND eu.start_at <= NOW() AND (eu.end_at IS NULL OR eu.end_at > NOW())
                            ORDER BY eu.start_at DESC");
$stmt->execute();
$on_leave = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// Fetch pending invitations
$stmt = $database->prepare("
    SELECT i.email, i.full_name, i.phone, i.expires_at, i.created_at 
    FROM invitations i
    WHERE i.used = 0 
      AND i.expires_at >= NOW() 
      AND i.usertype = 'evaluator'
      AND NOT EXISTS (
          SELECT 1 FROM webuser w WHERE w.email = i.email
      )
    ORDER BY i.created_at DESC
");
$stmt->execute();
$pending_invitations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch expired invitations
$stmt = $database->prepare("
    SELECT i.email, i.full_name, i.phone, i.expires_at, i.created_at 
    FROM invitations i
    WHERE i.used = 0 
      AND i.expires_at < NOW() 
      AND i.usertype = 'evaluator'
      AND NOT EXISTS (
          SELECT 1 FROM webuser w WHERE w.email = i.email
      )
    ORDER BY i.expires_at DESC
");
$stmt->execute();
$expired_invitations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$expired_count = count($expired_invitations);

// Fetch pending reactivation requests (with applicant/evaluator display name)
$stmt = $database->prepare("
    SELECT rr.id, rr.email, rr.usertype, rr.reason, rr.requested_at,
           COALESCE(ev.full_name, NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)), '')) AS full_name,
           c.profile_picture
    FROM reactivation_requests rr
    LEFT JOIN evaluators ev ON ev.email = rr.email AND rr.usertype = 'evaluator'
    LEFT JOIN clients c ON c.email = rr.email AND rr.usertype = 'client'
    WHERE rr.status = 'pending'
    ORDER BY rr.requested_at ASC
");
$stmt->execute();
$reactivation_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reactivation_requests_count = count($reactivation_requests);

// Stats — Evaluators
$total_evaluators = count($evaluators);
$active_evaluators_count = 0;
foreach ($evaluators as $e) {
    if ((int)$e['is_active'] === 1) $active_evaluators_count++;
}
$deactivated_evaluators_count = $total_evaluators - $active_evaluators_count;
$pending_count    = count($pending_invitations);
$newest_joined    = !empty($evaluators) ? date('M d', strtotime($evaluators[0]['created_at'])) : '—';

// Stats — Applicants
$total_applicants = count($applicants);
$active_applicants_count = 0;
foreach ($applicants as $a) {
    if ((int)$a['is_active'] === 1) $active_applicants_count++;
}
$deactivated_applicants_count = $total_applicants - $active_applicants_count;

// ── VIEW ONLY: reusable Tailwind class strings (presentation, no logic) ──
$badge_base = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10.5px] font-bold';
$btn_base   = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border px-3.5 py-1.5 text-[11.5px] font-bold transition';
$tw = [
    'card'      => 'overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800',
    'cardHead'  => 'flex items-center gap-3.5 border-b border-slate-200 bg-gradient-to-br from-slate-50 to-blue-50 px-4 py-4 dark:border-slate-700 dark:from-slate-800 dark:to-slate-700/40 sm:px-6',
    'title'     => 'text-[15px] font-extrabold text-blue-950 dark:text-blue-400',
    'sub'       => 'mt-0.5 text-xs font-medium text-slate-400',
    'badgeOk'   => "$badge_base border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-400/30 dark:bg-emerald-500/15 dark:text-emerald-400",
    'badgeWarn' => "$badge_base border-amber-200 bg-amber-50 text-amber-600 dark:border-amber-400/30 dark:bg-amber-500/15 dark:text-amber-400",
    'badgeBad'  => "$badge_base border-red-200 bg-red-50 text-red-600 dark:border-red-400/30 dark:bg-red-500/15 dark:text-red-400",
    'badgeInfo' => "$badge_base border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-400/30 dark:bg-blue-500/15 dark:text-blue-400",
    'btnRed'    => "$btn_base border-red-200 bg-red-50 text-red-600 hover:bg-red-100 dark:border-red-400/30 dark:bg-red-500/15 dark:text-red-400 dark:hover:bg-red-500/25",
    'btnGreen'  => "$btn_base border-emerald-200 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 dark:border-emerald-400/30 dark:bg-emerald-500/15 dark:text-emerald-400 dark:hover:bg-emerald-500/25",
    'btnBlue'   => "$btn_base border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:border-blue-400/30 dark:bg-blue-500/15 dark:text-blue-400 dark:hover:bg-blue-500/25",
    'btnPrimary' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-br from-blue-700 to-indigo-600 px-5 py-2.5 text-[13px] font-bold text-white shadow-lg shadow-blue-700/30 transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50',
    'btnDanger' => 'inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-br from-red-600 to-red-800 px-5 py-2.5 text-[13px] font-bold text-white transition hover:opacity-90',
    'btnCancel' => 'rounded-xl border-[1.5px] border-slate-200 bg-slate-50 px-5 py-2.5 text-[13px] font-semibold text-slate-600 transition hover:bg-slate-200 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-700',
    'input'     => 'w-full rounded-xl border-[1.5px] border-slate-200 bg-slate-50 px-3.5 py-2.5 text-[13px] text-slate-900 transition focus:border-blue-400 focus:bg-white focus:outline-none dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 dark:focus:bg-slate-800',
    'label'     => 'mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400',
    'hint'      => 'mt-1.5 text-[11px] text-slate-400',
    'avatar'    => 'flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-full bg-cover bg-center text-[13px] font-extrabold text-white',
    'search'    => 'w-full max-w-sm rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-3.5 text-[12.5px] text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-400/40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100',
    'empty'     => 'px-6 py-14 text-center',
    'emptyIcon' => 'mx-auto mb-4 flex h-[72px] w-[72px] items-center justify-center rounded-2xl bg-gradient-to-br from-blue-50 to-indigo-50 text-[28px] text-blue-400 dark:from-slate-800 dark:to-slate-700',
    'emptyTitle' => 'mb-1.5 text-base font-extrabold text-slate-900 dark:text-slate-100',
    'emptySub'  => 'mb-5 text-[13px] text-slate-400',
    'row'       => 'flex flex-wrap items-center gap-3.5 border-b border-slate-200 px-4 py-3.5 last:border-b-0 dark:border-slate-700 sm:px-5',
    // responsive table: stacked cards below md, real table from md up
    'table'     => 'block w-full md:table',
    'thead'     => 'hidden md:table-header-group',
    'th'        => 'border-b border-slate-200 bg-slate-50 px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:border-slate-700 dark:bg-slate-900',
    'tbody'     => 'block md:table-row-group',
    'tr'        => 'block border-b border-slate-200 px-4 py-3 last:border-b-0 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-700/30 md:table-row md:p-0',
    'td0'       => 'block py-1 text-[13px] text-slate-600 dark:text-slate-400 md:table-cell md:px-5 md:py-3.5 md:align-middle',
    'td'        => 'block py-1 text-[13px] text-slate-600 before:mb-0.5 before:block before:text-[10px] before:font-bold before:uppercase before:tracking-wider before:text-slate-400 before:content-[attr(data-label)] dark:text-slate-400 md:table-cell md:px-5 md:py-3.5 md:align-middle md:before:hidden',
];
$tab_btn = 'flex shrink-0 items-center gap-2 rounded-[10px] px-4 py-2 text-[12.5px] font-semibold text-slate-400 transition aria-selected:bg-white aria-selected:text-blue-950 aria-selected:shadow dark:aria-selected:bg-slate-800 dark:aria-selected:text-white';
$tab_count = 'rounded-full bg-indigo-100 px-2 py-px text-[10px] font-bold text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300';
$tab_alert = 'rounded-full bg-amber-600 px-2 py-px text-[10px] font-bold text-white';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluators – DMW RO IV-A Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]'],
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Sora', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        /* Only what Tailwind cannot express: shared topbar logo sizing */
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body class="bg-slate-50 font-sans text-slate-900 dark:bg-slate-900 dark:text-slate-200">

    <!-- TOP BAR -->
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

            <div class="tb-btn"><i class="fas fa-bell"></i></div>
            <?php include __DIR__ . '/avatar-menu.php'; ?>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Users</h2>
                <p>Manage evaluator and applicant accounts, invitations, and account status</p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <?php if ($error): ?>
            <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-600 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-400">
                <i class="fas fa-circle-exclamation mt-0.5"></i> <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-green-200 bg-emerald-50 px-4 py-3 text-[13px] text-emerald-800 dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:text-emerald-400">
                <i class="fas fa-circle-check mt-0.5"></i> <span><?= $success ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="mb-5 grid grid-cols-1 gap-3.5 sm:grid-cols-3">
            <?php
            $stats = [
                ['Active Evaluators', $active_evaluators_count, 'fa-users-gear', 'from-blue-700 to-blue-400', 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400'],
                ['Active Applicants', $active_applicants_count, 'fa-user-group', 'from-emerald-600 to-emerald-400', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400'],
                ['Pending Invitations', $pending_count, 'fa-envelope-open-text', 'from-amber-600 to-amber-400', 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400'],
            ];
            foreach ($stats as [$s_label, $s_val, $s_icon, $s_bar, $s_icon_cls]): ?>
                <div class="relative flex items-center gap-4 overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                    <div class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r <?= $s_bar ?>"></div>
                    <div class="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-xl text-lg <?= $s_icon_cls ?>"><i class="fas <?= $s_icon ?>"></i></div>
                    <div>
                        <div class="text-2xl font-extrabold leading-none sm:text-[28px]"><?= (int)$s_val ?></div>
                        <div class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400"><?= $s_label ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Tabs + Invite Button Row -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-slate-50 p-1.5 dark:border-slate-700 dark:bg-slate-900" role="tablist">
                <button type="button" role="tab" aria-selected="true" class="tab-btn <?= $tab_btn ?>" onclick="switchTab('registered', this)">
                    <i class="fas fa-users"></i> Evaluators
                    <span class="<?= $tab_count ?>"><?= $total_evaluators ?></span>
                </button>
                <button type="button" role="tab" aria-selected="false" class="tab-btn <?= $tab_btn ?>" onclick="switchTab('applicants', this)">
                    <i class="fas fa-user-group"></i> Applicants
                    <span class="<?= $tab_count ?>"><?= $total_applicants ?></span>
                </button>
                <button type="button" role="tab" aria-selected="false" class="tab-btn <?= $tab_btn ?>" onclick="switchTab('pending', this)">
                    <i class="fas fa-clock"></i> Pending Invites
                    <?php if ($pending_count > 0): ?><span class="<?= $tab_alert ?>"><?= $pending_count ?></span><?php endif; ?>
                </button>
                <button type="button" role="tab" aria-selected="false" class="tab-btn <?= $tab_btn ?>" onclick="switchTab('expired', this)">
                    <i class="fas fa-ban"></i> Expired
                    <?php if ($expired_count > 0): ?>
                        <span class="rounded-full bg-red-100 px-2 py-px text-[10px] font-bold text-red-600 dark:bg-red-500/20"><?= $expired_count ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" role="tab" aria-selected="false" class="tab-btn <?= $tab_btn ?>" onclick="switchTab('reactivation', this)">
                    <i class="fas fa-rotate-left"></i> Reactivation Requests
                    <?php if ($reactivation_requests_count > 0): ?><span class="<?= $tab_alert ?>"><?= $reactivation_requests_count ?></span><?php endif; ?>
                </button>
                <button type="button" role="tab" aria-selected="false" class="tab-btn <?= $tab_btn ?>" id="tabBtnLeave" onclick="switchTab('leave', this)">
                    <i class="fas fa-plane-departure"></i> Leave &amp; Reassignment
                    <?php if ($leave_requests_count > 0): ?><span class="<?= $tab_alert ?>"><?= $leave_requests_count ?></span><?php endif; ?>
                </button>
            </div>
            <button type="button" class="<?= $tw['btnPrimary'] ?> w-full sm:w-auto" onclick="showInviteModal()">
                <i class="fas fa-paper-plane"></i> Send Invitation
            </button>
        </div>

        <!-- TAB: Registered Evaluators -->
        <div id="tab-registered" class="tab-content">
            <div class="<?= $tw['card'] ?>">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Registered Evaluators</div>
                        <div class="<?= $tw['sub'] ?>"><?= $total_evaluators ?> account<?= $total_evaluators !== 1 ? 's' : '' ?> · <?= $active_evaluators_count ?> active, <?= $deactivated_evaluators_count ?> deactivated</div>
                    </div>
                </div>

                <div class="relative border-b border-slate-200 bg-slate-50 px-4 py-3.5 dark:border-slate-700 dark:bg-slate-900 sm:px-5">
                    <i class="fas fa-search pointer-events-none absolute left-7 top-1/2 -translate-y-1/2 text-xs text-slate-400 sm:left-8"></i>
                    <input type="text" class="<?= $tw['search'] ?>" id="evalSearch" placeholder="Search by name or email..." oninput="filterTable()">
                </div>

                <?php if (empty($evaluators)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyIcon'] ?>"><i class="fas fa-users-slash"></i></div>
                        <div class="<?= $tw['emptyTitle'] ?>">No evaluators yet</div>
                        <div class="<?= $tw['emptySub'] ?>">Send your first invitation to get started.</div>
                        <button type="button" class="<?= $tw['btnPrimary'] ?>" onclick="showInviteModal()">
                            <i class="fas fa-paper-plane"></i> Send First Invitation
                        </button>
                    </div>
                <?php else: ?>
                    <table id="evalTable" class="<?= $tw['table'] ?>">
                        <thead class="<?= $tw['thead'] ?>">
                            <tr>
                                <th class="<?= $tw['th'] ?>">Evaluator</th>
                                <th class="<?= $tw['th'] ?>">Phone</th>
                                <th class="<?= $tw['th'] ?>">Status</th>
                                <th class="<?= $tw['th'] ?>">Joined</th>
                                <th class="<?= $tw['th'] ?>">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="<?= $tw['tbody'] ?>">
                            <?php foreach ($evaluators as $e): ?>
                                <?php
                                $parts = explode(' ', trim($e['full_name'] ?? 'EV'));
                                $initials = strtoupper(count($parts) >= 2
                                    ? substr($parts[0], 0, 1) . substr(end($parts), 0, 1)
                                    : substr($e['full_name'], 0, 2));
                                $is_active = (int)($e['is_active'] ?? 1) === 1;
                                $has_pending_request = !empty($e['pending_status']);
                                $on_leave_now = !empty($e['leave_id']);
                                ?>
                                <tr class="ev-row <?= $tw['tr'] ?>">
                                    <td class="<?= $tw['td0'] ?>">
                                        <div class="flex items-center gap-3">
                                            <div class="<?= $tw['avatar'] ?> bg-gradient-to-br from-blue-700 to-indigo-600"><?= $initials ?></div>
                                            <div class="min-w-0">
                                                <div class="text-[13.5px] font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($e['full_name'] ?? '—') ?></div>
                                                <div class="break-all text-[11.5px] text-slate-400"><?= htmlspecialchars($e['email']) ?></div>
                                                <div class="text-[11.5px] text-slate-400"><?= (int)$e['open_cases'] ?> open case<?= (int)$e['open_cases'] !== 1 ? 's' : '' ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="<?= $tw['td'] ?>" data-label="Phone"><?= htmlspecialchars($e['phone'] ?? '—') ?></td>
                                    <td class="<?= $tw['td'] ?>" data-label="Status">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <?php if ($is_active): ?>
                                                <span class="<?= $tw['badgeOk'] ?>"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>Active</span>
                                                <?php if ($on_leave_now): ?>
                                                    <span class="<?= $tw['badgeInfo'] ?>"><i class="fas fa-plane-departure"></i> On leave<?= $e['leave_end'] ? ' until ' . date('M d', strtotime($e['leave_end'])) : '' ?></span>
                                                <?php endif; ?>
                                            <?php elseif ($has_pending_request): ?>
                                                <span class="<?= $tw['badgeWarn'] ?>"><i class="fas fa-hourglass-half"></i> Reactivation Pending</span>
                                            <?php else: ?>
                                                <span class="<?= $tw['badgeBad'] ?> cursor-pointer"
                                                    onclick='showDeactivationReason(<?= json_encode($e['deactivation_reason'] ?: 'No reason recorded.') ?>, <?= json_encode($e['deactivated_at'] ? date('M d, Y g:i a', strtotime($e['deactivated_at'])) : '—') ?>, <?= json_encode($e['deactivated_by'] ?: null) ?>)'>
                                                    <i class="fas fa-ban"></i> Deactivated <i class="fas fa-circle-info ml-0.5 text-[9px]"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="<?= $tw['td'] ?>" data-label="Joined">
                                        <div class="text-xs text-slate-400"><?= date('M d, Y', strtotime($e['created_at'])) ?></div>
                                        <div class="text-[10.5px] text-slate-400"><?= date('g:i a', strtotime($e['created_at'])) ?></div>
                                    </td>
                                    <td class="<?= $tw['td'] ?>" data-label="Actions">
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php if ($is_active): ?>
                                                <?php if ($on_leave_now): ?>
                                                    <button type="button" class="<?= $tw['btnGreen'] ?>" onclick="endLeave(<?= (int)$e['eid'] ?>)">
                                                        <i class="fas fa-plane-arrival"></i> End leave
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="<?= $tw['btnBlue'] ?>" data-eid="<?= (int)$e['eid'] ?>"
                                                        data-name="<?= htmlspecialchars($e['full_name'] ?? $e['email'], ENT_QUOTES) ?>"
                                                        onclick="showLeaveModal(this)">
                                                        <i class="fas fa-plane-departure"></i> Leave
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="<?= $tw['btnRed'] ?>"
                                                    onclick="showDeactivateModal('<?= htmlspecialchars($e['email'], ENT_QUOTES) ?>', 'evaluator', '<?= htmlspecialchars($e['full_name'] ?? $e['email'], ENT_QUOTES) ?>')">
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="<?= $tw['btnGreen'] ?>"
                                                    onclick="reactivateUser('<?= htmlspecialchars($e['email'], ENT_QUOTES) ?>', 'evaluator')">
                                                    <i class="fas fa-user-check"></i> Reactivate
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="no-results" class="hidden p-10 text-center text-[13px] text-slate-400">
                        <i class="fas fa-search mb-2.5 block text-2xl"></i>
                        No evaluators match your search.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Registered Applicants -->
        <div id="tab-applicants" class="tab-content hidden">
            <div class="<?= $tw['card'] ?>">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Registered Applicants</div>
                        <div class="<?= $tw['sub'] ?>"><?= $total_applicants ?> account<?= $total_applicants !== 1 ? 's' : '' ?> · <?= $active_applicants_count ?> active, <?= $deactivated_applicants_count ?> deactivated</div>
                    </div>
                </div>

                <div class="relative border-b border-slate-200 bg-slate-50 px-4 py-3.5 dark:border-slate-700 dark:bg-slate-900 sm:px-5">
                    <i class="fas fa-search pointer-events-none absolute left-7 top-1/2 -translate-y-1/2 text-xs text-slate-400 sm:left-8"></i>
                    <input type="text" class="<?= $tw['search'] ?>" id="applicantSearch" placeholder="Search by name or email..." oninput="filterApplicantTable()">
                </div>

                <?php if (empty($applicants)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyIcon'] ?>"><i class="fas fa-user-group"></i></div>
                        <div class="<?= $tw['emptyTitle'] ?>">No applicants yet</div>
                        <div class="<?= $tw['emptySub'] ?>">Registered applicants will appear here.</div>
                    </div>
                <?php else: ?>
                    <table id="applicantTable" class="<?= $tw['table'] ?>">
                        <thead class="<?= $tw['thead'] ?>">
                            <tr>
                                <th class="<?= $tw['th'] ?>">Applicant</th>
                                <th class="<?= $tw['th'] ?>">Phone</th>
                                <th class="<?= $tw['th'] ?>">Status</th>
                                <th class="<?= $tw['th'] ?>">Joined</th>
                                <th class="<?= $tw['th'] ?>">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="<?= $tw['tbody'] ?>">
                            <?php foreach ($applicants as $a): ?>
                                <?php
                                $full_name = trim(preg_replace('/\s+/', ' ', $a['first_name'] . ' ' . ($a['middle_name'] ?? '') . ' ' . $a['last_name']));
                                $aparts = explode(' ', $full_name !== '' ? $full_name : 'AP');
                                $ainitials = strtoupper(count($aparts) >= 2
                                    ? substr($aparts[0], 0, 1) . substr(end($aparts), 0, 1)
                                    : substr($full_name, 0, 2));
                                $is_active = (int)($a['is_active'] ?? 1) === 1;
                                $has_pending_request = !empty($a['pending_status']);
                                $a_has_pic = !empty($a['profile_picture']);
                                $a_pic_url = $a_has_pic ? '../' . $a['profile_picture'] : null;
                                ?>
                                <tr class="ap-row <?= $tw['tr'] ?>">
                                    <td class="<?= $tw['td0'] ?>">
                                        <div class="flex items-center gap-3">
                                            <div class="<?= $tw['avatar'] ?> <?= $a_has_pic ? '' : 'bg-gradient-to-br from-emerald-600 to-emerald-400' ?>"
                                                <?= $a_has_pic ? "style=\"background-image:url('" . htmlspecialchars($a_pic_url) . "')\" title=\"" . htmlspecialchars($full_name !== '' ? $full_name : $a['email'], ENT_QUOTES) . '"' : '' ?>>
                                                <?= $a_has_pic ? '' : $ainitials ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-[13.5px] font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($full_name !== '' ? $full_name : '—') ?></div>
                                                <div class="break-all text-[11.5px] text-slate-400"><?= htmlspecialchars($a['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="<?= $tw['td'] ?>" data-label="Phone"><?= htmlspecialchars($a['phone'] ?? '—') ?></td>
                                    <td class="<?= $tw['td'] ?>" data-label="Status">
                                        <?php if ($is_active): ?>
                                            <span class="<?= $tw['badgeOk'] ?>"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>Active</span>
                                        <?php elseif ($has_pending_request): ?>
                                            <span class="<?= $tw['badgeWarn'] ?>"><i class="fas fa-hourglass-half"></i> Reactivation Pending</span>
                                        <?php else: ?>
                                            <span class="<?= $tw['badgeBad'] ?> cursor-pointer"
                                                onclick='showDeactivationReason(<?= json_encode($a['deactivation_reason'] ?: 'No reason recorded.') ?>, <?= json_encode($a['deactivated_at'] ? date('M d, Y g:i a', strtotime($a['deactivated_at'])) : '—') ?>, <?= json_encode($a['deactivated_by'] ?: null) ?>)'>
                                                <i class="fas fa-ban"></i> Deactivated <i class="fas fa-circle-info ml-0.5 text-[9px]"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= $tw['td'] ?>" data-label="Joined">
                                        <div class="text-xs text-slate-400"><?= date('M d, Y', strtotime($a['created_at'])) ?></div>
                                        <div class="text-[10.5px] text-slate-400"><?= date('g:i a', strtotime($a['created_at'])) ?></div>
                                    </td>
                                    <td class="<?= $tw['td'] ?>" data-label="Actions">
                                        <?php if ($is_active): ?>
                                            <button type="button" class="<?= $tw['btnRed'] ?>"
                                                onclick="showDeactivateModal('<?= htmlspecialchars($a['email'], ENT_QUOTES) ?>', 'client', '<?= htmlspecialchars($full_name !== '' ? $full_name : $a['email'], ENT_QUOTES) ?>')">
                                                <i class="fas fa-user-slash"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="<?= $tw['btnGreen'] ?>"
                                                onclick="reactivateUser('<?= htmlspecialchars($a['email'], ENT_QUOTES) ?>', 'client')">
                                                <i class="fas fa-user-check"></i> Reactivate
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="no-applicant-results" class="hidden p-10 text-center text-[13px] text-slate-400">
                        <i class="fas fa-search mb-2.5 block text-2xl"></i>
                        No applicants match your search.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Pending Invitations -->
        <div id="tab-pending" class="tab-content hidden">
            <div class="<?= $tw['card'] ?>">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Pending Invitations</div>
                        <div class="<?= $tw['sub'] ?>">Invites awaiting acceptance · expires after 24 hours</div>
                    </div>
                </div>

                <?php if (empty($pending_invitations)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyIcon'] ?>"><i class="fas fa-envelope-circle-check"></i></div>
                        <div class="<?= $tw['emptyTitle'] ?>">All caught up!</div>
                        <div class="<?= $tw['emptySub'] ?>">No pending invitations at the moment.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_invitations as $inv): ?>
                        <?php
                        $iparts = explode(' ', trim($inv['full_name'] ?? 'IV'));
                        $iinit  = strtoupper(count($iparts) >= 2
                            ? substr($iparts[0], 0, 1) . substr(end($iparts), 0, 1)
                            : substr($inv['full_name'], 0, 2));
                        $expired = strtotime($inv['expires_at']) < time();
                        ?>
                        <div class="<?= $tw['row'] ?>">
                            <div class="<?= $tw['avatar'] ?> bg-gradient-to-br from-amber-600 to-amber-400"><?= $iinit ?></div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13.5px] font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($inv['full_name']) ?></div>
                                <div class="break-all text-xs text-slate-400"><?= htmlspecialchars($inv['email']) ?></div>
                            </div>
                            <div class="shrink-0 sm:text-right">
                                <?php if ($expired): ?>
                                    <span class="<?= $tw['badgeBad'] ?>"><i class="fas fa-clock"></i> Expired</span>
                                <?php else: ?>
                                    <span class="<?= $tw['badgeWarn'] ?>"><i class="fas fa-hourglass-half"></i> Pending</span>
                                <?php endif; ?>
                                <div class="mt-1 text-[10.5px] text-slate-400">Sent <?= date('M d, Y', strtotime($inv['created_at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Expired Invitations -->
        <div id="tab-expired" class="tab-content hidden">
            <div class="<?= $tw['card'] ?>">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Expired Invitations</div>
                        <div class="<?= $tw['sub'] ?>">These invites were not accepted within 24 hours</div>
                    </div>
                </div>

                <?php if (empty($expired_invitations)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyIcon'] ?>"><i class="fas fa-ban"></i></div>
                        <div class="<?= $tw['emptyTitle'] ?>">No expired invitations</div>
                        <div class="<?= $tw['emptySub'] ?>">All invitations are still active or have been accepted.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($expired_invitations as $inv): ?>
                        <?php
                        $iparts = explode(' ', trim($inv['full_name'] ?? 'IV'));
                        $iinit  = strtoupper(count($iparts) >= 2
                            ? substr($iparts[0], 0, 1) . substr(end($iparts), 0, 1)
                            : substr($inv['full_name'], 0, 2));
                        ?>
                        <div class="<?= $tw['row'] ?> opacity-75">
                            <div class="<?= $tw['avatar'] ?> bg-red-100 !text-red-600"><?= $iinit ?></div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13.5px] font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($inv['full_name']) ?></div>
                                <div class="break-all text-xs text-slate-400"><?= htmlspecialchars($inv['email']) ?></div>
                                <div class="mt-1 text-[10.5px] text-red-600">Expired <?= date('M d, Y g:i a', strtotime($inv['expires_at'])) ?></div>
                            </div>
                            <div class="shrink-0">
                                <span class="<?= $tw['badgeBad'] ?>"><i class="fas fa-ban"></i> Expired</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Reactivation Requests -->
        <div id="tab-reactivation" class="tab-content hidden">
            <div class="<?= $tw['card'] ?>">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Reactivation Requests</div>
                        <div class="<?= $tw['sub'] ?>"><?= $reactivation_requests_count ?> pending request<?= $reactivation_requests_count !== 1 ? 's' : '' ?> awaiting review</div>
                    </div>
                </div>

                <?php if (empty($reactivation_requests)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyIcon'] ?>"><i class="fas fa-inbox"></i></div>
                        <div class="<?= $tw['emptyTitle'] ?>">No pending requests</div>
                        <div class="<?= $tw['emptySub'] ?>">Deactivated users who ask to be reactivated will appear here.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($reactivation_requests as $rr): ?>
                        <?php
                        $display_name = $rr['full_name'] ?? '';
                        $base_for_initials = $display_name !== '' ? $display_name : 'RQ';
                        $rparts = explode(' ', trim($base_for_initials));
                        $rinit  = strtoupper(count($rparts) >= 2
                            ? substr($rparts[0], 0, 1) . substr(end($rparts), 0, 1)
                            : substr($base_for_initials, 0, 2));
                        $roleLabel = $rr['usertype'] === 'evaluator' ? 'Evaluator' : 'Applicant';
                        $rr_has_pic = $rr['usertype'] === 'client' && !empty($rr['profile_picture']);
                        $rr_pic_url = $rr_has_pic ? '../' . $rr['profile_picture'] : null;
                        ?>
                        <div class="<?= $tw['row'] ?> items-start">
                            <div class="<?= $tw['avatar'] ?> <?= $rr_has_pic ? '' : 'bg-gradient-to-br from-indigo-600 to-indigo-400' ?>"
                                <?= $rr_has_pic ? "style=\"background-image:url('" . htmlspecialchars($rr_pic_url) . "')\" title=\"" . htmlspecialchars($display_name !== '' ? $display_name : $rr['email'], ENT_QUOTES) . '"' : '' ?>>
                                <?= $rr_has_pic ? '' : htmlspecialchars($rinit) ?>
                            </div>
                            <div class="min-w-0 flex-1 basis-60">
                                <div class="text-[13.5px] font-bold text-slate-900 dark:text-slate-100">
                                    <?= htmlspecialchars($display_name !== '' ? $display_name : $rr['email']) ?>
                                    <span class="ml-1.5 text-[10.5px] font-semibold text-slate-400">(<?= htmlspecialchars($roleLabel) ?>)</span>
                                </div>
                                <div class="break-all text-xs text-slate-400"><?= htmlspecialchars($rr['email']) ?></div>
                                <div class="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                                    <i class="fas fa-quote-left mr-1 text-[9px] text-slate-400"></i>
                                    <?= nl2br(htmlspecialchars($rr['reason'])) ?>
                                </div>
                                <div class="mt-1.5 text-[10.5px] text-slate-400">Requested <?= date('M d, Y g:i a', strtotime($rr['requested_at'])) ?></div>
                            </div>
                            <div class="flex w-full shrink-0 gap-1.5 sm:w-auto sm:flex-col">
                                <button type="button" class="<?= $tw['btnGreen'] ?> flex-1"
                                    onclick="approveReactivation(<?= (int)$rr['id'] ?>, '<?= htmlspecialchars($rr['email'], ENT_QUOTES) ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button type="button" class="<?= $tw['btnRed'] ?> flex-1"
                                    onclick="showRejectReactivationModal(<?= (int)$rr['id'] ?>, '<?= htmlspecialchars($rr['email'], ENT_QUOTES) ?>')">
                                    <i class="fas fa-xmark"></i> Reject
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Leave & Reassignment -->
        <div id="tab-leave" class="tab-content hidden">
            <div class="<?= $tw['card'] ?> mb-5">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Reassignment Requests</div>
                        <div class="<?= $tw['sub'] ?>"><?= $leave_requests_count ?> pending · the split below is proposed by least workload, you can change any row</div>
                    </div>
                </div>

                <?php if (empty($leave_requests)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyIcon'] ?>"><i class="fas fa-inbox"></i></div>
                        <div class="<?= $tw['emptyTitle'] ?>">No pending requests</div>
                        <div class="<?= $tw['emptySub'] ?>">Use “Leave” on an evaluator to start one on their behalf.</div>
                    </div>
                    <?php else: foreach ($leave_requests as $lr): ?>
                        <?php $form_id = 'reassignForm' . (int)$lr['id'];
                        $no_target = empty($lr['targets']); ?>
                        <form method="POST" id="<?= $form_id ?>" class="border-b border-slate-200 px-4 py-4 last:border-b-0 dark:border-slate-700 sm:px-5">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="approve_reassignment">
                            <input type="hidden" name="request_id" value="<?= (int)$lr['id'] ?>">

                            <div class="mb-2.5">
                                <div class="font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($lr['full_name']) ?>
                                    <span class="text-[10.5px] font-semibold text-slate-400">(<?= $lr['scope'] === 'all' ? 'all open cases' : 'selected cases' ?>)</span>
                                </div>
                                <div class="text-xs text-slate-400">
                                    <?= htmlspecialchars($leave_reasons[$lr['reason_category']] ?? 'Other') ?><?= !empty($lr['reason_other']) ? ' — “' . htmlspecialchars($lr['reason_other']) . '”' : '' ?> ·
                                    <?= $lr['expected_return'] ? 'back ' . date('M d, Y', strtotime($lr['expected_return'])) : 'no return date' ?> ·
                                    requested <?= date('M d, Y g:i a', strtotime($lr['requested_at'])) ?>
                                </div>
                            </div>

                            <?php if ($no_target): ?>
                                <div class="mb-3 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-600 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-400">
                                    <i class="fas fa-triangle-exclamation mt-0.5"></i> No other evaluator is available. The request stays pending until one is.
                                </div>
                            <?php endif; ?>

                            <?php if (empty($lr['cases'])): ?>
                                <div class="mb-2.5 text-[12.5px] text-slate-400">This evaluator has no open cases. Approving will only mark them as on leave.</div>
                            <?php else: ?>
                                <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                                    <table class="<?= $tw['table'] ?>">
                                        <thead class="<?= $tw['thead'] ?>">
                                            <tr>
                                                <th class="<?= $tw['th'] ?>">RSF Number</th>
                                                <th class="<?= $tw['th'] ?>">Applicant</th>
                                                <th class="<?= $tw['th'] ?>">Position</th>
                                                <th class="<?= $tw['th'] ?>">Status</th>
                                                <th class="<?= $tw['th'] ?>">Date Submitted / Updated</th>
                                                <th class="<?= $tw['th'] ?>">New evaluator</th>
                                            </tr>
                                        </thead>
                                        <tbody class="<?= $tw['tbody'] ?>">
                                            <?php foreach ($lr['cases'] as $c): $aid = (int)$c['id']; ?>
                                                <tr class="<?= $tw['tr'] ?>">
                                                    <td class="<?= $tw['td'] ?>" data-label="RSF Number">
                                                        <a href="view-application.php?id=<?= $aid ?>" target="_self" rel="noopener"
                                                            class="font-bold text-blue-700 hover:underline dark:text-blue-400"
                                                            title="View application #<?= $aid ?>">
                                                            <?= htmlspecialchars($c['rsf_number'] ?? ('#' . $aid)) ?>
                                                        </a>
                                                    </td>
                                                    <td class="<?= $tw['td'] ?>" data-label="Applicant"><?= htmlspecialchars($c['applicant_name'] ?? '—') ?></td>
                                                    <td class="<?= $tw['td'] ?>" data-label="Position"><?= htmlspecialchars($c['position_title'] ?? '—') ?></td>
                                                    <td class="<?= $tw['td'] ?>" data-label="Status"><?= htmlspecialchars(str_replace('_', ' ', $c['status'] ?? '')) ?></td>
                                                    <td class="<?= $tw['td'] ?>" data-label="Date Submitted / Updated">
                                                        <?php
                                                        $sub_ts = !empty($c['submitted_at']) ? strtotime($c['submitted_at']) : null;
                                                        $upd_ts = !empty($c['updated_at'])   ? strtotime($c['updated_at'])   : null;
                                                        ?>
                                                        <?php if ($sub_ts): ?>
                                                            <div class="text-xs text-slate-600 dark:text-slate-300">
                                                                <span class="text-[10px] font-bold uppercase text-slate-400">Submitted</span>
                                                                <?= date('M d, Y g:i a', $sub_ts) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($upd_ts && (!$sub_ts || $upd_ts > $sub_ts)): ?>
                                                            <div class="text-xs text-slate-600 dark:text-slate-300">
                                                                <span class="text-[10px] font-bold uppercase text-slate-400">Updated</span>
                                                                <?= date('M d, Y g:i a', $upd_ts) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!$sub_ts && !$upd_ts): ?>—<?php endif; ?>
                                                    </td>
                                                    <td class="<?= $tw['td'] ?>" data-label="New evaluator">
                                                        <select name="assign[<?= $aid ?>]" class="<?= $tw['input'] ?> !py-2">
                                                            <option value="0">— Skip (leave as is) —</option>
                                                            <?php foreach ($lr['targets'] as $t): ?>
                                                                <option value="<?= (int)$t['eid'] ?>" <?= ($lr['proposal'][$aid] ?? 0) === (int)$t['eid'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($t['full_name']) ?> (<?= (int)$t['open_cases'] ?> open)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <div class="mt-3.5">
                                <label class="<?= $tw['label'] ?>">Admin note (optional)</label>
                                <input type="text" name="admin_comment" class="<?= $tw['input'] ?>" maxlength="500">
                            </div>
                            <div class="mt-3.5 flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                                <button type="button" class="<?= $tw['btnRed'] ?> !py-2.5" onclick="rejectReassignment(<?= (int)$lr['id'] ?>)"><i class="fas fa-xmark"></i> Reject</button>
                                <button type="button" class="<?= $tw['btnPrimary'] ?>" <?= $no_target ? 'disabled' : '' ?>
                                    onclick="approveReassignment('<?= $form_id ?>', <?= count($lr['cases']) ?>)"><i class="fas fa-check"></i> Approve &amp; reassign</button>
                            </div>
                        </form>
                <?php endforeach;
                endif; ?>
            </div>

            <div class="<?= $tw['card'] ?>">
                <div class="<?= $tw['cardHead'] ?>">
                    <div>
                        <div class="<?= $tw['title'] ?>">Currently on Leave</div>
                        <div class="<?= $tw['sub'] ?>"><?= count($on_leave) ?> evaluator<?= count($on_leave) !== 1 ? 's' : '' ?> unavailable for new cases</div>
                    </div>
                </div>
                <?php if (empty($on_leave)): ?>
                    <div class="<?= $tw['empty'] ?>">
                        <div class="<?= $tw['emptyTitle'] ?> !mb-0">Everyone is available</div>
                    </div>
                    <?php else: foreach ($on_leave as $ol): ?>
                        <div class="<?= $tw['row'] ?>">
                            <div class="min-w-0 flex-1 basis-52">
                                <div class="text-[13.5px] font-bold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($ol['full_name']) ?></div>
                                <div class="text-xs text-slate-400">
                                    <?= htmlspecialchars($leave_reasons[$ol['reason_category']] ?? 'Other') ?><?= ($ol['reason_category'] === 'other' && !empty($ol['reason_other'])) ? ' — “' . htmlspecialchars($ol['reason_other']) . '”' : '' ?> ·
                                    since <?= date('M d, Y', strtotime($ol['start_at'])) ?> ·
                                    <?= $ol['end_at'] ? 'back ' . date('M d, Y', strtotime($ol['end_at'])) : 'no return date' ?>
                                </div>
                            </div>
                            <button type="button" class="<?= $tw['btnGreen'] ?>" onclick="endLeave(<?= (int)$ol['evaluator_id'] ?>)">
                                <i class="fas fa-plane-arrival"></i> End leave
                            </button>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div><!-- /main -->

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <?php
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
                <div class="flyout" id="appsMenu">
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
            <a href="user.php" class="dk active" data-tip="Users">
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

    <!-- ── INVITE MODAL ── -->
    <div id="inviteModal" class="modal-overlay fixed inset-0 z-[300] hidden items-center justify-center bg-blue-950/55 p-4 backdrop-blur-sm sm:p-5">
        <div class="max-h-[92vh] w-full max-w-[460px] overflow-y-auto rounded-3xl bg-white shadow-2xl dark:bg-slate-800">
            <div class="relative bg-gradient-to-br from-blue-950 to-blue-900 px-6 pb-4 pt-5">
                <h3 class="pr-8 text-[17px] font-extrabold text-white"><i class="fas fa-paper-plane mr-2 opacity-80"></i>Send Evaluator Invitation</h3>
                <p class="mt-1 text-xs text-white/60">An email will be sent with a secure setup link.</p>
                <button type="button" class="absolute right-5 top-4 flex h-[30px] w-[30px] items-center justify-center rounded-lg bg-white/15 text-base text-white hover:bg-white/25" onclick="hideInviteModal()">×</button>
            </div>
            <form method="POST" id="inviteForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="invite">
                <div class="space-y-4 px-6 py-6">
                    <div>
                        <label class="<?= $tw['label'] ?>">Email Address</label>
                        <input type="email" name="email" required class="<?= $tw['input'] ?>" placeholder="evaluator@dmw.gov.ph">
                    </div>
                    <div>
                        <label class="<?= $tw['label'] ?>">Full Name</label>
                        <input type="text" name="full_name" required class="<?= $tw['input'] ?>" placeholder="Juan Dela Cruz">
                    </div>
                    <div>
                        <label class="<?= $tw['label'] ?>">Cellphone Number</label>
                        <input type="tel" name="phone" required class="<?= $tw['input'] ?>" placeholder="09171234567"
                            inputmode="numeric" maxlength="11" minlength="11"
                            pattern="09[0-9]{9}"
                            title="Enter an 11-digit mobile number starting with 09 (e.g., 09171234567)"
                            autocomplete="off"
                            oninput="this.value = this.value.replace(/\D/g, '').slice(0, 11)">
                        <div class="<?= $tw['hint'] ?>"><i class="fas fa-info-circle"></i> Format: 09171234567 (11 digits, numbers only)</div>
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-2.5 border-t border-slate-200 px-6 pb-5 pt-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                    <button type="button" class="<?= $tw['btnCancel'] ?>" onclick="hideInviteModal()">Cancel</button>
                    <button type="submit" class="<?= $tw['btnPrimary'] ?>"><i class="fas fa-paper-plane"></i> Send Invitation</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── DEACTIVATE ACCOUNT MODAL ── -->
    <div id="deactivateModal" class="modal-overlay fixed inset-0 z-[300] hidden items-center justify-center bg-blue-950/55 p-4 backdrop-blur-sm sm:p-5">
        <div class="max-h-[92vh] w-full max-w-[460px] overflow-y-auto rounded-3xl bg-white shadow-2xl dark:bg-slate-800">
            <div class="relative bg-gradient-to-br from-red-600 to-red-800 px-6 pb-4 pt-5">
                <h3 class="pr-8 text-[17px] font-extrabold text-white"><i class="fas fa-user-slash mr-2 opacity-80"></i>Deactivate Account</h3>
                <p id="deactivateModalSub" class="mt-1 text-xs text-white/70">This will immediately prevent the user from logging in.</p>
                <button type="button" class="absolute right-5 top-4 flex h-[30px] w-[30px] items-center justify-center rounded-lg bg-white/15 text-base text-white hover:bg-white/25" onclick="hideDeactivateModal()">×</button>
            </div>
            <form method="POST" id="deactivateForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="deactivate_user">
                <input type="hidden" name="target_email" id="deactivate_target_email">
                <input type="hidden" name="target_usertype" id="deactivate_target_usertype">
                <div class="space-y-4 px-6 py-6">
                    <div>
                        <label class="<?= $tw['label'] ?>">Reason for Deactivation</label>
                        <select name="reason_preset" id="reason_preset" class="<?= $tw['input'] ?>" required onchange="toggleOtherReason()">
                            <option value="">— Select a reason —</option>
                        </select>
                    </div>
                    <div id="otherReasonField" class="hidden">
                        <label class="<?= $tw['label'] ?>">Please Specify</label>
                        <input type="text" name="reason_other" id="reason_other" class="<?= $tw['input'] ?>" placeholder="Enter specific reason...">
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-2.5 border-t border-slate-200 px-6 pb-5 pt-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                    <button type="button" class="<?= $tw['btnCancel'] ?>" onclick="hideDeactivateModal()">Cancel</button>
                    <button type="submit" class="<?= $tw['btnDanger'] ?>"><i class="fas fa-user-slash"></i> Deactivate Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden form for Reactivate action (submitted via JS after confirmation) -->
    <form method="POST" id="reactivateForm" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="reactivate_user">
        <input type="hidden" name="target_email" id="reactivate_target_email">
        <input type="hidden" name="target_usertype" id="reactivate_target_usertype">
    </form>

    <!-- Hidden form for Approve Reactivation Request (submitted via JS after confirmation) -->
    <form method="POST" id="approveReactivationForm" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="approve_reactivation">
        <input type="hidden" name="request_id" id="approve_request_id">
    </form>

    <!-- ── REJECT REACTIVATION REQUEST MODAL ── -->
    <div id="rejectReactivationModal" class="modal-overlay fixed inset-0 z-[300] hidden items-center justify-center bg-blue-950/55 p-4 backdrop-blur-sm sm:p-5">
        <div class="max-h-[92vh] w-full max-w-[460px] overflow-y-auto rounded-3xl bg-white shadow-2xl dark:bg-slate-800">
            <div class="relative bg-gradient-to-br from-red-600 to-red-800 px-6 pb-4 pt-5">
                <h3 class="pr-8 text-[17px] font-extrabold text-white"><i class="fas fa-xmark mr-2 opacity-80"></i>Reject Reactivation Request</h3>
                <p id="rejectReactivationModalSub" class="mt-1 text-xs text-white/70">Let the user know why this request is being declined.</p>
                <button type="button" class="absolute right-5 top-4 flex h-[30px] w-[30px] items-center justify-center rounded-lg bg-white/15 text-base text-white hover:bg-white/25" onclick="hideRejectReactivationModal()">×</button>
            </div>
            <form method="POST" id="rejectReactivationForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="reject_reactivation">
                <input type="hidden" name="request_id" id="reject_request_id">
                <div class="px-6 py-6">
                    <label class="<?= $tw['label'] ?>">Reason for Rejection</label>
                    <textarea name="admin_comment" id="reject_admin_comment" class="<?= $tw['input'] ?> resize-y" rows="4" placeholder="Explain why this reactivation request is being declined..." required></textarea>
                    <div class="<?= $tw['hint'] ?>"><i class="fas fa-info-circle"></i> This message will be sent to the user.</div>
                </div>
                <div class="flex flex-col-reverse gap-2.5 border-t border-slate-200 px-6 pb-5 pt-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                    <button type="button" class="<?= $tw['btnCancel'] ?>" onclick="hideRejectReactivationModal()">Cancel</button>
                    <button type="submit" class="<?= $tw['btnDanger'] ?>"><i class="fas fa-xmark"></i> Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── LEAVE MODAL ── -->
    <div id="leaveModal" class="modal-overlay fixed inset-0 z-[300] hidden items-center justify-center bg-blue-950/55 p-4 backdrop-blur-sm sm:p-5">
        <div class="max-h-[92vh] w-full max-w-[460px] overflow-y-auto rounded-3xl bg-white shadow-2xl dark:bg-slate-800">
            <div class="relative bg-gradient-to-br from-blue-950 to-blue-900 px-6 pb-4 pt-5">
                <h3 class="pr-8 text-[17px] font-extrabold text-white"><i class="fas fa-plane-departure mr-2 opacity-80"></i>Set Evaluator Leave</h3>
                <p id="leaveModalSub" class="mt-1 text-xs text-white/60"></p>
                <button type="button" class="absolute right-5 top-4 flex h-[30px] w-[30px] items-center justify-center rounded-lg bg-white/15 text-base text-white hover:bg-white/25" onclick="hideLeaveModal()">×</button>
            </div>
            <form method="POST" id="leaveForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="evaluator_id" id="leave_evaluator_id">
                <div class="space-y-4 px-6 py-6">
                    <div>
                        <label class="<?= $tw['label'] ?>">What should happen?</label>
                        <select name="action" id="leave_action" class="<?= $tw['input'] ?>">
                            <option value="create_leave_request">Reassign open cases and mark as on leave</option>
                            <option value="set_unavailability">Mark as on leave only (keep their cases)</option>
                        </select>
                    </div>
                    <div>
                        <label class="<?= $tw['label'] ?>">Reason category</label>
                        <select name="reason_category" class="<?= $tw['input'] ?>" required>
                            <?php foreach ($leave_reasons as $k => $label): ?>
                                <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="<?= $tw['hint'] ?>"><i class="fas fa-info-circle"></i> Category only. Do not record medical or family details.</div>
                    </div>
                    <div>
                        <label class="<?= $tw['label'] ?>">Expected return (optional)</label>
                        <input type="date" name="expected_return" class="<?= $tw['input'] ?>" min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-2.5 border-t border-slate-200 px-6 pb-5 pt-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                    <button type="button" class="<?= $tw['btnCancel'] ?>" onclick="hideLeaveModal()">Cancel</button>
                    <button type="submit" class="<?= $tw['btnPrimary'] ?>"><i class="fas fa-check"></i> Continue</button>
                </div>
            </form>
        </div>
    </div>

    <form method="POST" id="endLeaveForm" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="end_unavailability">
        <input type="hidden" name="evaluator_id" id="end_leave_evaluator_id">
    </form>
    <form method="POST" id="rejectReassignForm" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="reject_reassignment">
        <input type="hidden" name="request_id" id="reject_reassign_id">
        <input type="hidden" name="admin_comment" id="reject_reassign_comment">
    </form>

    <script>
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

        // Modal helpers (Tailwind: hidden <-> flex)
        function openModal(id) {
            const m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) closeModal(m.id);
            });
        });

        function switchTab(name, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => el.setAttribute('aria-selected', 'false'));
            document.getElementById('tab-' + name).classList.remove('hidden');
            btn.setAttribute('aria-selected', 'true');
        }
        // Show the default tab on load
        document.getElementById('tab-registered').classList.remove('hidden');

        function filterRows(inputId, rowSelector, emptyId) {
            const q = document.getElementById(inputId).value.toLowerCase();
            let visible = 0;
            document.querySelectorAll(rowSelector).forEach(row => {
                const show = row.textContent.toLowerCase().includes(q);
                // Rows use block/table-row via classes; toggle with `hidden`
                row.classList.toggle('hidden', !show);
                if (show) visible++;
            });
            document.getElementById(emptyId).classList.toggle('hidden', visible !== 0);
        }

        function filterTable() {
            filterRows('evalSearch', '.ev-row', 'no-results');
        }

        function filterApplicantTable() {
            filterRows('applicantSearch', '.ap-row', 'no-applicant-results');
        }

        function toggleFlyout(e, id) {
            e.stopPropagation();
            const el = document.getElementById(id);
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
        });

        function showInviteModal() {
            openModal('inviteModal');
        }

        function hideInviteModal() {
            closeModal('inviteModal');
        }

        // Reasons available per account type — mirrors $deactivation_reasons_by_type in PHP
        const reasonsByType = <?= json_encode($deactivation_reasons_by_type) ?>;

        function showDeactivateModal(email, usertype, name) {
            document.getElementById('deactivate_target_email').value = email;
            document.getElementById('deactivate_target_usertype').value = usertype;
            const roleLabel = usertype === 'evaluator' ? 'evaluator' : 'applicant';
            document.getElementById('deactivateModalSub').textContent =
                `You are about to deactivate ${name}'s account (${roleLabel}). They will be logged out immediately and unable to log in until reactivated.`;

            const select = document.getElementById('reason_preset');
            const reasons = reasonsByType[usertype] || [];
            select.innerHTML = '<option value="">— Select a reason —</option>' +
                reasons.map(r => `<option value="${r.replace(/"/g, '&quot;')}">${r}</option>`).join('');

            document.getElementById('reason_other').value = '';
            document.getElementById('otherReasonField').classList.add('hidden');
            openModal('deactivateModal');
        }

        function hideDeactivateModal() {
            closeModal('deactivateModal');
        }

        function toggleOtherReason() {
            const val = document.getElementById('reason_preset').value;
            document.getElementById('otherReasonField').classList.toggle('hidden', val !== 'Other');
        }

        function showDeactivationReason(reason, date, deactivatedBy) {
            const performedBy = deactivatedBy ? deactivatedBy : 'Self-service (user deactivated own account)';
            Swal.fire({
                title: 'Deactivation Details',
                html: `
            <div style="text-align:left;font-size:13px;line-height:1.6;">
                <p><strong>Reason:</strong> ${reason}</p>
                <p><strong>Deactivated At:</strong> ${date}</p>
                <p><strong>Performed By:</strong> ${performedBy}</p>
            </div>
        `,
                icon: 'info',
                confirmButtonColor: '#1d4ed8'
            });
        }

        document.getElementById('deactivateForm').addEventListener('submit', function(e) {
            const preset = document.getElementById('reason_preset').value;
            const other = document.getElementById('reason_other').value.trim();
            if (!preset) {
                e.preventDefault();
                Swal.fire('Missing reason', 'Please select a reason for deactivation.', 'warning');
                return;
            }
            if (preset === 'Other' && other.length < 5) {
                e.preventDefault();
                Swal.fire('Missing details', 'Please specify a reason (at least 5 characters).', 'warning');
            }
        });

        function reactivateUser(email, usertype) {
            Swal.fire({
                title: 'Reactivate this account?',
                text: 'The user will be able to log in again immediately.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, reactivate',
                confirmButtonColor: '#059669'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('reactivate_target_email').value = email;
                    document.getElementById('reactivate_target_usertype').value = usertype;
                    document.getElementById('reactivateForm').submit();
                }
            });
        }

        // Approve reactivation request (confirm then submit hidden form)
        function approveReactivation(requestId, email) {
            Swal.fire({
                title: 'Approve this request?',
                text: `${email} will be reactivated and able to log in immediately.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve',
                confirmButtonColor: '#059669'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('approve_request_id').value = requestId;
                    document.getElementById('approveReactivationForm').submit();
                }
            });
        }

        // Reject Reactivation Modal
        function showRejectReactivationModal(requestId, email) {
            document.getElementById('reject_request_id').value = requestId;
            document.getElementById('rejectReactivationModalSub').textContent =
                `You are about to reject the reactivation request from ${email}. Please provide a reason.`;
            document.getElementById('reject_admin_comment').value = '';
            openModal('rejectReactivationModal');
        }

        function hideRejectReactivationModal() {
            closeModal('rejectReactivationModal');
        }

        document.getElementById('rejectReactivationForm').addEventListener('submit', function(e) {
            const comment = document.getElementById('reject_admin_comment').value.trim();
            if (comment.length < 5) {
                e.preventDefault();
                Swal.fire('Missing reason', 'Please provide a reason (at least 5 characters) for the user.', 'warning');
            }
        });

        function showLeaveModal(btn) {
            document.getElementById('leave_evaluator_id').value = btn.dataset.eid;
            document.getElementById('leaveModalSub').textContent = 'Set leave for ' + btn.dataset.name + '. They will stop receiving new cases.';
            openModal('leaveModal');
        }

        function hideLeaveModal() {
            closeModal('leaveModal');
        }

        function endLeave(eid) {
            Swal.fire({
                title: 'End this leave?',
                text: 'The evaluator can claim and evaluate cases again.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, end leave',
                confirmButtonColor: '#059669'
            }).then(r => {
                if (r.isConfirmed) {
                    document.getElementById('end_leave_evaluator_id').value = eid;
                    document.getElementById('endLeaveForm').submit();
                }
            });
        }

        function approveReassignment(formId, count) {
            Swal.fire({
                title: 'Approve this request?',
                text: count + ' case(s) will be moved as shown. Rows set to “Skip” stay with the current evaluator.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve',
                confirmButtonColor: '#059669'
            }).then(r => {
                if (r.isConfirmed) document.getElementById(formId).submit();
            });
        }

        function rejectReassignment(id) {
            Swal.fire({
                title: 'Reject this request?',
                input: 'textarea',
                inputLabel: 'Reason (sent to the evaluator)',
                inputAttributes: {
                    maxlength: 500
                },
                showCancelButton: true,
                confirmButtonText: 'Reject',
                confirmButtonColor: '#dc2626',
                preConfirm: v => (v && v.trim().length >= 5) ? v.trim() : Swal.showValidationMessage('Please enter at least 5 characters.')
            }).then(r => {
                if (r.isConfirmed) {
                    document.getElementById('reject_reassign_id').value = id;
                    document.getElementById('reject_reassign_comment').value = r.value;
                    document.getElementById('rejectReassignForm').submit();
                }
            });
        }
        <?php if (($flash_tab ?? '') === 'leave'): ?>
            switchTab('leave', document.getElementById('tabBtnLeave'));
        <?php endif; ?>

        <?php if ($success || $error): ?>
            Swal.fire({
                title: <?= $success ? "'Success!'" : "'Error'" ?>,
                html: <?= json_encode($success ?: $error) ?>,
                icon: <?= $success ? "'success'" : "'error'" ?>,
                confirmButtonColor: "#1d4ed8",
                timer: 5000,
                timerProgressBar: true
            });
        <?php endif; ?>
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
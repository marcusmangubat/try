<?php
// user.php
session_start();
require_once '../db.php';
require_once '../includes/mailer.php';

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
function pg_redirect_with_flash($success = '', $error = '')
{
    if ($success !== '') $_SESSION['flash_success'] = $success;
    if ($error !== '')   $_SESSION['flash_error']   = $error;
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

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

                    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM applications WHERE current_evaluator_id = ? AND handling_party = 'evaluator'");
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
// Recover flash messages set by the PRG redirect above (if any)
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Fetch Evaluators (with derived account status)
$stmt = $database->prepare("SELECT e.full_name, e.email, e.phone, e.created_at,
                            w.is_active, w.deactivated_at, w.deactivation_reason, w.deactivated_by,
                            rr.status AS pending_status
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluators – DMW RO IV-A Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        :root {
            --navy: #0b1f4b;
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
            --indigo: #4f46e5;
            --green: #059669;
            --amber: #d97706;
            --red: #dc2626;
            --card: #ffffff;
            --surface: #f8fafc;
            --border: #e2e8f0;
            --text1: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;
        }

        [data-theme="dark"] {
            --navy: #3b82f6;
            --blue: #3b82f6;
            --blue-lt: #60a5fa;
            --indigo: #818cf8;
            --green: #34d399;
            --amber: #fbbf24;
            --red: #f87171;
            --card: #1e293b;
            --surface: #0f172a;
            --border: #2d3748;
            --text1: #e2e8f0;
            --text2: #94a3b8;
            --text3: #64748b;
        }

        [data-theme="dark"] .stat-icon.blue {
            background: rgba(59, 130, 246, .15);
        }

        [data-theme="dark"] .stat-icon.green {
            background: rgba(16, 185, 129, .15);
        }

        [data-theme="dark"] .stat-icon.amber {
            background: rgba(217, 119, 6, .15);
        }

        [data-theme="dark"] .tab-btn.active {
            background: var(--card);
            color: #fff;
            box-shadow: 0 1px 6px rgba(0, 0, 0, .35);
        }

        [data-theme="dark"] .main-card-head {
            background: linear-gradient(135deg, #1a2332 0%, #1e2b45 100%);
        }

        [data-theme="dark"] tbody tr:hover {
            background: #202a3d;
        }

        [data-theme="dark"] .modal-input {
            background: var(--surface);
            color: var(--text1);
            border-color: var(--border);
        }

        [data-theme="dark"] .modal-input:focus {
            background: #1a2332;
        }

        [data-theme="dark"] .badge-active {
            background: rgba(16, 185, 129, .15);
            border-color: rgba(52, 211, 153, .35);
        }

        [data-theme="dark"] .badge-pending,
        [data-theme="dark"] .badge-reactivation-pending {
            background: rgba(217, 119, 6, .15);
            border-color: rgba(251, 191, 36, .35);
        }

        [data-theme="dark"] .badge-expired,
        [data-theme="dark"] .badge-deactivated {
            background: rgba(220, 38, 38, .15);
            border-color: rgba(248, 113, 113, .35);
        }

        [data-theme="dark"] .btn-action-deactivate {
            background: rgba(220, 38, 38, .15);
            border-color: rgba(248, 113, 113, .35);
        }

        [data-theme="dark"] .btn-action-deactivate:hover {
            background: rgba(220, 38, 38, .25);
        }

        [data-theme="dark"] .btn-action-reactivate {
            background: rgba(16, 185, 129, .15);
            border-color: rgba(52, 211, 153, .35);
        }

        [data-theme="dark"] .btn-action-reactivate:hover {
            background: rgba(16, 185, 129, .25);
        }

        [data-theme="dark"] .empty-icon {
            background: linear-gradient(135deg, #1a2332, #202a45);
        }

        [data-theme="dark"] .alert-error {
            background: rgba(220, 38, 38, .12);
            border-color: rgba(248, 113, 113, .35);
        }

        [data-theme="dark"] .alert-success {
            background: rgba(16, 185, 129, .12);
            border-color: rgba(52, 211, 153, .35);
            color: #34d399;
        }

        [data-theme="dark"] .inv-row {
            border-color: var(--border);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--surface);
            color: var(--text1);
        }

        .stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
            animation: fadeUp .4s both;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .stat-card.blue::before {
            background: linear-gradient(90deg, var(--blue), var(--blue-lt));
        }

        .stat-card.green::before {
            background: linear-gradient(90deg, var(--green), #34d399);
        }

        .stat-card.amber::before {
            background: linear-gradient(90deg, var(--amber), #fbbf24);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            background: #eff6ff;
            color: var(--blue);
        }

        .stat-icon.green {
            background: #ecfdf5;
            color: var(--green);
        }

        .stat-icon.amber {
            background: #fffbeb;
            color: var(--amber);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--text1);
            line-height: 1;
        }

        .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text3);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-top: 4px;
        }

        .tab-bar {
            display: flex;
            gap: 4px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 5px;
            margin-bottom: 18px;
            width: fit-content;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 8px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'Sora', sans-serif;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text3);
            background: transparent;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .tab-btn.active {
            background: var(--card);
            color: var(--navy);
            box-shadow: 0 1px 6px rgba(0, 0, 0, .08);
        }

        .tab-badge {
            background: var(--amber);
            color: #fff;
            border-radius: 20px;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 700;
        }

        .tab-count-badge {
            background: #e0e7ff;
            color: var(--indigo);
        }

        [data-theme="dark"] .tab-count-badge {
            background: rgba(99, 102, 241, .18);
        }

        [data-theme="dark"] .tab-count-badge {
            background: rgba(99, 102, 241, .18);
        }

        .tab-expired-badge {
            background: #fee2e2;
            color: var(--red);
        }

        [data-theme="dark"] .tab-expired-badge {
            background: rgba(220, 38, 38, .18);
        }

        .main-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            animation: fadeUp .5s .1s both;
        }

        .main-card-head {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
        }

        .main-card-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--navy);
        }

        .main-card-sub {
            font-size: 11.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 1px;
        }

        .search-wrap {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .search-input {
            width: 100%;
            max-width: 340px;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Sora', sans-serif;
            font-size: 12.5px;
            background: var(--card);
            color: var(--text1);
            position: relative;
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap i {
            position: absolute;
            left: 34px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text3);
            font-size: 12px;
            pointer-events: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            padding: 12px 20px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text3);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #f8faff;
        }

        tbody td {
            padding: 14px 20px;
            font-size: 13px;
            color: var(--text2);
            vertical-align: middle;
        }

        .ev-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
        }

        .ev-name {
            font-weight: 700;
            font-size: 13.5px;
            color: var(--text1);
        }

        .ev-email {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 1px;
        }

        .badge-active {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ecfdf5;
            color: var(--green);
            border: 1px solid #a7f3d0;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .badge-active::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--green);
            animation: pulse-dot 2s infinite;
        }

        .badge-pending {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fffbeb;
            color: var(--amber);
            border: 1px solid #fde68a;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .badge-expired {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fecaca;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .badge-deactivated {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fecaca;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .badge-reactivation-pending {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fffbeb;
            color: var(--amber);
            border: 1px solid #fde68a;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .btn-action-deactivate {
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: var(--red);
            font-family: 'Sora', sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            white-space: nowrap;
        }

        .btn-action-deactivate:hover {
            background: #fee2e2;
        }

        .btn-action-reactivate {
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
            color: var(--green);
            font-family: 'Sora', sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            white-space: nowrap;
        }

        .btn-action-reactivate:hover {
            background: #d1fae5;
        }

        .empty-state {
            padding: 60px 24px;
            text-align: center;
        }

        .empty-icon {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #eff6ff, #eef2ff);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--blue-lt);
            margin: 0 auto 18px;
        }

        .empty-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--text1);
            margin-bottom: 6px;
        }

        .empty-sub {
            font-size: 13px;
            color: var(--text3);
            margin-bottom: 22px;
        }

        .invite-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
            color: #fff;
            padding: 10px 22px;
            border-radius: 12px;
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 4px 14px rgba(29, 78, 216, .3);
            position: relative;
            overflow: hidden;
        }

        .invite-btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 60%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .15), transparent);
            transform: skewX(-20deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            to {
                left: 160%;
            }
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 31, 75, .55);
            backdrop-filter: blur(4px);
            z-index: 300;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--card);
            border-radius: 22px;
            width: 100%;
            max-width: 460px;
            overflow: hidden;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .18);
            animation: modalPop .25s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes modalPop {
            from {
                opacity: 0;
                transform: scale(.92) translateY(20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            padding: 22px 26px 18px;
            background: linear-gradient(135deg, var(--navy) 0%, #1e3a8a 100%);
            position: relative;
        }

        .modal-header h3 {
            font-size: 17px;
            font-weight: 800;
            color: #fff;
        }

        .modal-header p {
            font-size: 12px;
            color: rgba(255, 255, 255, .6);
            margin-top: 3px;
        }

        .modal-close {
            position: absolute;
            top: 18px;
            right: 20px;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .15);
            border: none;
            cursor: pointer;
            color: #fff;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, .25);
        }

        .modal-body {
            padding: 24px 26px;
        }

        .modal-field {
            margin-bottom: 18px;
        }

        .modal-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text3);
            margin-bottom: 7px;
        }

        .modal-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 11px;
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            color: var(--text1);
            background: var(--surface);
            transition: border-color .2s;
        }

        .modal-input:focus {
            outline: none;
            border-color: var(--blue-lt);
            background: #fff;
        }

        .modal-hint {
            font-size: 11px;
            color: var(--text3);
            margin-top: 5px;
        }

        .modal-footer {
            padding: 16px 26px 22px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-cancel {
            padding: 10px 20px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text2);
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
        }

        .btn-cancel:hover {
            background: var(--border);
        }

        .btn-send {
            padding: 10px 24px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
            color: #fff;
            border: none;
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity .2s;
        }

        .btn-send:hover {
            opacity: .9;
        }

        .alert {
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            animation: fadeUp .3s both;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--red);
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #065f46;
        }

        .inv-row {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .inv-row:last-child {
            border-bottom: none;
        }

        .inv-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--amber), #f59e0b);
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse-dot {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .4;
            }
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 640px) {
            .stats-strip {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 22px;
            }
        }

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

<body>

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
            <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= $success ?></div>
        <?php endif; ?>

        <div class="stats-strip">
            <div class="stat-card blue" style="animation-delay:.05s">
                <div class="stat-icon blue"><i class="fas fa-users-gear"></i></div>
                <div>
                    <div class="stat-value"><?= $active_evaluators_count ?></div>
                    <div class="stat-label">Active Evaluators</div>
                </div>
            </div>
            <div class="stat-card green" style="animation-delay:.1s">
                <div class="stat-icon green"><i class="fas fa-user-group"></i></div>
                <div>
                    <div class="stat-value"><?= $active_applicants_count ?></div>
                    <div class="stat-label">Active Applicants</div>
                </div>
            </div>
            <div class="stat-card amber" style="animation-delay:.15s">
                <div class="stat-icon amber"><i class="fas fa-envelope-open-text"></i></div>
                <div>
                    <div class="stat-value"><?= $pending_count ?></div>
                    <div class="stat-label">Pending Invitations</div>
                </div>
            </div>
        </div>

        <!-- Tabs + Invite Button Row -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('registered', this)">
                    <i class="fas fa-users"></i> Evaluators
                    <span class="tab-count-badge" style="border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;"><?= $total_evaluators ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('applicants', this)">
                    <i class="fas fa-user-group"></i> Applicants
                    <span class="tab-count-badge" style="border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;"><?= $total_applicants ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('pending', this)">
                    <i class="fas fa-clock"></i> Pending Invites
                    <?php if ($pending_count > 0): ?>
                        <span class="tab-badge"><?= $pending_count ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" onclick="switchTab('expired', this)">
                    <i class="fas fa-ban"></i> Expired
                    <?php if ($expired_count > 0): ?>
                        <span class="tab-expired-badge" style="border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;"><?= $expired_count ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" onclick="switchTab('reactivation', this)">
                    <i class="fas fa-rotate-left"></i> Reactivation Requests
                    <?php if ($reactivation_requests_count > 0): ?>
                        <span class="tab-badge"><?= $reactivation_requests_count ?></span>
                    <?php endif; ?>
                </button>
            </div>
            <button class="invite-btn" onclick="showInviteModal()">
                <i class="fas fa-paper-plane"></i> Send Invitation
            </button>
        </div>

        <!-- TAB: Registered Evaluators -->
        <div id="tab-registered" class="tab-content active">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Registered Evaluators</div>
                        <div class="main-card-sub"><?= $total_evaluators ?> account<?= $total_evaluators !== 1 ? 's' : '' ?> · <?= $active_evaluators_count ?> active, <?= $deactivated_evaluators_count ?> deactivated</div>
                    </div>
                </div>

                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-input" id="evalSearch" placeholder="Search by name or email..." oninput="filterTable()">
                </div>

                <?php if (empty($evaluators)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
                        <div class="empty-title">No evaluators yet</div>
                        <div class="empty-sub">Send your first invitation to get started.</div>
                        <button class="invite-btn" onclick="showInviteModal()" style="margin:0 auto;">
                            <i class="fas fa-paper-plane"></i> Send First Invitation
                        </button>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table id="evalTable">
                            <thead>
                                <tr>
                                    <th>Evaluator</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluators as $e): ?>
                                    <?php
                                    $parts = explode(' ', trim($e['full_name'] ?? 'EV'));
                                    $initials = strtoupper(count($parts) >= 2
                                        ? substr($parts[0], 0, 1) . substr(end($parts), 0, 1)
                                        : substr($e['full_name'], 0, 2));
                                    $is_active = (int)($e['is_active'] ?? 1) === 1;
                                    $has_pending_request = !empty($e['pending_status']);
                                    ?>
                                    <tr class="ev-row">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:12px;">
                                                <div class="ev-avatar"><?= $initials ?></div>
                                                <div>
                                                    <div class="ev-name"><?= htmlspecialchars($e['full_name'] ?? '—') ?></div>
                                                    <div class="ev-email"><?= htmlspecialchars($e['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($e['phone'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($is_active): ?>
                                                <span class="badge-active">Active</span>
                                            <?php elseif ($has_pending_request): ?>
                                                <span class="badge-reactivation-pending"><i class="fas fa-hourglass-half"></i> Reactivation Pending</span>
                                            <?php else: ?>
                                                <span class="badge-deactivated" style="cursor:pointer;"
                                                    onclick='showDeactivationReason(<?= json_encode($e['deactivation_reason'] ?: 'No reason recorded.') ?>, <?= json_encode($e['deactivated_at'] ? date('M d, Y g:i a', strtotime($e['deactivated_at'])) : '—') ?>, <?= json_encode($e['deactivated_by'] ?: null) ?>)'>
                                                    <i class="fas fa-ban"></i> Deactivated <i class="fas fa-circle-info" style="font-size:9px;margin-left:2px;"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px;color:var(--text3);">
                                            <div><?= date('M d, Y', strtotime($e['created_at'])) ?></div>
                                            <div style="font-size:10.5px;"><?= date('g:i a', strtotime($e['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($is_active): ?>
                                                <button type="button" class="btn-action-deactivate"
                                                    onclick="showDeactivateModal('<?= htmlspecialchars($e['email'], ENT_QUOTES) ?>', 'evaluator', '<?= htmlspecialchars($e['full_name'] ?? $e['email'], ENT_QUOTES) ?>')">
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-action-reactivate"
                                                    onclick="reactivateUser('<?= htmlspecialchars($e['email'], ENT_QUOTES) ?>', 'evaluator')">
                                                    <i class="fas fa-user-check"></i> Reactivate
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="no-results" style="display:none;padding:40px;text-align:center;color:var(--text3);font-size:13px;">
                            <i class="fas fa-search" style="font-size:24px;margin-bottom:10px;display:block;"></i>
                            No evaluators match your search.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Registered Applicants -->
        <div id="tab-applicants" class="tab-content">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Registered Applicants</div>
                        <div class="main-card-sub"><?= $total_applicants ?> account<?= $total_applicants !== 1 ? 's' : '' ?> · <?= $active_applicants_count ?> active, <?= $deactivated_applicants_count ?> deactivated</div>
                    </div>
                </div>

                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-input" id="applicantSearch" placeholder="Search by name or email..." oninput="filterApplicantTable()">
                </div>

                <?php if (empty($applicants)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-user-group"></i></div>
                        <div class="empty-title">No applicants yet</div>
                        <div class="empty-sub">Registered applicants will appear here.</div>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table id="applicantTable">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
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
                                    <tr class="ap-row">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:12px;">
                                                <div class="ev-avatar"
                                                    style="<?= $a_has_pic
                                                                ? "background-image:url('" . htmlspecialchars($a_pic_url) . "');background-size:cover;background-position:center;"
                                                                : 'background:linear-gradient(135deg,#059669,#34d399);' ?>"
                                                    <?= $a_has_pic ? 'title="' . htmlspecialchars($full_name !== '' ? $full_name : $a['email'], ENT_QUOTES) . '"' : '' ?>>
                                                    <?= $a_has_pic ? '' : $ainitials ?>
                                                </div>
                                                <div>
                                                    <div class="ev-name"><?= htmlspecialchars($full_name !== '' ? $full_name : '—') ?></div>
                                                    <div class="ev-email"><?= htmlspecialchars($a['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($a['phone'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($is_active): ?>
                                                <span class="badge-active">Active</span>
                                            <?php elseif ($has_pending_request): ?>
                                                <span class="badge-reactivation-pending"><i class="fas fa-hourglass-half"></i> Reactivation Pending</span>
                                            <?php else: ?>
                                                <span class="badge-deactivated" style="cursor:pointer;"
                                                    onclick='showDeactivationReason(<?= json_encode($a['deactivation_reason'] ?: 'No reason recorded.') ?>, <?= json_encode($a['deactivated_at'] ? date('M d, Y g:i a', strtotime($a['deactivated_at'])) : '—') ?>, <?= json_encode($a['deactivated_by'] ?: null) ?>)'>
                                                    <i class="fas fa-ban"></i> Deactivated <i class="fas fa-circle-info" style="font-size:9px;margin-left:2px;"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px;color:var(--text3);">
                                            <div><?= date('M d, Y', strtotime($a['created_at'])) ?></div>
                                            <div style="font-size:10.5px;"><?= date('g:i a', strtotime($a['created_at'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($is_active): ?>
                                                <button type="button" class="btn-action-deactivate"
                                                    onclick="showDeactivateModal('<?= htmlspecialchars($a['email'], ENT_QUOTES) ?>', 'client', '<?= htmlspecialchars($full_name !== '' ? $full_name : $a['email'], ENT_QUOTES) ?>')">
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-action-reactivate"
                                                    onclick="reactivateUser('<?= htmlspecialchars($a['email'], ENT_QUOTES) ?>', 'client')">
                                                    <i class="fas fa-user-check"></i> Reactivate
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="no-applicant-results" style="display:none;padding:40px;text-align:center;color:var(--text3);font-size:13px;">
                            <i class="fas fa-search" style="font-size:24px;margin-bottom:10px;display:block;"></i>
                            No applicants match your search.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Pending Invitations -->
        <div id="tab-pending" class="tab-content">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Pending Invitations</div>
                        <div class="main-card-sub">Invites awaiting acceptance · expires after 24 hours</div>
                    </div>
                </div>

                <?php if (empty($pending_invitations)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-envelope-circle-check"></i></div>
                        <div class="empty-title">All caught up!</div>
                        <div class="empty-sub">No pending invitations at the moment.</div>
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
                        <div class="inv-row">
                            <div class="inv-avatar"><?= $iinit ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;font-size:13.5px;color:var(--text1);"><?= htmlspecialchars($inv['full_name']) ?></div>
                                <div style="font-size:12px;color:var(--text3);"><?= htmlspecialchars($inv['email']) ?></div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <?php if ($expired): ?>
                                    <span class="badge-expired"><i class="fas fa-clock"></i> Expired</span>
                                <?php else: ?>
                                    <span class="badge-pending"><i class="fas fa-hourglass-half"></i> Pending</span>
                                <?php endif; ?>
                                <div style="font-size:10.5px;color:var(--text3);margin-top:4px;">
                                    Sent <?= date('M d, Y', strtotime($inv['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Expired Invitations -->
        <div id="tab-expired" class="tab-content">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Expired Invitations</div>
                        <div class="main-card-sub">These invites were not accepted within 24 hours</div>
                    </div>
                </div>

                <?php if (empty($expired_invitations)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-ban"></i></div>
                        <div class="empty-title">No expired invitations</div>
                        <div class="empty-sub">All invitations are still active or have been accepted.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($expired_invitations as $inv): ?>
                        <?php
                        $iparts = explode(' ', trim($inv['full_name'] ?? 'IV'));
                        $iinit  = strtoupper(count($iparts) >= 2
                            ? substr($iparts[0], 0, 1) . substr(end($iparts), 0, 1)
                            : substr($inv['full_name'], 0, 2));
                        ?>
                        <div class="inv-row" style="opacity:0.75;">
                            <div class="inv-avatar" style="background:#fee2e2;color:var(--red);"><?= $iinit ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;font-size:13.5px;color:var(--text1);"><?= htmlspecialchars($inv['full_name']) ?></div>
                                <div style="font-size:12px;color:var(--text3);"><?= htmlspecialchars($inv['email']) ?></div>
                                <div style="font-size:10.5px;color:var(--red);margin-top:4px;">
                                    Expired <?= date('M d, Y g:i a', strtotime($inv['expires_at'])) ?>
                                </div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <span class="badge-expired"><i class="fas fa-ban"></i> Expired</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Reactivation Requests -->
        <div id="tab-reactivation" class="tab-content">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Reactivation Requests</div>
                        <div class="main-card-sub"><?= $reactivation_requests_count ?> pending request<?= $reactivation_requests_count !== 1 ? 's' : '' ?> awaiting review</div>
                    </div>
                </div>

                <?php if (empty($reactivation_requests)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                        <div class="empty-title">No pending requests</div>
                        <div class="empty-sub">Deactivated users who ask to be reactivated will appear here.</div>
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
                        <div class="inv-row" style="align-items:flex-start;">
                            <div class="inv-avatar"
                                style="<?= $rr_has_pic
                                            ? "background-image:url('" . htmlspecialchars($rr_pic_url) . "');background-size:cover;background-position:center;"
                                            : 'background:linear-gradient(135deg,#4f46e5,#818cf8);' ?>"
                                <?= $rr_has_pic ? 'title="' . htmlspecialchars($display_name !== '' ? $display_name : $rr['email'], ENT_QUOTES) . '"' : '' ?>>
                                <?= $rr_has_pic ? '' : htmlspecialchars($rinit) ?>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;font-size:13.5px;color:var(--text1);">
                                    <?= htmlspecialchars($display_name !== '' ? $display_name : $rr['email']) ?>
                                    <span style="font-weight:600;font-size:10.5px;color:var(--text3);margin-left:6px;">(<?= htmlspecialchars($roleLabel) ?>)</span>
                                </div>
                                <div style="font-size:12px;color:var(--text3);"><?= htmlspecialchars($rr['email']) ?></div>
                                <div style="font-size:12px;color:var(--text2);margin-top:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 10px;">
                                    <i class="fas fa-quote-left" style="font-size:9px;color:var(--text3);margin-right:4px;"></i>
                                    <?= nl2br(htmlspecialchars($rr['reason'])) ?>
                                </div>
                                <div style="font-size:10.5px;color:var(--text3);margin-top:6px;">
                                    Requested <?= date('M d, Y g:i a', strtotime($rr['requested_at'])) ?>
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                                <button type="button" class="btn-action-reactivate"
                                    onclick="approveReactivation(<?= (int)$rr['id'] ?>, '<?= htmlspecialchars($rr['email'], ENT_QUOTES) ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button type="button" class="btn-action-deactivate"
                                    onclick="showRejectReactivationModal(<?= (int)$rr['id'] ?>, '<?= htmlspecialchars($rr['email'], ENT_QUOTES) ?>')">
                                    <i class="fas fa-xmark"></i> Reject
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
    <div id="inviteModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3><i class="fas fa-paper-plane" style="margin-right:8px;opacity:.8;"></i>Send Evaluator Invitation</h3>
                <p>An email will be sent with a secure setup link.</p>
                <button class="modal-close" onclick="hideInviteModal()">×</button>
            </div>
            <form method="POST" id="inviteForm">
                <input type="hidden" name="action" value="invite">
                <div class="modal-body">
                    <div class="modal-field">
                        <label class="modal-label">Email Address</label>
                        <input type="email" name="email" required class="modal-input" placeholder="evaluator@dmw.gov.ph">
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Full Name</label>
                        <input type="text" name="full_name" required class="modal-input" placeholder="Juan Dela Cruz">
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Cellphone Number</label>
                        <input type="tel" name="phone" required class="modal-input" placeholder="09171234567"
                            inputmode="numeric" maxlength="11" minlength="11"
                            pattern="09[0-9]{9}"
                            title="Enter an 11-digit mobile number starting with 09 (e.g., 09171234567)"
                            autocomplete="off"
                            oninput="this.value = this.value.replace(/\D/g, '').slice(0, 11)">
                        <div class="modal-hint"><i class="fas fa-info-circle"></i> Format: 09171234567 (11 digits, numbers only)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="hideInviteModal()">Cancel</button>
                    <button type="submit" class="btn-send">
                        <i class="fas fa-paper-plane"></i> Send Invitation
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── DEACTIVATE ACCOUNT MODAL ── -->
    <div id="deactivateModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header" style="background:linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">
                <h3><i class="fas fa-user-slash" style="margin-right:8px;opacity:.8;"></i>Deactivate Account</h3>
                <p id="deactivateModalSub">This will immediately prevent the user from logging in.</p>
                <button class="modal-close" onclick="hideDeactivateModal()">×</button>
            </div>
            <form method="POST" id="deactivateForm">
                <input type="hidden" name="action" value="deactivate_user">
                <input type="hidden" name="target_email" id="deactivate_target_email">
                <input type="hidden" name="target_usertype" id="deactivate_target_usertype">
                <div class="modal-body">
                    <div class="modal-field">
                        <label class="modal-label">Reason for Deactivation</label>
                        <select name="reason_preset" id="reason_preset" class="modal-input" required onchange="toggleOtherReason()">
                            <option value="">— Select a reason —</option>
                        </select>
                    </div>
                    <div class="modal-field" id="otherReasonField" style="display:none;">
                        <label class="modal-label">Please Specify</label>
                        <input type="text" name="reason_other" id="reason_other" class="modal-input" placeholder="Enter specific reason...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="hideDeactivateModal()">Cancel</button>
                    <button type="submit" class="btn-send" style="background:linear-gradient(135deg, #dc2626, #991b1b);">
                        <i class="fas fa-user-slash"></i> Deactivate Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden form for Reactivate action (submitted via JS after confirmation) -->
    <form method="POST" id="reactivateForm" style="display:none;">
        <input type="hidden" name="action" value="reactivate_user">
        <input type="hidden" name="target_email" id="reactivate_target_email">
        <input type="hidden" name="target_usertype" id="reactivate_target_usertype">
    </form>

    <!-- Hidden form for Approve Reactivation Request (submitted via JS after confirmation) -->
    <form method="POST" id="approveReactivationForm" style="display:none;">
        <input type="hidden" name="action" value="approve_reactivation">
        <input type="hidden" name="request_id" id="approve_request_id">
    </form>

    <!-- ── REJECT REACTIVATION REQUEST MODAL ── -->
    <div id="rejectReactivationModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header" style="background:linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">
                <h3><i class="fas fa-xmark" style="margin-right:8px;opacity:.8;"></i>Reject Reactivation Request</h3>
                <p id="rejectReactivationModalSub">Let the user know why this request is being declined.</p>
                <button class="modal-close" onclick="hideRejectReactivationModal()">×</button>
            </div>
            <form method="POST" id="rejectReactivationForm">
                <input type="hidden" name="action" value="reject_reactivation">
                <input type="hidden" name="request_id" id="reject_request_id">
                <div class="modal-body">
                    <div class="modal-field">
                        <label class="modal-label">Reason for Rejection</label>
                        <textarea name="admin_comment" id="reject_admin_comment" class="modal-input" rows="4" style="resize:vertical;" placeholder="Explain why this reactivation request is being declined..." required></textarea>
                        <div class="modal-hint"><i class="fas fa-info-circle"></i> This message will be sent to the user.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="hideRejectReactivationModal()">Cancel</button>
                    <button type="submit" class="btn-send" style="background:linear-gradient(135deg, #dc2626, #991b1b);">
                        <i class="fas fa-xmark"></i> Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>

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

        function switchTab(name, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + name).classList.add('active');
            btn.classList.add('active');
        }

        function filterTable() {
            const q = document.getElementById('evalSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.ev-row');
            let visible = 0;
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const show = text.includes(q);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('no-results').style.display = visible === 0 ? 'block' : 'none';
        }

        function filterApplicantTable() {
            const q = document.getElementById('applicantSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.ap-row');
            let visible = 0;
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const show = text.includes(q);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('no-applicant-results').style.display = visible === 0 ? 'block' : 'none';
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
            document.getElementById('inviteModal').classList.add('open');
        }

        function hideInviteModal() {
            document.getElementById('inviteModal').classList.remove('open');
        }
        document.getElementById('inviteModal').addEventListener('click', function(e) {
            if (e.target === this) hideInviteModal();
        });

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
            document.getElementById('otherReasonField').style.display = 'none';
            document.getElementById('deactivateModal').classList.add('open');
        }

        function hideDeactivateModal() {
            document.getElementById('deactivateModal').classList.remove('open');
        }
        document.getElementById('deactivateModal').addEventListener('click', function(e) {
            if (e.target === this) hideDeactivateModal();
        });

        function toggleOtherReason() {
            const val = document.getElementById('reason_preset').value;
            document.getElementById('otherReasonField').style.display = (val === 'Other') ? 'block' : 'none';
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
            document.getElementById('rejectReactivationModal').classList.add('open');
        }

        function hideRejectReactivationModal() {
            document.getElementById('rejectReactivationModal').classList.remove('open');
        }
        document.getElementById('rejectReactivationModal').addEventListener('click', function(e) {
            if (e.target === this) hideRejectReactivationModal();
        });

        document.getElementById('rejectReactivationForm').addEventListener('submit', function(e) {
            const comment = document.getElementById('reject_admin_comment').value.trim();
            if (comment.length < 5) {
                e.preventDefault();
                Swal.fire('Missing reason', 'Please provide a reason (at least 5 characters) for the user.', 'warning');
            }
        });

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
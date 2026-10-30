<?php
// includes/mailer.php
// Transport only: builds a PHPMailer from the email_settings table.
// Safe to include from any page (e.g. create-account.php).

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';
require_once __DIR__ . '/../phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

/** Returns the single settings row (password still encrypted) or null. */
function get_email_settings(mysqli $db): ?array
{
    $res = $db->query("SELECT * FROM email_settings WHERE id = 1 LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    return $row ?: null;
}

/** Builds a PHPMailer from the email_settings row. Throws if not configured. */
function create_mailer(mysqli $db): PHPMailer
{
    $s = get_email_settings($db);
    if (!$s) {
        throw new RuntimeException('Email settings have not been configured.');
    }

    $password = decrypt_secret($s['smtp_password_enc']);
    if ($password === null) {
        throw new RuntimeException('Stored email password could not be decrypted.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host     = $s['smtp_host'];
    $mail->Port     = (int) $s['smtp_port'];
    $mail->SMTPAuth = true;
    $mail->Username = $s['smtp_username'];
    $mail->Password = $password;
    $mail->CharSet  = 'UTF-8';
    $mail->Timeout  = 15;

    switch ($s['smtp_encryption']) {
        case 'ssl':
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            break;
        case 'tls':
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            break;
        default:
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($s['from_email'], $s['from_name']);
    return $mail;
}

/** Backward-compatible wrapper (HTML mode on). */
function dmw_mailer(?mysqli $db = null): PHPMailer
{
    if ($db === null) {
        global $database;
        $db = $database;
    }
    $mail = create_mailer($db);
    $mail->isHTML(true);
    return $mail;
}

/**
 * Sends one HTML email using the DB-configured SMTP settings.
 * Never throws: returns true/false and logs failures, so a mail outage
 * cannot break the calling admin action.
 */
function send_html_mail(mysqli $db, string $to, ?string $toName, string $subject, string $html): bool
{
    try {
        $mail = create_mailer($db);
        $mail->addAddress($to, (string) $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log("send_html_mail to {$to} failed: " . $e->getMessage());
        return false;
    }
}

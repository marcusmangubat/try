<?php
// includes/status_email.php
// Builds the data, picks the template, attaches the certificate PDF, sends.
// Public entry point: send_application_status_email($database, $app_id, $decision, $comments)

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/certificate_template.php'; // dmw_render_certificate_pdf()

use PHPMailer\PHPMailer\PHPMailer;

// NOTE: the verification code is display-only (no verify table/endpoint in the
// database yet). Move this secret to config/secret.php before issuing real
// certificates; changing it later invalidates every code already issued.
if (!defined('DMW_EMAIL_VERIFY_SECRET')) {
    define('DMW_EMAIL_VERIFY_SECRET', 'change-this-to-a-long-random-string');
}

/**
 * Single source of truth for the email templates AND client/certificate.php.
 * Returns null if the application or applicant email can't be found.
 */
function dmw_build_status_data(mysqli $database, int $app_id, string $decision, ?string $comments = null): ?array
{
    $stmt = $database->prepare("
        SELECT a.id, a.status, a.submitted_at,
               c.email AS applicant_email, c.first_name, c.middle_name, c.last_name, c.suffix,
               c.barangay, c.city_municipality, c.province,
               r.id AS rsf_id, r.rsf_number, r.position_title, r.employer_name, r.worksite_country, r.classification
        FROM applications a
        JOIN clients c        ON a.client_id = c.cid
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$app || empty($app['applicant_email'])) {
        return null;
    }

    $stmt = $database->prepare("
        SELECT e.evaluation_type, e.decision, e.comments, e.evaluated_at,
               ev.full_name AS evaluator_name, ev.email AS evaluator_email,
               ad.full_name AS admin_name, ad.email AS admin_email
        FROM evaluations e
        LEFT JOIN evaluators ev ON e.evaluator_id = ev.eid
        LEFT JOIN admins ad     ON e.admin_id = ad.aid
        WHERE e.application_id = ? AND e.decision = ?
        ORDER BY e.evaluated_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("is", $app_id, $decision);
    $stmt->execute();
    $eval = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM rsf_required_documents WHERE rsf_id = ? AND is_required = 1");
    $stmt->bind_param("i", $app['rsf_id']);
    $stmt->execute();
    $docsRequired = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM application_documents WHERE application_id = ?");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $docsSubmitted = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $fullName = trim(
        $app['first_name'] . ' ' .
            ($app['middle_name'] ? $app['middle_name'] . ' ' : '') .
            $app['last_name'] .
            ($app['suffix'] ? ' ' . $app['suffix'] : '')
    );
    // Submission year (not today) so ref_no stays stable forever.
    $refNo       = 'APP-' . date('Y', strtotime($app['submitted_at'])) . '-' . str_pad((string) $app_id, 6, '0', STR_PAD_LEFT);
    $evaluatedAt = $eval['evaluated_at'] ?? date('Y-m-d H:i:s');
    $verifyCode  = strtoupper(substr(hash('sha256', $app_id . $evaluatedAt . DMW_EMAIL_VERIFY_SECRET), 0, 16));

    return [
        'app_id'           => $app_id,
        'ref_no'           => $refNo,
        'applicant_name'   => $fullName,
        'applicant_email'  => $app['applicant_email'],
        'address'          => trim($app['barangay'] . ', ' . $app['city_municipality'] . ', ' . $app['province'], ', '),
        'submitted_at'     => $app['submitted_at'],
        'status'           => $app['status'],
        'rsf_number'       => $app['rsf_number'],
        'position_title'   => $app['position_title'],
        'employer_name'    => $app['employer_name'],
        'worksite_country' => $app['worksite_country'],
        'classification'   => $app['classification'],
        'evaluation_type'  => $eval['evaluation_type'] ?? '—',
        'evaluated_at'     => $evaluatedAt,
        'evaluator_name'   => $eval['evaluator_name'] ?? null,
        'evaluator_email'  => $eval['evaluator_email'] ?? null,
        'admin_name'       => $eval['admin_name'] ?? null,
        'admin_email'      => $eval['admin_email'] ?? null,
        'comments'         => $comments !== null ? $comments : ($eval['comments'] ?? ''),
        'docs_required'    => $docsRequired,
        'docs_submitted'   => $docsSubmitted,
        'verify_code'      => $verifyCode,
    ];
}

/**
 * Sends the status email. Failures are logged, never thrown, so a mail
 * outage never blocks the evaluator's status-change flow.
 */
function send_application_status_email(mysqli $database, int $app_id, string $decision, ?string $comments = null): bool
{
    if (!in_array($decision, ['approved', 'disapproved', 'for_compliance'], true)) {
        return false;
    }

    $data = dmw_build_status_data($database, $app_id, $decision, $comments);
    if ($data === null) {
        error_log("DMW mailer: application #$app_id or applicant email not found — email not sent.");
        return false;
    }

    $subject = match ($decision) {
        'approved'       => "DMW AMS  Application Approved ({$data['ref_no']})",
        'disapproved'    => "DMW AMS  Application Disapproved ({$data['ref_no']})",
        'for_compliance' => "DMW AMS  Compliance Required ({$data['ref_no']})",
    };

    $body = match ($decision) {
        'approved'       => dmw_email_body_approved($data),
        'disapproved'    => dmw_email_body_disapproved($data),
        'for_compliance' => dmw_email_body_compliance($data),
    };

    try {
        $mail = dmw_mailer($database);
        $mail->addAddress($data['applicant_email'], $data['applicant_name']);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

        // Certificate PDF for approvals. If generation fails, still send the email.
        if ($decision === 'approved') {
            try {
                $pdf = dmw_render_certificate_pdf($data);
                $mail->addStringAttachment(
                    $pdf->output(),
                    "DMW_Certificate_{$data['ref_no']}.pdf",
                    PHPMailer::ENCODING_BASE64,
                    'application/pdf'
                );
            } catch (\Throwable $pdfError) {
                error_log("DMW mailer: failed to generate certificate PDF for application #{$app_id} — " . $pdfError->getMessage());
            }
        }

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log("DMW mailer: failed to send {$decision} email for application #{$app_id} — " . $e->getMessage());
        return false;
    }
}

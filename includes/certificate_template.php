<?php
// includes/certificate_template.php
// Shared "Official Evaluation Completion" certificate builder.
// Extracted from client/certificate.php so the exact same PDF can be:
//   1. Streamed for download when the applicant clicks "Download Certificate"
//   2. Attached to the approval email in includes/mailer.php
// One template, two consumers — no duplicated HTML/CSS.
// Expects the same $data array shape produced by dmw_build_status_data()
// in includes/mailer.php.

require_once __DIR__ . '/../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Timezone handling — DB timestamps are stored in UTC; certificates show PH time.
// If your DB session already returns Manila time, set DMW_DB_TZ to 'Asia/Manila'.
const DMW_DISPLAY_TZ = 'Asia/Manila';
const DMW_DB_TZ      = 'UTC';

/**
 * Current moment in Philippine time.
 */
function dmw_cert_now(string $format): string
{
    return (new DateTime('now', new DateTimeZone(DMW_DISPLAY_TZ)))->format($format);
}

/**
 * Convert a DB timestamp (stored in DMW_DB_TZ) to Philippine time.
 * Returns an em dash for empty/invalid values.
 */
function dmw_cert_format_db(?string $timestamp, string $format): string
{
    if (empty($timestamp)) {
        return '—';
    }
    try {
        $dt = new DateTime($timestamp, new DateTimeZone(DMW_DB_TZ));
        $dt->setTimezone(new DateTimeZone(DMW_DISPLAY_TZ));
        return $dt->format($format);
    } catch (Exception $e) {
        return '—';
    }
}
/**
 * Render a single label/value row for a certificate info table.
 *
 * @param string $label Row label.
 * @param mixed  $value Row value; null/empty renders as an em dash.
 * @return string HTML table row markup.
 */

function cert_row(string $label, $value): string
{
    $value = $value === null || $value === '' ? '—' : $value;
    return "<tr>
        <td class='lbl'>{$label}</td>
        <td class='val'>" . htmlspecialchars((string) $value) . "</td>
    </tr>";
}

/**
 * Remove placeholder text such as "Not applicable" / "N/A" that
 * can end up inside a name when an applicant picks it as their suffix.
 */
function dmw_clean_person_name(?string $name): string
{
    $name = (string) $name;
    $name = preg_replace('/(?<!\w)(not\s+applicable|n\/a)(?!\w)/i', '', $name);
    $name = preg_replace('/\s+none\s*$/i', '', $name);   // trailing "None" only
    $name = preg_replace('/\s{2,}/', ' ', $name);
    return trim($name, " \t\n\r\0\x0B,");
}

/**
 * Returns the DMW logo as a base64 data URI so Dompdf can embed it
 * without relying on file paths or remote fetching.
 * Falls back to the public DMW logo URL if the local file is missing.
 */
function dmw_cert_logo_src(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $candidates = [
        __DIR__ . '/../pictures/dmw_logo.png',
    ];

    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
            return $cached = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
        }
    }

    // Fallback (works because isRemoteEnabled is true)
    return $cached = 'https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png';
}

/**
 * Builds the full certificate HTML (used for PDF rendering only — this is
 * not meant to be emailed directly as the email body; the email body uses
 * the templates in includes/mailer.php and simply carries this as a PDF
 * attachment).
 */
function dmw_certificate_html(array $data): string
{
    $logoSrc = dmw_cert_logo_src();
    $data['applicant_name'] = dmw_clean_person_name($data['applicant_name'] ?? '');
    $dateIssued     = dmw_cert_now('F d, Y g:i a');
    $evaluatedBy    = $data['evaluator_name'] ?: ($data['admin_name'] ? $data['admin_name'] . ' (Admin)' : '—');
    $decidedByEmail = $data['evaluator_email'] ?: ($data['admin_email'] ?? null);
    $docsComplete   = $data['docs_submitted'] >= $data['docs_required'] ? 'Complete' : 'Incomplete';
    $evaluatorSigName = !empty($data['evaluator_name'])
        ? htmlspecialchars($data['evaluator_name'])
        : '';

    // QR points at a verification concept only — see the note in
    // includes/mailer.php about there being no live /verify endpoint yet.
    $qrPayload = "Ref:{$data['ref_no']} Code:{$data['verify_code']}";
    $qrUrl     = "https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=" . urlencode($qrPayload);

    return "
<html>
<head>
<meta charset='utf-8'>
<style>
    @page { margin: 112px 34px 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
    .header-table { width: 100%; border-bottom: 3px solid #0b1f4b; padding-bottom: 8px; margin-bottom: 10px; }
    .header-table td { vertical-align: middle; }
    .fixed-header { position: fixed; top: -92px; left: 0; right: 0; }
    .agency-name { font-size: 15px; font-weight: bold; color: #0b1f4b; margin: 0; }
    .agency-sub { font-size: 8.5px; color: #4b5563; margin: 2px 0 0; }
    .doc-title { text-align: center; margin: 14px 0 2px; font-size: 15px; font-weight: bold; color: #0b1f4b; text-transform: uppercase; }
    .doc-subtitle { text-align: center; font-size: 10px; color: #6b7280; margin-bottom: 14px; }
    .ref-table { width: 100%; margin-bottom: 10px; font-size: 10px; }
    .ref-table td { padding: 2px 0; }
    .ref-label { font-weight: bold; width: 140px; }
    .intro { font-size: 10.5px; line-height: 1.6; margin-bottom: 14px; text-align: justify; }
    .section-title { background: #0b1f4b; color: #fff; font-size: 10.5px; font-weight: bold; padding: 5px 10px; margin: 14px 0 0; text-transform: uppercase; letter-spacing: .03em; }
    table.info { width: 100%; border-collapse: collapse; border: 1px solid #d1d5db; }
    table.info .lbl { width: 38%; background: #f8fafc; font-weight: bold; padding: 6px 10px; border: 1px solid #e5e7eb; font-size: 9.5px; }
    table.info .val { padding: 6px 10px; border: 1px solid #e5e7eb; font-size: 10px; }
    .cert-body { font-size: 10px; line-height: 1.65; margin: 14px 0; text-align: justify; }
    .steps { font-size: 9.8px; line-height: 1.6; margin: 6px 0 0 16px; padding: 0; }
    .steps li { margin-bottom: 4px; }
    .footer-note { font-size: 8.3px; color: #6b7280; line-height: 1.5; margin-top: 14px; font-style: italic; }
    .sign-table { width: 100%; margin-top: 34px; font-size: 9.5px; }
    .sign-table td { width: 50%; text-align: center; vertical-align: top; }
    .sign-line { border-top: 1px solid #1f2937; margin: 0 20px 4px; padding-top: 4px; }
    .sig-name { font-weight: bold; font-size: 10.5px; color: #0b1f4b; text-transform: uppercase; margin: 0 20px 6px; height: 22px; line-height: 22px; text-align: center; }
    .verify-box { margin-top: 20px; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 14px; }
    .verify-table td { vertical-align: middle; }
    .verify-code { font-weight: bold; font-size: 11px; color: #0b1f4b; }
    .official { position: fixed; top: 260px; left: 90px; font-size: 60px; color: rgba(5,150,105,0.08); transform: rotate(-30deg); font-weight: bold; }
    .page-footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 4px; }
</style>
</head>
<body>

<div class='official'>OFFICIAL RECORD</div>

<div class='fixed-header'>
<table class='header-table'>
    <tr>
        <td style='width:52px;'>
            <img src='{$logoSrc}' width='48'>
        </td>
       <td>
            <p style='font-size:8px;color:#6b7280;margin:0;'>Republic of the Philippines</p>
            <p class='agency-name'>DEPARTMENT OF MIGRANT WORKERS</p>
            <p class='agency-sub'>
                Regional Office IV-A (CALABARZON)<br>
                Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
            </p>
        </td>
    </tr>
</table>
</div>

<div class='doc-title'>Official Evaluation Completion</div>
<div class='doc-subtitle'>Certificate of Record — Overseas Employment Application Evaluation Process</div>

<table class='ref-table'>
    <tr><td class='ref-label'>Reference No.:</td><td>{$data['ref_no']}</td></tr>
    <tr><td class='ref-label'>Date Issued:</td><td>{$dateIssued}</td></tr>
</table>

<div class='intro'>
    This is to formally certify that <strong>" . htmlspecialchars($data['applicant_name']) . "</strong> has undergone the
    official evaluation process of the Department of Migrant Workers (DMW) in connection with the overseas employment
    application filed and processed through the DMW Application Management System, as detailed below.
</div>

<div class='section-title'>I. Applicant Information</div>
<table class='info'>
    " . cert_row('Full Name', $data['applicant_name']) . "
    " . cert_row('Registered Email Address', $data['applicant_email']) . "
    " . cert_row('Address', $data['address']) . "
    " . cert_row('Application Reference No.', $data['ref_no']) . "
    " . cert_row('Date Application Submitted', dmw_cert_format_db($data['submitted_at'], 'F d, Y')) . "
    " . cert_row('Current Application Status', ucwords(str_replace('_', ' ', $data['status']))) . "
</table>

<div class='section-title'>II. Job Order / Recruitment Details</div>
<table class='info'>
    " . cert_row('RSF Number', $data['rsf_number']) . "
    " . cert_row('Position Applied For', $data['position_title']) . "
    " . cert_row('Employer / Company', $data['employer_name']) . "
    " . cert_row('Worksite Country', $data['worksite_country']) . "
    " . cert_row('Classification', $data['classification']) . "
</table>

<div class='section-title'>III. Evaluation Details</div>
<table class='info'>
    " . cert_row('Evaluation Type', ucfirst($data['evaluation_type'])) . "
    " . cert_row('Date &amp; Time of Evaluation', dmw_cert_format_db($data['evaluated_at'], 'F d, Y g:i a')) . "
    " . cert_row('Evaluated By', $evaluatedBy) . "
    " . cert_row('Contact Email (for verification)', $decidedByEmail ?: '—') . "
    " . cert_row('Evaluation Result', 'Approved') . "
    " . cert_row('Evaluator / Admin Remarks', $data['comments'] ?: 'None') . "
</table>

<div class='section-title'>IV. Document Compliance Summary</div>
<table class='info'>
    " . cert_row('Required Documents', $data['docs_required']) . "
    " . cert_row('Documents Submitted', $data['docs_submitted']) . "
    " . cert_row('Compliance Status', $docsComplete) . "
</table>

<div class='section-title'>V. Certification</div>
<div class='cert-body'>
    This document serves as <strong>official proof and evidence</strong> that the above-named applicant has undergone
    the evaluation process administered by the Department of Migrant Workers through its Application Management
    System, in accordance with existing recruitment and deployment regulations. This record reflects the evaluation
    decision, evaluator information, and processing timeline as captured by the system at the time of evaluation.
    <br><br>
    This notification is issued for the applicant's official record and may be presented, as necessary, to relevant
    parties for verification of the applicant's evaluation status within the DMW recruitment process. This does
    <strong>not</strong> constitute an Overseas Employment Certificate (OEC) or a certificate of deployment clearance.
</div>

<div class='section-title'>VI. Next Steps for Approved Applicants</div>
<ol class='steps'>
    <li><strong>Submit Hardcopy Documents</strong> — Visit the designated DMW office within fifteen (15) working days from the date of this notice to submit the required hardcopy documents for verification and further processing.</li>
    <li><strong>Prepare the Required Documents</strong> — Bring the original copies and/or photocopies of the documents indicated in Section IV above.</li>
    <li><strong>Present Your Application Reference Number</strong> — Present {$data['ref_no']} and this Official Evaluation Completion document to the authorized DMW personnel for verification.</li>
    <li><strong>Await Further Instructions</strong> — Once your hardcopy documents have been submitted and verified, follow the instructions provided by the authorized DMW personnel regarding the next stage of your application.</li>
</ol>

<div class='footer-note'>
    This notification does not, by itself, serve as an Overseas Employment Certificate (OEC) or a certificate of
    deployment clearance. Please retain this document, along with your other application records, for your personal
    file. This notification reflects the application status as of the date and time this document was generated.
</div>

<table class='sign-table'>
    <tr>
        <td>
            <div class='sig-name'>" . ($evaluatorSigName !== '' ? $evaluatorSigName : '&nbsp;') . "</div>
            <div class='sign-line'>Evaluator, DMW Application Management System<br>Recruitment and Evaluation Division</div>
        </td>
        <td>
            <div class='sig-name'>ATTY. APRIL R. CASABUENA</div>
            <div class='sign-line'>Regional Director<br>Department of Migrant Workers, Regional Office IV-A (CALABARZON)</div>
        </td>
    </tr>
</table> 

<div class='verify-box'>
    <table class='verify-table'><tr>
        <td style='width:80px;'><img src='{$qrUrl}' width='70'></td>
        <td>
            <strong style='font-size:9.5px;'>Document Verification</strong><br>
            <span style='font-size:8.5px;color:#6b7280;'>Reference Code:</span> <span class='verify-code'>{$data['verify_code']}</span><br>
            <span style='font-size:8px;color:#9ca3af;'>Quote this reference code when contacting DMW to confirm the authenticity of this document.</span>
        </td>
    </tr></table>
</div>

<div class='page-footer'>
    Department of Migrant Workers — Application Management System | This document is system-generated and does not require a wet signature. | Reference: {$data['ref_no']}
</div>

</body>
</html>
";
}

/**
 * Renders the certificate HTML into a fully-rendered Dompdf instance.
 * Caller decides what to do with it:
 *   - client/certificate.php calls ->stream() to force a browser download
 *   - includes/mailer.php calls ->output() to get raw PDF bytes for
 *     attaching to the approval email
 */
function dmw_render_certificate_pdf(array $data): Dompdf
{
    $options = new Options();
    $options->set('isRemoteEnabled', true); // needed to fetch the logo + QR images

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(dmw_certificate_html($data));
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf;
}

<?php
// includes/mail_templates.php
// Email contents only (HTML). No database access, no sending.
// Each body function receives the array built by dmw_build_status_data().

if (!defined('DMW_LOGIN_URL')) {
    define('DMW_LOGIN_URL', 'http://localhost/try/login.php');
}

/** Shared HTML shell (header band + card + footer). */
function dmw_email_shell(string $accentColor, string $headline, string $subheadline, string $innerHtml): string
{
    return "
    <div style='font-family:\"DM Sans\", Arial, sans-serif; max-width:640px; margin:0 auto; color:#1f2937;'>
        <div style='background:{$accentColor}; padding:24px 28px; border-radius:12px 12px 0 0;'>
            <p style='margin:0; color:#e5e7eb; font-size:10.5px; letter-spacing:.08em; text-transform:uppercase;'>
                Department of Migrant Workers &middot; Application Management System
            </p>
            <h1 style='margin:6px 0 2px; color:#ffffff; font-size:20px;'>{$headline}</h1>
            <p style='margin:0; color:#f1f5f9; font-size:13px;'>{$subheadline}</p>
        </div>
        <div style='background:#ffffff; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 12px 12px; padding:24px 28px;'>
            {$innerHtml}
            <p style='margin-top:24px; font-size:11px; color:#9ca3af; border-top:1px solid #e5e7eb; padding-top:12px;'>
                This is a system-generated message from the DMW Application Management System. Please do not reply directly to this email.
                For concerns, contact the Regional Office IV-A recruitment and evaluation division.
            </p>
        </div>
    </div>";
}

/** Label/value table. $rows is [ 'Label' => 'Value', ... ] */
function dmw_email_info_table(array $rows): string
{
    $out = "<table style='width:100%; border-collapse:collapse; margin:14px 0;'>";
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $out .= "<tr>
            <td style='padding:7px 10px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; width:40%; vertical-align:top; border-bottom:1px solid #f1f5f9;'>{$label}</td>
            <td style='padding:7px 10px; font-size:13px; color:#111827; vertical-align:top; border-bottom:1px solid #f1f5f9;'>" . htmlspecialchars((string) $value) . "</td>
        </tr>";
    }
    $out .= "</table>";
    return $out;
}

/** Call-to-action button linking to the client portal login page. */
function dmw_email_login_link_block(string $accentColor, string $label = 'Log In to Check Your Application'): string
{
    $url = htmlspecialchars(DMW_LOGIN_URL);
    return "
        <div style='text-align:center; margin:22px 0;'>
            <a href='{$url}' target='_blank' rel='noopener'
               style='display:inline-block; background:{$accentColor}; color:#ffffff; text-decoration:none;
                      font-size:13px; font-weight:700; padding:11px 28px; border-radius:8px; letter-spacing:.02em;'>
                {$label}
            </a>
            <p style='margin:10px 0 0; font-size:10.5px; color:#9ca3af; word-break:break-all;'>
                Or copy this link: <a href='{$url}' style='color:#6b7280;'>{$url}</a>
            </p>
        </div>";
}

/** APPROVED email (certificate PDF is attached by status_email.php). */
function dmw_email_body_approved(array $d): string
{
    $evaluatedBy = $d['evaluator_name'] ?: ($d['admin_name'] ? $d['admin_name'] . ' (Admin)' : '—');

    $inner = "
        <p style='font-size:13.5px; line-height:1.6;'>
            Dear <strong>" . htmlspecialchars($d['applicant_name']) . "</strong>,<br><br>
            This is to formally certify that you have undergone the official evaluation process of the Department of
            Migrant Workers (DMW) for the overseas employment application detailed below, and your application has
            been <strong style='color:#059669;'>APPROVED</strong>.
        </p>

        <div style='background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:12px 16px; margin:16px 0; font-size:12.5px; color:#065f46;'>
            📄 Your official <strong>Evaluation Completion Certificate</strong> is attached to this email as a PDF
            (<strong>DMW_Certificate_{$d['ref_no']}.pdf</strong>). Most email apps let you preview it directly here —
            you can also download or print it from the attachment.
        </div>

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #0b1f4b; padding-bottom:4px;'>I. Applicant Information</h3>"
        . dmw_email_info_table([
            'Full Name'                 => $d['applicant_name'],
            'Registered Email'          => $d['applicant_email'],
            'Address'                   => $d['address'],
            'Application Reference No.' => $d['ref_no'],
            'Date Submitted'            => date('F d, Y g:i a', strtotime($d['submitted_at'])),
            'Current Status'            => ucwords(str_replace('_', ' ', $d['status'])),
        ]) . "

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #0b1f4b; padding-bottom:4px;'>II. Job Order / Recruitment Details</h3>"
        . dmw_email_info_table([
            'RSF Number'           => $d['rsf_number'],
            'Position Applied For' => $d['position_title'],
            'Employer / Company'   => $d['employer_name'],
            'Worksite Country'     => $d['worksite_country'],
            'Classification'       => $d['classification'],
        ]) . "

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #0b1f4b; padding-bottom:4px;'>III. Evaluation Details</h3>"
        . dmw_email_info_table([
            'Evaluation Type'               => ucfirst($d['evaluation_type']),
            'Date &amp; Time of Evaluation' => date('F d, Y g:i a', strtotime($d['evaluated_at'])),
            'Evaluated / Approved By'       => $evaluatedBy,
            'Evaluation Result'             => 'Approved',
            'Remarks'                       => $d['comments'],
        ]) . "

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #0b1f4b; padding-bottom:4px;'>IV. Document Compliance Summary</h3>"
        . dmw_email_info_table([
            'Documents Required'  => $d['docs_required'],
            'Documents Submitted' => $d['docs_submitted'],
            'Compliance Status'   => $d['docs_submitted'] >= $d['docs_required'] ? 'Complete' : 'Incomplete',
        ]) . "

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 8px; border-bottom:2px solid #0b1f4b; padding-bottom:4px;'>V. Next Steps</h3>
        <ol style='font-size:12.5px; line-height:1.7; color:#374151; padding-left:18px; margin:0;'>
            <li><strong>Submit Hardcopy Documents</strong> — Visit the designated DMW office within fifteen (15) working days from the date of this notice to submit the required hardcopy documents for verification.</li>
            <li><strong>Prepare the Required Documents</strong> — Bring original copies and/or photocopies of the documents listed in Section IV above.</li>
            <li><strong>Present Your Reference Number</strong> — Present <strong>{$d['ref_no']}</strong> and this email to the authorized DMW personnel for verification.</li>
            <li><strong>Await Further Instructions</strong> — Once your hardcopy documents are verified, DMW personnel will advise you on the next stage (contract processing, orientation, or deployment requirements).</li>
        </ol>
    " . dmw_email_login_link_block('#059669', 'Log In to View Your Certificate & Status') . "
        <p style='font-size:11px; color:#6b7280; margin-top:16px; line-height:1.6;'>
            This notice does not, by itself, serve as an Overseas Employment Certificate (OEC) or a certificate of
            deployment clearance. Please retain this email and the attached certificate, along with your other
            application records, for your personal file. Your reference / verification code: <strong>{$d['verify_code']}</strong>.
        </p>
    ";

    return dmw_email_shell('linear-gradient(135deg,#0b1f4b,#059669)', 'Application Approved', 'Official Evaluation Completion Notice', $inner);
}

/** DISAPPROVED email. */
function dmw_email_body_disapproved(array $d): string
{
    $decidedBy = $d['evaluator_name'] ?: ($d['admin_name'] ? $d['admin_name'] . ' (Admin)' : '—');

    $inner = "
        <p style='font-size:13.5px; line-height:1.6;'>
            Dear <strong>" . htmlspecialchars($d['applicant_name']) . "</strong>,<br><br>
            After evaluation by the Department of Migrant Workers (DMW), your application below has been
            <strong style='color:#dc2626;'>DISAPPROVED</strong>.
        </p>

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #dc2626; padding-bottom:4px;'>Application Details</h3>"
        . dmw_email_info_table([
            'Application Reference No.' => $d['ref_no'],
            'Position Applied For'      => $d['position_title'],
            'Employer / Company'        => $d['employer_name'],
            'Worksite Country'          => $d['worksite_country'],
            'RSF Number'                => $d['rsf_number'],
            'Date Submitted'            => date('F d, Y g:i a', strtotime($d['submitted_at'])),
            'Date of Decision'          => date('F d, Y g:i a', strtotime($d['evaluated_at'])),
            'Decided By'                => $decidedBy,
        ]) . "

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #dc2626; padding-bottom:4px;'>Evaluator Remarks</h3>
        <p style='font-size:13px; color:#374151; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; line-height:1.6;'>
            " . ($d['comments'] !== '' ? nl2br(htmlspecialchars($d['comments'])) : 'No additional remarks were provided.') . "
        </p>

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 8px; border-bottom:2px solid #dc2626; padding-bottom:4px;'>What You Can Do</h3>
        <ol style='font-size:12.5px; line-height:1.7; color:#374151; padding-left:18px; margin:0;'>
            <li>Review the evaluator remarks above to understand the reason for disapproval.</li>
            <li>Browse other open job orders on the DMW portal that may better match your profile.</li>
            <li>If you believe this decision was made in error, contact the Regional Office IV-A recruitment and evaluation division, citing reference <strong>{$d['ref_no']}</strong>.</li>
        </ol>
    " . dmw_email_login_link_block('#dc2626', 'Log In to Browse Other Job Orders') . "
        <p style='font-size:11px; color:#6b7280; margin-top:16px;'>Reference code: <strong>{$d['verify_code']}</strong></p>
    ";

    return dmw_email_shell('linear-gradient(135deg,#0b1f4b,#dc2626)', 'Application Disapproved', 'Official Evaluation Decision Notice', $inner);
}

/** FOR COMPLIANCE email. */
function dmw_email_body_compliance(array $d): string
{
    $flaggedBy = $d['evaluator_name'] ?: ($d['admin_name'] ? $d['admin_name'] . ' (Admin)' : '—');

    $inner = "
        <p style='font-size:13.5px; line-height:1.6;'>
            Dear <strong>" . htmlspecialchars($d['applicant_name']) . "</strong>,<br><br>
            Your application below requires additional action before it can proceed. Its status has been set to
            <strong style='color:#b45309;'>FOR COMPLIANCE</strong>.
        </p>

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #d97706; padding-bottom:4px;'>Application Details</h3>"
        . dmw_email_info_table([
            'Application Reference No.' => $d['ref_no'],
            'Position Applied For'      => $d['position_title'],
            'Employer / Company'        => $d['employer_name'],
            'RSF Number'                => $d['rsf_number'],
            'Flagged By'                => $flaggedBy,
            'Date Flagged'              => date('F d, Y g:i a', strtotime($d['evaluated_at'])),
            'Documents Submitted'       => $d['docs_submitted'] . ' of ' . $d['docs_required'] . ' required',
        ]) . "

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 4px; border-bottom:2px solid #d97706; padding-bottom:4px;'>What Needs to Be Corrected</h3>
        <p style='font-size:13px; color:#374151; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; line-height:1.6;'>
            " . ($d['comments'] !== '' ? nl2br(htmlspecialchars($d['comments'])) : 'Please log in to review the specific document comments left on your application.') . "
        </p>

        <h3 style='font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0b1f4b; margin:20px 0 8px; border-bottom:2px solid #d97706; padding-bottom:4px;'>Next Steps</h3>
        <ol style='font-size:12.5px; line-height:1.7; color:#374151; padding-left:18px; margin:0;'>
            <li>Log in to the DMW Application Management System client portal.</li>
            <li>Open this application and review the evaluator/document comments noted above.</li>
            <li>Correct or re-upload the affected documents, or answer any missing information.</li>
            <li>Resubmit your application for continued evaluation.</li>
        </ol>
    " . dmw_email_login_link_block('#d97706', 'Log In to Fix & Resubmit Your Application') . "
        <p style='font-size:11px; color:#6b7280; margin-top:16px;'>Reference code: <strong>{$d['verify_code']}</strong></p>
    ";

    return dmw_email_shell('linear-gradient(135deg,#0b1f4b,#d97706)', 'Compliance Required', 'Action Needed on Your Application', $inner);
}

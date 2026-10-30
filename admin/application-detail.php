<?php
//application-detail.php
session_start();
require_once '../db.php'; // gives us $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper((substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1)));

function statusLabel(string $status): string
{
    $labels = [
        'pending'          => 'Pending',
        'under_evaluation' => 'Under Evaluation',
        'for_compliance'   => 'For Compliance',
        'resubmitted'      => 'Resubmitted',
        'approved'         => 'Approved',
        'disapproved'      => 'Disapproved',
        'assign'           => 'Assigned',
        'unassign'         => 'Unassigned',
        'assigned'         => 'Assigned',
        'unassigned'       => 'Unassigned',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function badgeClass(?string $status): string
{
    switch ($status) {
        case 'approved':
            return 'b-approved';
        case 'disapproved':
            return 'b-disapproved';
        case 'pending':
            return 'b-pending';
        default:
            return 'b-other';
    }
}

function fmtDate(?string $dt, string $format = 'M j, Y g:i A'): string
{
    return $dt ? date($format, strtotime($dt)) : '—';
}

/**
 * Returns a display-safe fallback for null/empty values.
 *
 * @param mixed  $v        Value to check (string, int, float, or null from any DB column).
 * @param string $fallback Text to show when $v is null or an empty string.
 * @return mixed
 */

function nz($v, string $fallback = '—')
{
    return ($v === null || $v === '') ? $fallback : $v;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid application ID.');
}

/* MAIN APPLICATION + APPLICANT + JOB ORDER RECORD */

$stmt = $database->prepare("
    SELECT
        a.id AS application_id, a.status, a.handling_party, a.current_evaluator_id,
        a.locked_at, a.submitted_at, a.updated_at, a.notes AS admin_notes,
        c.cid AS client_id, c.email AS applicant_email, c.first_name, c.middle_name, c.last_name, c.suffix,
        c.phone, c.address, c.barangay, c.city_municipality, c.province, c.dob, c.gender, c.profile_picture,
        j.id AS job_order_id, j.rsf_number, j.employer_name, j.worksite_country, j.classification,
        j.position_title, j.slots_available, j.qualifications, j.salary_min, j.salary_max, j.salary_currency,
        j.deadline AS job_deadline,
        jc.category_name, jsub.subcategory_name,
        ev.eid AS evaluator_id, ev.full_name AS evaluator_name, ev.email AS evaluator_email,
        cp.citizenship, cp.civil_status, cp.religion, cp.height_cm, cp.weight_kg, cp.educational_attainment,
        cp.course_degree, cp.school_name, cp.school_address, cp.year_graduated, cp.work_experience_years,
        cp.last_employer, cp.last_position, cp.profile_completed_at,
        dp.degree_name
    FROM applications a
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    LEFT JOIN job_subcategories jsub ON jsub.id = j.subcategory_id
    LEFT JOIN job_categories jc ON jc.id = jsub.category_id
    LEFT JOIN evaluators ev ON ev.eid = a.current_evaluator_id
    LEFT JOIN client_profiles cp ON cp.client_id = c.cid
    LEFT JOIN degree_programs dp ON dp.id = cp.degree_id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Default values so Intelephense (and PHP) never see these as undefined —
// the HTML below is gated by $notFound, but the two conditionals are
// separate blocks so static analysis can't prove they always agree.
$applicantName      = '';
$age                = null;
$preferredCountries = [];
$preferredPositions = [];
$clientSkills       = [];
$clientLicenses     = [];
$answers            = [];
$documents          = [];
$requiredCount      = 0;
$submittedCount     = 0;
$missingDocs        = [];
$completionPct      = 100;
$evaluations        = [];
$statusHistory      = [];
$assignments        = [];
$daysInProcess      = 0;
$evaluatorNames     = [];
$evaluatorCount     = 0;
$statusChangeCount  = 0;
$complianceRounds   = 0;
$isPastDeadline     = false;
$isClosed           = false;

if (!$app) {
    $notFound = true;
} else {
    $notFound = false;
    $applicantName = trim(implode(' ', array_filter([$app['first_name'], $app['middle_name'], $app['last_name'], $app['suffix']])));
    $age = $app['dob'] ? floor((strtotime('today') - strtotime($app['dob'])) / 31556926) : null;

    /*  Preferred countries / positions (full list, not just top 1)  */
    $preferredCountries = [];
    $s = $database->prepare("SELECT country, priority_order FROM client_preferred_countries WHERE client_id = ? ORDER BY priority_order ASC");
    $s->bind_param('i', $app['client_id']);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $preferredCountries[] = $row;
    $s->close();

    $preferredPositions = [];
    $s = $database->prepare("
        SELECT jsub.subcategory_name, pp.priority_order
        FROM client_preferred_positions pp
        JOIN job_subcategories jsub ON jsub.id = pp.subcategory_id
        WHERE pp.client_id = ? ORDER BY pp.priority_order ASC
    ");
    $s->bind_param('i', $app['client_id']);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $preferredPositions[] = $row;
    $s->close();

    /*  Skills & licenses  */
    $clientSkills = [];
    $s = $database->prepare("
        SELECT sk.skill_name, cs.proficiency_level, cs.years_experience
        FROM client_skills cs JOIN skills sk ON sk.id = cs.skill_id
        WHERE cs.client_id = ? ORDER BY sk.skill_name ASC
    ");
    $s->bind_param('i', $app['client_id']);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $clientSkills[] = $row;
    $s->close();

    $clientLicenses = [];
    $s = $database->prepare("
        SELECT pl.license_name, pl.issuing_body
        FROM client_licenses cl JOIN professional_licenses pl ON pl.id = cl.license_id
        WHERE cl.client_id = ? ORDER BY pl.license_name ASC
    ");
    $s->bind_param('i', $app['client_id']);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $clientLicenses[] = $row;
    $s->close();

    /*  Application answers  */
    $answers = [];
    $s = $database->prepare("
        SELECT q.question_text, aa.answer, aa.answered_at
        FROM application_answers aa JOIN questions q ON q.id = aa.question_id
        WHERE aa.application_id = ? ORDER BY q.order_no ASC, q.id ASC
    ");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $answers[] = $row;
    $s->close();

    /*  Documents + comments  */
    $documents = [];
    $s = $database->prepare("
        SELECT ad.id, ad.document_type, ad.file_name, ad.file_path, ad.uploaded_at, rd.document_name, rd.is_required
        FROM application_documents ad
        LEFT JOIN rsf_required_documents rd ON rd.id = ad.required_doc_id
        WHERE ad.application_id = ? ORDER BY ad.uploaded_at ASC
    ");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $documents[] = $row;
    $s->close();

    foreach ($documents as &$doc) {
        $doc['comments'] = [];
        $s = $database->prepare("SELECT commented_by, comment, created_at FROM document_comments WHERE document_id = ? ORDER BY created_at ASC");
        $s->bind_param('i', $doc['id']);
        $s->execute();
        $r = $s->get_result();
        while ($row = $r->fetch_assoc()) $doc['comments'][] = $row;
        $s->close();
    }
    unset($doc);

    /*  Document compliance (required vs submitted, same logic style as report.php)  */
    $s = $database->prepare("SELECT COUNT(*) AS cnt FROM rsf_required_documents WHERE rsf_id = ? AND is_required = 1");
    $s->bind_param('i', $app['job_order_id']);
    $s->execute();
    $requiredCount = (int) ($s->get_result()->fetch_assoc()['cnt'] ?? 0);
    $s->close();

    $s = $database->prepare("
        SELECT COUNT(DISTINCT rd2.document_type) AS cnt
        FROM rsf_required_documents rd2
        JOIN application_documents ad ON ad.application_id = ? AND ad.document_type = rd2.document_type
        WHERE rd2.rsf_id = ? AND rd2.is_required = 1
    ");
    $s->bind_param('ii', $id, $app['job_order_id']);
    $s->execute();
    $submittedCount = (int) ($s->get_result()->fetch_assoc()['cnt'] ?? 0);
    $s->close();

    $missingDocs = [];
    $s = $database->prepare("
        SELECT rd.document_name FROM rsf_required_documents rd
        WHERE rd.rsf_id = ? AND rd.is_required = 1
          AND rd.document_type NOT IN (SELECT ad.document_type FROM application_documents ad WHERE ad.application_id = ?)
    ");
    $s->bind_param('ii', $app['job_order_id'], $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $missingDocs[] = $row['document_name'];
    $s->close();

    $completionPct = $requiredCount > 0 ? round($submittedCount / $requiredCount * 100) : 100;

    /*  Evaluation history  */
    $evaluations = [];
    $s = $database->prepare("
        SELECT e.id, e.evaluation_type, e.decision, e.comments, e.evaluated_at,
               ev.full_name AS evaluator_name, ad.full_name AS admin_name
        FROM evaluations e
        LEFT JOIN evaluators ev ON ev.eid = e.evaluator_id
        LEFT JOIN admins ad ON ad.aid = e.admin_id
        WHERE e.application_id = ? ORDER BY e.evaluated_at ASC
    ");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $evaluations[] = $row;
    $s->close();

    /*  Status history  */
    $statusHistory = [];
    $s = $database->prepare("SELECT from_status, to_status, changed_by, changed_at, reason FROM status_history WHERE application_id = ? ORDER BY changed_at ASC");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $statusHistory[] = $row;
    $s->close();

    /*  Evaluator assignments  */
    $assignments = [];
    $s = $database->prepare("
        SELECT asg.id, asg.assigned_at, asg.status, ev.full_name AS evaluator_name, ev.email AS evaluator_email
        FROM assignments asg JOIN evaluators ev ON ev.eid = asg.evaluator_id
        WHERE asg.application_id = ? ORDER BY asg.assigned_at ASC
    ");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $assignments[] = $row;
    $s->close();

    /*  Derived case-summary metrics (computed only from data already
       fetched above — no new queries, no invented figures) — these power
       the "Case Summary" panel and the narrative line so the admin gets
       an at-a-glance read of the case without hunting through every
       section.  */
    $isClosed        = in_array($app['status'], ['approved', 'disapproved'], true);
    $summaryEndPoint = $isClosed ? $app['updated_at'] : date('Y-m-d H:i:s');
    $daysInProcess   = max(0, (int) floor((strtotime($summaryEndPoint) - strtotime($app['submitted_at'])) / 86400));

    $evaluatorNames    = array_values(array_unique(array_filter(array_column($assignments, 'evaluator_name'))));
    $evaluatorCount    = count($evaluatorNames);
    $statusChangeCount = count($statusHistory);
    $complianceRounds  = count(array_filter($statusHistory, fn($h) => ($h['to_status'] ?? '') === 'for_compliance'));
    $isPastDeadline    = $app['job_deadline'] && strtotime($app['job_deadline']) < time() && !$isClosed;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Application #<?= (int) $id ?> – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }
    </style>
    <style>
        /* DESIGN SYSTEM — aligned with includes/certificate_template.php
           (navy header, section bars, label/value info tables) so every
           official DMW document shares one visual language.*/
        :root {
            --navy: #0b1f4b;
            --navy-soft: #13295e;
            --ok: #059669;
            --amber: #b45309;
            --red: #b91c1c;
            --text1: #1f2937;
            --text2: #4b5563;
            --text3: #9ca3af;
            --bdr: #e5e7eb;
            --lbl-bg: #f8fafc;
            --surface: #ffffff;
            --surface2: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #eef1f6;
            color: var(--text1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid var(--bdr);
            background: var(--surface);
            color: var(--text1);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover {
            background: var(--surface2);
        }

        .btn-primary {
            background: var(--navy);
            border-color: var(--navy);
            color: #fff;
        }

        .sheet {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 16px 110px;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        /*  Document sheet (mirrors certificate's body/header treatment)  */
        .doc-sheet {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 30px 34px 36px;
        }

        .doc-header-table {
            width: 100%;
            border-bottom: 3px solid var(--navy);
            padding-bottom: 10px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .doc-header-table img {
            width: 48px;
            height: auto;
            flex-shrink: 0;
        }

        .republic-line {
            font-size: 10.5px;
            color: var(--text2);
            margin: 0;
        }

        .agency-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--navy);
            margin: 1px 0 2px;
        }

        .agency-sub {
            font-size: 10.5px;
            color: var(--text2);
            line-height: 1.5;
            margin: 0;
        }

        .doc-title {
            text-align: center;
            margin: 18px 0 3px;
            font-size: 17px;
            font-weight: 800;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .doc-subtitle {
            text-align: center;
            font-size: 12px;
            color: var(--text2);
            margin-bottom: 16px;
        }

        table.ref-table {
            width: 100%;
            margin-bottom: 20px;
            font-size: 12px;
            border-collapse: collapse;
        }

        table.ref-table td {
            padding: 3px 0;
            vertical-align: middle;
        }

        table.ref-table td.ref-label {
            font-weight: 700;
            width: 160px;
            color: var(--text1);
        }

        .intro-note {
            font-size: 12.5px;
            line-height: 1.65;
            color: var(--text2);
            text-align: justify;
            margin: 0 0 18px;
        }

        /*  Case Summary panel: quick-read metrics for the reviewing admin,
           derived entirely from data already on the page.  */
        .summary-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0;
            border: 1px solid var(--bdr);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 18px;
        }

        .summary-cell {
            padding: 12px 14px;
            border-right: 1px solid var(--bdr);
            border-bottom: 1px solid var(--bdr);
            background: var(--surface2);
        }

        .summary-cell:last-child {
            border-right: none;
        }

        .summary-cell .sc-num {
            font-size: 20px;
            font-weight: 800;
            color: var(--navy);
            line-height: 1.1;
        }

        .summary-cell .sc-lbl {
            font-size: 10px;
            font-weight: 600;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: .03em;
            margin-top: 3px;
        }

        .summary-cell.sc-warn .sc-num {
            color: var(--red);
        }

        .alert-note {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
            font-size: 12px;
            font-weight: 600;
            padding: 9px 12px;
            border-radius: 6px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-hint {
            font-size: 11.5px;
            color: var(--text3);
            font-style: italic;
            margin: -4px 0 10px;
        }

        /* ── Section title bar ── */
        .section {
            margin-bottom: 22px;
        }

        .section-title {
            background: var(--navy);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 12px;
            margin: 0 0 10px;
            text-transform: uppercase;
            letter-spacing: .04em;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 3px;
        }

        /*  Label / value info table (mirrors certificate table.info)  */
        table.info-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--bdr);
            margin-bottom: 4px;
        }

        table.info-table td {
            border: 1px solid var(--bdr);
            padding: 7px 12px;
            vertical-align: top;
            font-size: 12.5px;
        }

        table.info-table td.lbl {
            width: 30%;
            background: var(--lbl-bg);
            font-weight: 700;
            color: var(--text1);
            font-size: 11px;
        }

        table.info-table td.val {
            color: var(--text1);
            font-weight: 500;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .b-approved {
            background: #d1fae5;
            color: #047857;
        }

        .b-disapproved {
            background: #fee2e2;
            color: #b91c1c;
        }

        .b-pending {
            background: #fef3c7;
            color: #b45309;
        }

        .b-other {
            background: #e9edf7;
            color: var(--navy);
        }

        .pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: var(--surface2);
            border: 1px solid var(--bdr);
            margin: 2px 4px 2px 0;
        }

        table.detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            border: 1px solid var(--bdr);
        }

        table.detail-table th {
            background: var(--lbl-bg);
            text-align: left;
            padding: 8px 10px;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: var(--text2);
            border: 1px solid var(--bdr);
        }

        table.detail-table td {
            padding: 8px 10px;
            border: 1px solid var(--bdr);
            vertical-align: top;
        }

        table.detail-table tbody tr:nth-child(even) {
            background: #fbfcfe;
        }

        .empty-note {
            font-size: 12px;
            color: var(--text3);
            font-style: italic;
            padding: 10px 0;
        }

        .timeline {
            border-left: 2px solid var(--bdr);
            margin-left: 6px;
            padding-left: 18px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 16px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -23px;
            top: 3px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--navy);
        }

        .timeline-item .tt {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text1);
        }

        .timeline-item .ts {
            font-size: 10.5px;
            color: var(--text3);
        }

        .timeline-item .td-note {
            font-size: 12px;
            color: var(--text2);
            margin-top: 2px;
        }

        .doc-card {
            border: 1px solid var(--bdr);
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: var(--surface2);
        }

        .doc-card .dh {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .doc-comment {
            background: #fff;
            border: 1px solid var(--bdr);
            border-radius: 6px;
            padding: 8px 10px;
            margin-top: 8px;
            font-size: 12px;
        }

        .doc-comment .dc-meta {
            font-size: 10.5px;
            color: var(--text3);
            margin-bottom: 3px;
        }

        .footer-note {
            font-size: 10px;
            color: var(--text3);
            text-align: center;
            border-top: 1px solid var(--bdr);
            padding-top: 10px;
            margin-top: 24px;
        }

        /* 
           PRINT / PDF REPORT — separate, print-optimized layout.
           Hidden on screen; shown only for window.print() (via
           @media print below) and for html2pdf export (toggled by JS
           in exportToPdf(), since html2canvas ignores @media print). */
        #printReport {
            display: none;
            background: #fff;
            color: #1f2937;
            font-family: 'DM Sans', Arial, Helvetica, sans-serif;
            width: 100%;
            max-width: 190mm;
            margin: 0 auto;
            padding: 0;
        }

        #printReport .pr-page-header {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 3px solid #0b1f4b;
            padding-bottom: 10px;
            margin-bottom: 6px;
        }

        #printReport .pr-page-header img {
            height: 42px;
            width: auto;
        }

        #printReport .pr-republic {
            font-size: 8.5pt;
            color: #4b5563;
            margin: 0 0 1px;
        }

        #printReport .pr-agency {
            font-size: 12.5pt;
            font-weight: 800;
            color: #0b1f4b;
            letter-spacing: .01em;
        }

        #printReport .pr-office {
            font-size: 8.7pt;
            color: #4b5563;
            margin-top: 2px;
            line-height: 1.5;
        }

        #printReport .pr-report-title {
            text-align: center;
            margin: 14px 0 6px;
        }

        #printReport .pr-report-title h1 {
            font-family: 'DM Sans', Arial, sans-serif;
            font-weight: 800;
            font-size: 14pt;
            margin: 0;
            color: #0b1f4b;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        #printReport .pr-report-title p {
            font-size: 9.5pt;
            color: #6b7280;
            margin: 3px 0 0;
        }

        #printReport .pr-meta-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 8.5pt;
            color: #4b5563;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }

        #printReport .pr-status-badge {
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 4px;
            border: 1px solid #9ca3af;
            color: #1f2937;
        }

        #printReport .pr-intro {
            font-size: 9pt;
            line-height: 1.6;
            color: #374151;
            text-align: justify;
            margin: 0 0 12px;
        }

        #printReport .pr-alert {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
            font-size: 8.7pt;
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 4px;
            margin-bottom: 12px;
        }

        #printReport .pr-summary-panel {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            table-layout: fixed;
        }

        #printReport .pr-summary-panel td {
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            text-align: center;
            padding: 8px 4px;
        }

        #printReport .pr-summary-panel .sc-num {
            font-size: 13pt;
            font-weight: 800;
            color: #0b1f4b;
            display: block;
        }

        #printReport .pr-summary-panel .sc-lbl {
            font-size: 6.7pt;
            font-weight: 700;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: .02em;
            display: block;
            margin-top: 2px;
        }

        #printReport .pr-section-hint {
            font-size: 7.8pt;
            color: #9ca3af;
            font-style: italic;
            margin: -5px 0 6px;
        }

        #printReport .pr-notes-list {
            font-size: 8.7pt;
            color: #374151;
            line-height: 1.7;
            margin: 0;
            padding-left: 15px;
        }

        #printReport .pr-section {
            margin-bottom: 16px;
            page-break-inside: avoid;
        }

        #printReport .pr-section-title {
            font-size: 10.5pt;
            font-weight: 700;
            color: #fff;
            background: #0b1f4b;
            padding: 5px 9px;
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        #printReport table.pr-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        #printReport table.pr-grid td {
            font-size: 9pt;
            padding: 4px 6px;
            vertical-align: top;
            border-bottom: 1px dotted #e5e7eb;
        }

        #printReport table.pr-grid td.pr-label {
            color: #4b5563;
            font-weight: 700;
            width: 16%;
            white-space: nowrap;
        }

        #printReport table.pr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-top: 4px;
        }

        #printReport table.pr-table th {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            text-align: left;
            padding: 6px 8px;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: #374151;
        }

        #printReport table.pr-table td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
        }

        #printReport table.pr-table thead {
            display: table-header-group;
        }

        #printReport table.pr-table tr {
            page-break-inside: avoid;
        }

        #printReport table.pr-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        #printReport .pr-empty {
            font-size: 8.5pt;
            color: #9ca3af;
            font-style: italic;
            padding: 4px 0;
        }

        #printReport .pr-badge-ok {
            color: #047857;
            font-weight: 700;
        }

        #printReport .pr-badge-bad {
            color: #b91c1c;
            font-weight: 700;
        }

        #printReport .pr-compliance-note {
            font-size: 8.5pt;
            color: #4b5563;
            margin: 0 0 8px;
        }

        #printReport .pr-signoff {
            margin-top: 46px;
            display: flex;
            justify-content: space-between;
            font-size: 8.5pt;
            page-break-inside: avoid;
        }

        #printReport .pr-signoff .pr-sig-line {
            width: 42%;
            text-align: center;
        }

        #printReport .pr-signoff .pr-sig-line .line {
            border-top: 1px solid #1f2937;
            margin-top: 34px;
            padding-top: 4px;
            color: #374151;
        }

        #printReport .pr-footer {
            margin-top: 22px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            font-size: 7.5pt;
            color: #9ca3af;
        }

        @media print {

            .no-print,
            .webView {
                display: none !important;
            }

            #printReport {
                display: block !important;
            }

            body {
                background: #fff;
            }

            .sheet {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            @page {
                size: A4;
                margin: 14mm 12mm;
            }
        }

        #printReport .pr-footer {
            margin-top: 22px;
            padding-top: 8px;
            border-top: 1px solid #9ca3af;
        }

        #printReport .pr-footer-columns {
            display: flex;
            align-items: stretch;
            gap: 16px;
        }

        #printReport .pr-footer-left {
            flex: 1 1 60%;
            display: flex;
            align-items: center;
        }

        #printReport .pr-footer-disclaimer {
            font-size: 7.3pt;
            color: #6b7280;
            line-height: 1.55;
            text-align: left;
            margin: 0;
        }

        #printReport .pr-footer-right {
            flex: 0 0 34%;
            border-left: 1px solid #d1d5db;
            padding-left: 14px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
            font-size: 7.3pt;
            color: #6b7280;
        }

        #printReport .pr-footer-right span:first-child {
            color: #374151;
        }

        [data-theme="dark"] {
            --navy: #3b82f6;
            --navy-soft: #1e3a8a;
            --ok: #34d399;
            --amber: #fbbf24;
            --red: #f87171;
            --text1: #e2e8f0;
            --text2: #94a3b8;
            --text3: #64748b;
            --bdr: #2d3748;
            --lbl-bg: #1e293b;
            --surface: #1e293b;
            --surface2: #263449;
        }

        [data-theme="dark"] body {
            background: #0f172a;
        }

        [data-theme="dark"] .btn {
            background: var(--surface);
            color: var(--text1);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .btn:hover {
            background: var(--surface2);
        }

        [data-theme="dark"] .doc-sheet {
            background: var(--surface);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .agency-name,
        [data-theme="dark"] .doc-title {
            color: var(--navy);
        }

        [data-theme="dark"] .republic-line,
        [data-theme="dark"] .agency-sub,
        [data-theme="dark"] .doc-subtitle,
        [data-theme="dark"] .intro-note,
        [data-theme="dark"] .section-hint,
        [data-theme="dark"] .empty-note,
        [data-theme="dark"] .footer-note,
        [data-theme="dark"] .timeline-item .ts,
        [data-theme="dark"] .timeline-item .td-note {
            color: var(--text2);
        }

        [data-theme="dark"] .section-title {
            background: var(--navy-soft);
            color: #f8fafc;
        }

        [data-theme="dark"] .summary-panel {
            border-color: var(--bdr);
        }

        [data-theme="dark"] .summary-cell {
            background: var(--surface2);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .summary-cell .sc-num {
            color: var(--navy);
        }

        [data-theme="dark"] .summary-cell.sc-warn .sc-num {
            color: var(--red);
        }

        [data-theme="dark"] .alert-note {
            background: rgba(217, 119, 6, .15);
            border-color: rgba(251, 191, 36, .35);
            color: #fbbf24;
        }

        [data-theme="dark"] table.info-table,
        [data-theme="dark"] table.detail-table {
            border-color: var(--bdr);
        }

        [data-theme="dark"] table.info-table td,
        [data-theme="dark"] table.detail-table th,
        [data-theme="dark"] table.detail-table td {
            border-color: var(--bdr);
        }

        [data-theme="dark"] table.info-table td.lbl {
            background: var(--lbl-bg);
            color: var(--text1);
        }

        [data-theme="dark"] table.info-table td.val {
            color: var(--text1);
        }

        [data-theme="dark"] table.detail-table th {
            background: var(--lbl-bg);
            color: var(--text2);
        }

        [data-theme="dark"] table.detail-table td {
            color: var(--text1);
        }

        [data-theme="dark"] table.detail-table tbody tr:nth-child(even) {
            background: #202f45;
        }

        [data-theme="dark"] .pill {
            background: var(--surface2);
            border-color: var(--bdr);
            color: var(--text1);
        }

        [data-theme="dark"] .timeline {
            border-color: var(--bdr);
        }

        [data-theme="dark"] .timeline-item::before {
            background: var(--navy);
        }

        [data-theme="dark"] .timeline-item .tt {
            color: var(--text1);
        }

        [data-theme="dark"] .doc-card {
            background: var(--surface2);
            border-color: var(--bdr);
        }

        [data-theme="dark"] .doc-comment {
            background: var(--surface);
            border-color: var(--bdr);
            color: var(--text1);
        }

        [data-theme="dark"] .doc-comment .dc-meta {
            color: var(--text3);
        }

        [data-theme="dark"] .badge.b-approved {
            background: rgba(16, 185, 129, .18);
            color: #34d399;
        }

        [data-theme="dark"] .badge.b-disapproved {
            background: rgba(220, 38, 38, .20);
            color: #f87171;
        }

        [data-theme="dark"] .badge.b-pending {
            background: rgba(217, 119, 6, .20);
            color: #fbbf24;
        }

        [data-theme="dark"] .badge.b-other {
            background: rgba(99, 102, 241, .22);
            color: #a5b4fc;
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

    <header class="topbar no-print">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
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
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <div class="sheet">

        <?php if ($notFound): ?>
            <div class="doc-sheet" style="text-align:center;padding:60px 24px;">
                <i class="fas fa-circle-exclamation" style="font-size:36px;color:var(--red);margin-bottom:12px;"></i>
                <h2 style="justify-content:center;color:var(--navy);">Application #<?= (int) $id ?> not found</h2>
                <p style="color:var(--text2);font-size:13px;">This application may have been removed, or the ID in the link is incorrect.</p>
                <a href="applicant-reports.php" class="btn btn-primary" style="margin-top:14px;"><i class="fas fa-arrow-left"></i> Back to Applicant Reports</a>
            </div>
        <?php else: ?>

            <div class="toolbar-actions no-print">
                <a href="applicant-reports.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Applicant Reports</a>
                <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-primary" id="exportPdfBtn" onclick="exportToPdf()"><i class="fas fa-file-pdf"></i> Export to PDF</button>
            </div>
            <!--  WEB VIEW — interactive on-screen layout, restyled to match
                 the certificate's navy government-document design.-->
            <div class="webView">
                <div class="doc-sheet">

                    <!--  HEADER  -->
                    <div class="doc-header-table">
                        <img id="printLogo" src="../pictures/dmw_logo.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
                        <div>
                            <p class="republic-line">Republic of the Philippines</p>
                            <p class="agency-name">DEPARTMENT OF MIGRANT WORKERS</p>
                            <p class="agency-sub">
                                Regional Office IV-A (CALABARZON)<br>
                                Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                                Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                            </p>
                        </div>
                    </div>

                    <div class="doc-title">Application Report</div>
                    <div class="doc-subtitle">
                        <?= htmlspecialchars($applicantName) ?> — <?= htmlspecialchars($app['position_title']) ?> at <?= htmlspecialchars($app['employer_name']) ?>
                    </div>

                    <table class="ref-table">
                        <tr>
                            <td class="ref-label">Application No.:</td>
                            <td>#<?= (int) $app['application_id'] ?></td>
                        </tr>
                        <tr>
                            <td class="ref-label">RSF Number:</td>
                            <td><?= htmlspecialchars($app['rsf_number']) ?></td>
                        </tr>
                        <tr>
                            <td class="ref-label">Current Status:</td>
                            <td><span class="badge <?= badgeClass($app['status']) ?>"><?= statusLabel($app['status']) ?></span></td>
                        </tr>
                    </table>

                    <p class="intro-note">
                        This report presents the complete case record of <strong><?= htmlspecialchars($applicantName) ?></strong>'s
                        application for <strong><?= htmlspecialchars($app['position_title']) ?></strong> at
                        <strong><?= htmlspecialchars($app['employer_name']) ?></strong> (RSF <?= htmlspecialchars($app['rsf_number']) ?>),
                        as processed through the DMW Application Management System. It consolidates the applicant's
                        profile, job order details, submitted answers and documents, and the full evaluation, status,
                        and assignment trail, giving the reviewing officer a single reference for case decisions,
                        audits, or compliance follow-up.
                    </p>

                    <?php if ($isPastDeadline): ?>
                        <div class="alert-note">
                            <i class="fas fa-triangle-exclamation"></i>
                            This application is still <?= strtolower(statusLabel($app['status'])) ?> and the job order deadline
                            (<?= htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) ?>) has already passed. Prioritize review or confirm with the client.
                        </div>
                    <?php endif; ?>

                    <div class="summary-panel">
                        <div class="summary-cell">
                            <div class="sc-num"><?= $daysInProcess ?></div>
                            <div class="sc-lbl"><?= $isClosed ? 'Days to Close' : 'Days in Process' ?></div>
                        </div>
                        <div class="summary-cell">
                            <div class="sc-num"><?= $statusChangeCount ?></div>
                            <div class="sc-lbl">Status Changes</div>
                        </div>
                        <div class="summary-cell">
                            <div class="sc-num"><?= $evaluatorCount ?></div>
                            <div class="sc-lbl">Evaluator(s) Involved</div>
                        </div>
                        <div class="summary-cell <?= $complianceRounds > 2 ? 'sc-warn' : '' ?>">
                            <div class="sc-num"><?= $complianceRounds ?></div>
                            <div class="sc-lbl">Compliance Rounds</div>
                        </div>
                        <div class="summary-cell <?= $completionPct < 100 ? 'sc-warn' : '' ?>">
                            <div class="sc-num"><?= $completionPct ?>%</div>
                            <div class="sc-lbl">Docs Complete</div>
                        </div>
                    </div>

                    <!--  APPLICANT PROFILE  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-user"></i> I. Applicant Profile</div>
                        <p class="section-hint">Personal, educational, and preference details as declared by the applicant. Cross-check against submitted documents below.</p>
                        <table class="info-table">
                            <tr>
                                <td class="lbl">Full Name</td>
                                <td class="val"><?= htmlspecialchars($applicantName) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Email</td>
                                <td class="val"><?= htmlspecialchars($app['applicant_email']) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Phone</td>
                                <td class="val"><?= htmlspecialchars(nz($app['phone'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Date of Birth / Age</td>
                                <td class="val"><?= $app['dob'] ? htmlspecialchars(date('M j, Y', strtotime($app['dob']))) . ' (' . $age . ' yrs)' : '—' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Gender</td>
                                <td class="val"><?= htmlspecialchars(nz($app['gender'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Civil Status</td>
                                <td class="val"><?= htmlspecialchars(nz($app['civil_status'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Citizenship</td>
                                <td class="val"><?= htmlspecialchars(nz($app['citizenship'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Religion</td>
                                <td class="val"><?= htmlspecialchars(nz($app['religion'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Height / Weight</td>
                                <td class="val"><?= $app['height_cm'] ? htmlspecialchars($app['height_cm']) . ' cm' : '—' ?> / <?= $app['weight_kg'] ? htmlspecialchars($app['weight_kg']) . ' kg' : '—' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Address</td>
                                <td class="val"><?= htmlspecialchars(implode(', ', array_filter([$app['address'], $app['barangay'], $app['city_municipality'], $app['province']]))) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Educational Attainment</td>
                                <td class="val"><?= htmlspecialchars(nz($app['educational_attainment'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Degree / Course</td>
                                <td class="val"><?= htmlspecialchars(nz($app['degree_name'] ?: $app['course_degree'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">School</td>
                                <td class="val"><?= htmlspecialchars(nz($app['school_name'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Year Graduated</td>
                                <td class="val"><?= htmlspecialchars(nz($app['year_graduated'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Work Experience</td>
                                <td class="val"><?= $app['work_experience_years'] !== null ? htmlspecialchars($app['work_experience_years']) . ' yr(s)' : '—' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Last Employer / Position</td>
                                <td class="val"><?= htmlspecialchars(nz($app['last_employer'])) ?> <?= $app['last_position'] ? '— ' . htmlspecialchars($app['last_position']) : '' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Profile Completed</td>
                                <td class="val"><?= $app['profile_completed_at'] ? fmtDate($app['profile_completed_at'], 'M j, Y') : 'Not completed' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Preferred Countries</td>
                                <td class="val">
                                    <?php if ($preferredCountries): ?>
                                        <?php foreach ($preferredCountries as $pc): ?>
                                            <span class="pill">#<?= (int) $pc['priority_order'] ?> <?= htmlspecialchars($pc['country']) ?></span>
                                        <?php endforeach; ?>
                                        <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="lbl">Preferred Positions</td>
                                <td class="val">
                                    <?php if ($preferredPositions): ?>
                                        <?php foreach ($preferredPositions as $pp): ?>
                                            <span class="pill">#<?= (int) $pp['priority_order'] ?> <?= htmlspecialchars($pp['subcategory_name']) ?></span>
                                        <?php endforeach; ?>
                                        <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($clientSkills): ?>
                                <tr>
                                    <td class="lbl">Skills</td>
                                    <td class="val">
                                        <?php foreach ($clientSkills as $sk): ?>
                                            <span class="pill"><?= htmlspecialchars($sk['skill_name']) ?> (<?= htmlspecialchars($sk['proficiency_level']) ?>)</span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($clientLicenses): ?>
                                <tr>
                                    <td class="lbl">Professional Licenses</td>
                                    <td class="val">
                                        <?php foreach ($clientLicenses as $lic): ?>
                                            <span class="pill"><?= htmlspecialchars($lic['license_name']) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!--  APPLICATION / JOB ORDER INFO  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-briefcase"></i> II. Application &amp; Job Order Information</div>
                        <p class="section-hint">Recruitment slot the applicant is being matched against — verify qualifications, salary, and deadline align with the applicant's profile.</p>
                        <table class="info-table">
                            <tr>
                                <td class="lbl">RSF Number</td>
                                <td class="val"><?= htmlspecialchars($app['rsf_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Position</td>
                                <td class="val"><?= htmlspecialchars($app['position_title']) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Category / Subcategory</td>
                                <td class="val"><?= htmlspecialchars(nz($app['category_name'])) ?> / <?= htmlspecialchars(nz($app['subcategory_name'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Employer</td>
                                <td class="val"><?= htmlspecialchars($app['employer_name']) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Worksite Country</td>
                                <td class="val"><?= htmlspecialchars(nz($app['worksite_country'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Classification</td>
                                <td class="val"><?= htmlspecialchars(nz($app['classification'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Slots Available</td>
                                <td class="val"><?= htmlspecialchars(nz($app['slots_available'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Salary Range</td>
                                <td class="val"><?= ($app['salary_min'] !== null && $app['salary_max'] !== null) ? htmlspecialchars(number_format($app['salary_min'], 2) . ' – ' . number_format($app['salary_max'], 2) . ' ' . ($app['salary_currency'] ?: 'PHP')) : '—' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Job Order Deadline</td>
                                <td class="val"><?= $app['job_deadline'] ? htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) : '—' ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Handling Party</td>
                                <td class="val"><?= htmlspecialchars(nz($app['handling_party'] ? ucfirst($app['handling_party']) : null)) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Current Evaluator</td>
                                <td class="val"><?= htmlspecialchars(nz($app['evaluator_name'])) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Locked At</td>
                                <td class="val"><?= fmtDate($app['locked_at']) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Submitted At</td>
                                <td class="val"><?= fmtDate($app['submitted_at']) ?></td>
                            </tr>
                            <tr>
                                <td class="lbl">Last Updated</td>
                                <td class="val"><?= fmtDate($app['updated_at']) ?></td>
                            </tr>
                            <?php if ($app['qualifications']): ?>
                                <tr>
                                    <td class="lbl">Job Qualifications</td>
                                    <td class="val"><?= nl2br(htmlspecialchars($app['qualifications'])) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($app['admin_notes']): ?>
                                <tr>
                                    <td class="lbl">Admin Notes</td>
                                    <td class="val"><?= nl2br(htmlspecialchars($app['admin_notes'])) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!--  APPLICATION ANSWERS  -->
                    <?php if ($answers): ?>
                        <div class="section">
                            <div class="section-title"><i class="fas fa-circle-question"></i> III. Application Answers</div>
                            <p class="section-hint">Screening questions specific to this job order, answered at the time of submission.</p>
                            <table class="detail-table">
                                <thead>
                                    <tr>
                                        <th>Question</th>
                                        <th>Answer</th>
                                        <th>Answered At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($answers as $a): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($a['question_text']) ?></td>
                                            <td><?= htmlspecialchars(nz($a['answer'])) ?></td>
                                            <td><?= fmtDate($a['answered_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!--  DOCUMENTS  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-folder-open"></i> IV. Documents &amp; Comments</div>
                        <p class="section-hint">Files uploaded against this job order's requirements, with any reviewer remarks attached to each file.</p>
                        <p style="font-size:12px;color:var(--text2);margin:0 0 14px;">
                            Compliance: <b><?= $submittedCount ?> / <?= $requiredCount ?></b> required documents submitted
                            (<span class="badge <?= $completionPct == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= $completionPct ?>%</span>)
                            <?php if ($missingDocs): ?>&nbsp;•&nbsp; Missing: <b><?= htmlspecialchars(implode(', ', $missingDocs)) ?></b><?php endif; ?>
                        </p>

                        <?php if (!$documents): ?>
                            <p class="empty-note">No documents uploaded for this application.</p>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                                <div class="doc-card">
                                    <div class="dh">
                                        <div>
                                            <div style="font-weight:700;font-size:12.5px;"><?= htmlspecialchars($doc['document_name'] ?: ucfirst($doc['document_type'])) ?></div>
                                            <div style="font-size:11px;color:var(--text3);"><?= htmlspecialchars($doc['file_name']) ?> &nbsp;•&nbsp; Uploaded <?= fmtDate($doc['uploaded_at']) ?></div>
                                        </div>
                                        <?php if ($doc['is_required']): ?><span class="badge b-other">Required</span><?php endif; ?>
                                    </div>
                                    <?php foreach ($doc['comments'] as $c): ?>
                                        <div class="doc-comment">
                                            <div class="dc-meta"><?= htmlspecialchars($c['commented_by']) ?> — <?= fmtDate($c['created_at']) ?></div>
                                            <?= htmlspecialchars($c['comment']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!--  EVALUATION HISTORY  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-clipboard-check"></i> V. Evaluation History</div>
                        <p class="section-hint">Every decision recorded against this application, in chronological order, with the evaluator or admin responsible.</p>
                        <?php if (!$evaluations): ?>
                            <p class="empty-note">No evaluations recorded yet.</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($evaluations as $e): ?>
                                    <div class="timeline-item">
                                        <div class="tt">
                                            <?= htmlspecialchars(ucfirst($e['evaluation_type'] ?? 'evaluation')) ?> —
                                            <span class="badge <?= badgeClass($e['decision']) ?>"><?= statusLabel($e['decision'] ?? '—') ?></span>
                                            by <?= htmlspecialchars($e['evaluator_name'] ?: $e['admin_name'] ?: 'System') ?>
                                        </div>
                                        <div class="ts"><?= fmtDate($e['evaluated_at']) ?></div>
                                        <?php if ($e['comments']): ?><div class="td-note"><?= htmlspecialchars($e['comments']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!--  STATUS HISTORY  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-timeline"></i> VI. Status History</div>
                        <p class="section-hint">Full audit trail of status transitions — useful for tracing delays or repeated compliance cycles.</p>
                        <?php if (!$statusHistory): ?>
                            <p class="empty-note">No status changes recorded yet.</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($statusHistory as $h): ?>
                                    <div class="timeline-item">
                                        <div class="tt"><?= statusLabel($h['from_status'] ?? '—') ?> → <?= statusLabel($h['to_status'] ?? '—') ?></div>
                                        <div class="ts">By <?= htmlspecialchars($h['changed_by'] ?: 'System') ?> — <?= fmtDate($h['changed_at']) ?></div>
                                        <?php if ($h['reason']): ?><div class="td-note"><?= htmlspecialchars($h['reason']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!--  EVALUATOR ASSIGNMENTS  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-user-tie"></i> VII. Evaluator Assignments</div>
                        <p class="section-hint">Which evaluator(s) have handled this case and when — helps confirm accountability and reassignment history.</p>
                        <?php if (!$assignments): ?>
                            <p class="empty-note">No evaluator has been assigned to this application.</p>
                        <?php else: ?>
                            <table class="detail-table">
                                <thead>
                                    <tr>
                                        <th>Evaluator</th>
                                        <th>Email</th>
                                        <th>Assigned At</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $asg): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($asg['evaluator_name']) ?></td>
                                            <td><?= htmlspecialchars($asg['evaluator_email']) ?></td>
                                            <td><?= fmtDate($asg['assigned_at']) ?></td>
                                            <td><span class="badge b-other"><?= htmlspecialchars(ucfirst($asg['status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!--  NOTES FOR REVIEWING OFFICER  -->
                    <div class="section">
                        <div class="section-title"><i class="fas fa-lightbulb"></i> VIII. Notes for Reviewing Officer</div>
                        <ul style="font-size:12.5px;color:var(--text2);line-height:1.8;margin:0;padding-left:18px;">
                            <?php if ($missingDocs): ?>
                                <li><strong>Missing documents:</strong> <?= htmlspecialchars(implode(', ', $missingDocs)) ?> — request these before advancing the application past compliance.</li>
                            <?php elseif ($requiredCount > 0): ?>
                                <li>All required documents (<?= $requiredCount ?>) have been submitted for this job order.</li>
                            <?php endif; ?>
                            <?php if ($complianceRounds > 2): ?>
                                <li>This application has gone through <strong><?= $complianceRounds ?> compliance rounds</strong> — consider a direct call or in-person follow-up with the applicant to resolve recurring issues faster.</li>
                            <?php endif; ?>
                            <?php if ($evaluatorCount > 1): ?>
                                <li>Handled by <strong><?= $evaluatorCount ?> different evaluators</strong> (<?= htmlspecialchars(implode(', ', $evaluatorNames)) ?>) — review the evaluation history above for continuity before finalizing a decision.</li>
                            <?php endif; ?>
                            <?php if ($isPastDeadline): ?>
                                <li>The job order deadline has passed while this application remains <?= strtolower(statusLabel($app['status'])) ?> — flag for priority handling or client notification.</li>
                            <?php endif; ?>
                            <?php if ($isClosed): ?>
                                <li>This case is closed (<?= strtolower(statusLabel($app['status'])) ?>) after <?= $daysInProcess ?> day(s) of processing. No further action is required unless reopened.</li>
                            <?php else: ?>
                                <li>This case has been in process for <?= $daysInProcess ?> day(s) and is currently <?= strtolower(statusLabel($app['status'])) ?>.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <p class="footer-note">Department of Migrant Workers — Application Management System &nbsp;|&nbsp; Application #<?= (int) $app['application_id'] ?> &nbsp;|&nbsp; For official use only</p>

                </div><!-- /.doc-sheet -->
            </div><!-- /.webView -->
            <!--PRINT / PDF REPORT — separate, print-optimized layout.
                 Hidden by default; shown for window.print() and for
                 PDF export (see @media print CSS and exportToPdf() JS). -->
            <div id="printReport">

                <div class="pr-page-header">
                    <img id="printLogo" src="../pictures/dmw_tahanan_ng_ofw.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
                    <div>
                        <div class="pr-republic">Republic of the Philippines</div>
                        <div class="pr-agency">DEPARTMENT OF MIGRANT WORKERS</div>
                        <div class="pr-office">
                            Regional Office IV-A (CALABARZON)<br>
                            Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                            Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                        </div>
                    </div>
                </div>

                <div class="pr-report-title">
                    <h1>Application Report</h1>
                    <p>Application #<?= (int) $app['application_id'] ?> — <?= htmlspecialchars($applicantName) ?></p>
                </div>

                <div class="pr-meta-bar">
                    <span>Generated: <?= date('M j, Y g:i A') ?></span>
                    <span>Status: <span class="pr-status-badge"><?= statusLabel($app['status']) ?></span></span>
                    <span>RSF <?= htmlspecialchars($app['rsf_number']) ?></span>
                </div>

                <p class="pr-intro">
                    This report presents the complete case record of <strong><?= htmlspecialchars($applicantName) ?></strong>'s
                    application for <strong><?= htmlspecialchars($app['position_title']) ?></strong> at
                    <strong><?= htmlspecialchars($app['employer_name']) ?></strong> (RSF <?= htmlspecialchars($app['rsf_number']) ?>),
                    as processed through the DMW Application Management System. It consolidates the applicant's profile,
                    job order details, submitted answers and documents, and the full evaluation, status, and assignment
                    trail for reference during case review, audit, or compliance follow-up.
                </p>

                <?php if ($isPastDeadline): ?>
                    <div class="pr-alert">
                        <i class="fas fa-triangle-exclamation"></i>
                        Job order deadline (<?= htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) ?>) has passed while this application remains <?= strtolower(statusLabel($app['status'])) ?>.
                    </div>
                <?php endif; ?>

                <table class="pr-summary-panel">
                    <tr>
                        <td>
                            <span class="sc-num"><?= $daysInProcess ?></span>
                            <span class="sc-lbl"><?= $isClosed ? 'Days to Close' : 'Days in Process' ?></span>
                        </td>
                        <td>
                            <span class="sc-num"><?= $statusChangeCount ?></span>
                            <span class="sc-lbl">Status Changes</span>
                        </td>
                        <td>
                            <span class="sc-num"><?= $evaluatorCount ?></span>
                            <span class="sc-lbl">Evaluator(s)</span>
                        </td>
                        <td>
                            <span class="sc-num"><?= $complianceRounds ?></span>
                            <span class="sc-lbl">Compliance Rounds</span>
                        </td>
                        <td>
                            <span class="sc-num"><?= $completionPct ?>%</span>
                            <span class="sc-lbl">Docs Complete</span>
                        </td>
                    </tr>
                </table>

                <!-- Applicant Profile -->
                <div class="pr-section">
                    <div class="pr-section-title">I. Applicant Profile</div>
                    <p class="pr-section-hint">Personal, educational, and preference details as declared by the applicant.</p>
                    <table class="pr-grid">
                        <tr>
                            <td class="pr-label">Full Name</td>
                            <td><?= htmlspecialchars($applicantName) ?></td>
                            <td class="pr-label">Email</td>
                            <td><?= htmlspecialchars($app['applicant_email']) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Phone</td>
                            <td><?= htmlspecialchars(nz($app['phone'])) ?></td>
                            <td class="pr-label">DOB / Age</td>
                            <td><?= $app['dob'] ? htmlspecialchars(date('M j, Y', strtotime($app['dob']))) . ' (' . $age . ' yrs)' : '—' ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Gender</td>
                            <td><?= htmlspecialchars(nz($app['gender'])) ?></td>
                            <td class="pr-label">Civil Status</td>
                            <td><?= htmlspecialchars(nz($app['civil_status'])) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Citizenship</td>
                            <td><?= htmlspecialchars(nz($app['citizenship'])) ?></td>
                            <td class="pr-label">Religion</td>
                            <td><?= htmlspecialchars(nz($app['religion'])) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Height / Weight</td>
                            <td><?= $app['height_cm'] ? htmlspecialchars($app['height_cm']) . ' cm' : '—' ?> / <?= $app['weight_kg'] ? htmlspecialchars($app['weight_kg']) . ' kg' : '—' ?></td>
                            <td class="pr-label">Address</td>
                            <td><?= htmlspecialchars(implode(', ', array_filter([$app['address'], $app['barangay'], $app['city_municipality'], $app['province']]))) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Education</td>
                            <td><?= htmlspecialchars(nz($app['educational_attainment'])) ?></td>
                            <td class="pr-label">Degree / Course</td>
                            <td><?= htmlspecialchars(nz($app['degree_name'] ?: $app['course_degree'])) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">School</td>
                            <td><?= htmlspecialchars(nz($app['school_name'])) ?></td>
                            <td class="pr-label">Year Graduated</td>
                            <td><?= htmlspecialchars(nz($app['year_graduated'])) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Work Experience</td>
                            <td><?= $app['work_experience_years'] !== null ? htmlspecialchars($app['work_experience_years']) . ' yr(s)' : '—' ?></td>
                            <td class="pr-label">Last Employer / Position</td>
                            <td><?= htmlspecialchars(nz($app['last_employer'])) ?><?= $app['last_position'] ? ' — ' . htmlspecialchars($app['last_position']) : '' ?></td>
                        </tr>
                    </table>

                    <?php if ($preferredCountries || $preferredPositions): ?>
                        <table class="pr-grid">
                            <tr>
                                <td class="pr-label">Pref. Countries</td>
                                <td colspan="3"><?= $preferredCountries ? htmlspecialchars(implode(', ', array_map(fn($pc) => '#' . $pc['priority_order'] . ' ' . $pc['country'], $preferredCountries))) : '—' ?></td>
                            </tr>
                            <tr>
                                <td class="pr-label">Pref. Positions</td>
                                <td colspan="3"><?= $preferredPositions ? htmlspecialchars(implode(', ', array_map(fn($pp) => '#' . $pp['priority_order'] . ' ' . $pp['subcategory_name'], $preferredPositions))) : '—' ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>

                    <?php if ($clientSkills || $clientLicenses): ?>
                        <table class="pr-grid">
                            <?php if ($clientSkills): ?>
                                <tr>
                                    <td class="pr-label">Skills</td>
                                    <td colspan="3"><?= htmlspecialchars(implode(', ', array_map(fn($sk) => $sk['skill_name'] . ' (' . $sk['proficiency_level'] . ')', $clientSkills))) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($clientLicenses): ?>
                                <tr>
                                    <td class="pr-label">Licenses</td>
                                    <td colspan="3"><?= htmlspecialchars(implode(', ', array_column($clientLicenses, 'license_name'))) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Application & Job Order -->
                <div class="pr-section">
                    <div class="pr-section-title">II. Application &amp; Job Order Information</div>
                    <p class="pr-section-hint">Recruitment slot the applicant is being matched against.</p>
                    <table class="pr-grid">
                        <tr>
                            <td class="pr-label">RSF Number</td>
                            <td><?= htmlspecialchars($app['rsf_number']) ?></td>
                            <td class="pr-label">Position</td>
                            <td><?= htmlspecialchars($app['position_title']) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Category</td>
                            <td><?= htmlspecialchars(nz($app['category_name'])) ?> / <?= htmlspecialchars(nz($app['subcategory_name'])) ?></td>
                            <td class="pr-label">Employer</td>
                            <td><?= htmlspecialchars($app['employer_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Worksite Country</td>
                            <td><?= htmlspecialchars(nz($app['worksite_country'])) ?></td>
                            <td class="pr-label">Classification</td>
                            <td><?= htmlspecialchars(nz($app['classification'])) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Slots Available</td>
                            <td><?= htmlspecialchars(nz($app['slots_available'])) ?></td>
                            <td class="pr-label">Salary Range</td>
                            <td><?= ($app['salary_min'] !== null && $app['salary_max'] !== null) ? htmlspecialchars(number_format($app['salary_min'], 2) . ' – ' . number_format($app['salary_max'], 2) . ' ' . ($app['salary_currency'] ?: 'PHP')) : '—' ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Job Deadline</td>
                            <td><?= $app['job_deadline'] ? htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) : '—' ?></td>
                            <td class="pr-label">Handling Party</td>
                            <td><?= htmlspecialchars(nz($app['handling_party'] ? ucfirst($app['handling_party']) : null)) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Current Evaluator</td>
                            <td><?= htmlspecialchars(nz($app['evaluator_name'])) ?></td>
                            <td class="pr-label">Submitted At</td>
                            <td><?= fmtDate($app['submitted_at']) ?></td>
                        </tr>
                        <tr>
                            <td class="pr-label">Locked At</td>
                            <td><?= fmtDate($app['locked_at']) ?></td>
                            <td class="pr-label">Last Updated</td>
                            <td><?= fmtDate($app['updated_at']) ?></td>
                        </tr>
                    </table>
                    <?php if ($app['qualifications']): ?>
                        <table class="pr-grid">
                            <tr>
                                <td class="pr-label">Qualifications</td>
                                <td colspan="3"><?= nl2br(htmlspecialchars($app['qualifications'])) ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                    <?php if ($app['admin_notes']): ?>
                        <table class="pr-grid">
                            <tr>
                                <td class="pr-label">Admin Notes</td>
                                <td colspan="3"><?= nl2br(htmlspecialchars($app['admin_notes'])) ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Application Answers -->
                <?php if ($answers): ?>
                    <div class="pr-section">
                        <div class="pr-section-title">III. Application Answers</div>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th style="width:48%;">Question</th>
                                    <th style="width:32%;">Answer</th>
                                    <th style="width:20%;">Answered At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($answers as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['question_text']) ?></td>
                                        <td><?= htmlspecialchars(nz($a['answer'])) ?></td>
                                        <td><?= fmtDate($a['answered_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Documents -->
                <div class="pr-section">
                    <div class="pr-section-title">IV. Document Compliance</div>
                    <p class="pr-section-hint">Files uploaded against this job order's requirements, with reviewer remarks.</p>
                    <p class="pr-compliance-note">
                        Required documents submitted: <b><?= $submittedCount ?> / <?= $requiredCount ?></b>
                        (<span class="<?= $completionPct == 100 ? 'pr-badge-ok' : 'pr-badge-bad' ?>"><?= $completionPct ?>%</span>)
                        <?php if ($missingDocs): ?> — Missing: <b><?= htmlspecialchars(implode(', ', $missingDocs)) ?></b><?php endif; ?>
                    </p>
                    <?php if (!$documents): ?>
                        <p class="pr-empty">No documents uploaded for this application.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th style="width:20%;">Document</th>
                                    <th style="width:22%;">File Name</th>
                                    <th style="width:14%;">Uploaded</th>
                                    <th style="width:10%;">Required</th>
                                    <th style="width:34%;">Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($doc['document_name'] ?: ucfirst($doc['document_type'])) ?></td>
                                        <td><?= htmlspecialchars($doc['file_name']) ?></td>
                                        <td><?= fmtDate($doc['uploaded_at'], 'M j, Y') ?></td>
                                        <td><?= $doc['is_required'] ? 'Yes' : 'No' ?></td>
                                        <td>
                                            <?php if ($doc['comments']): ?>
                                                <?php foreach ($doc['comments'] as $c): ?>
                                                    <div><?= htmlspecialchars($c['commented_by']) ?> (<?= fmtDate($c['created_at'], 'M j, Y') ?>): <?= htmlspecialchars($c['comment']) ?></div>
                                                <?php endforeach; ?>
                                                <?php else: ?>—<?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Evaluation History -->
                <div class="pr-section">
                    <div class="pr-section-title">V. Evaluation History</div>
                    <?php if (!$evaluations): ?>
                        <p class="pr-empty">No evaluations recorded yet.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th style="width:16%;">Date</th>
                                    <th style="width:12%;">Type</th>
                                    <th style="width:14%;">Decision</th>
                                    <th style="width:18%;">By</th>
                                    <th style="width:40%;">Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluations as $e): ?>
                                    <tr>
                                        <td><?= fmtDate($e['evaluated_at']) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($e['evaluation_type'] ?? '—')) ?></td>
                                        <td><?= statusLabel($e['decision'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($e['evaluator_name'] ?: $e['admin_name'] ?: 'System') ?></td>
                                        <td><?= htmlspecialchars(nz($e['comments'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Status History -->
                <div class="pr-section">
                    <div class="pr-section-title">VI. Status History</div>
                    <?php if (!$statusHistory): ?>
                        <p class="pr-empty">No status changes recorded yet.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th style="width:16%;">Date</th>
                                    <th style="width:16%;">From</th>
                                    <th style="width:16%;">To</th>
                                    <th style="width:18%;">By</th>
                                    <th style="width:34%;">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statusHistory as $h): ?>
                                    <tr>
                                        <td><?= fmtDate($h['changed_at']) ?></td>
                                        <td><?= statusLabel($h['from_status'] ?? '—') ?></td>
                                        <td><?= statusLabel($h['to_status'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($h['changed_by'] ?: 'System') ?></td>
                                        <td><?= htmlspecialchars(nz($h['reason'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Evaluator Assignments -->
                <div class="pr-section">
                    <div class="pr-section-title">VII. Evaluator Assignments</div>
                    <?php if (!$assignments): ?>
                        <p class="pr-empty">No evaluator has been assigned to this application.</p>
                    <?php else: ?>
                        <table class="pr-table">
                            <thead>
                                <tr>
                                    <th style="width:28%;">Evaluator</th>
                                    <th style="width:32%;">Email</th>
                                    <th style="width:24%;">Assigned At</th>
                                    <th style="width:16%;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $asg): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($asg['evaluator_name']) ?></td>
                                        <td><?= htmlspecialchars($asg['evaluator_email']) ?></td>
                                        <td><?= fmtDate($asg['assigned_at']) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($asg['status'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Notes for Reviewing Officer -->
                <div class="pr-section">
                    <div class="pr-section-title">VIII. Notes for Reviewing Officer</div>
                    <ul class="pr-notes-list">
                        <?php if ($missingDocs): ?>
                            <li><strong>Missing documents:</strong> <?= htmlspecialchars(implode(', ', $missingDocs)) ?> — request before advancing past compliance.</li>
                        <?php elseif ($requiredCount > 0): ?>
                            <li>All required documents (<?= $requiredCount ?>) have been submitted for this job order.</li>
                        <?php endif; ?>
                        <?php if ($complianceRounds > 2): ?>
                            <li>This application has gone through <strong><?= $complianceRounds ?> compliance rounds</strong> — consider direct follow-up with the applicant.</li>
                        <?php endif; ?>
                        <?php if ($evaluatorCount > 1): ?>
                            <li>Handled by <strong><?= $evaluatorCount ?> different evaluators</strong> (<?= htmlspecialchars(implode(', ', $evaluatorNames)) ?>) — review evaluation history above before finalizing.</li>
                        <?php endif; ?>
                        <?php if ($isPastDeadline): ?>
                            <li>Job order deadline has passed while this application remains <?= strtolower(statusLabel($app['status'])) ?> — flag for priority handling.</li>
                        <?php endif; ?>
                        <?php if ($isClosed): ?>
                            <li>This case is closed (<?= strtolower(statusLabel($app['status'])) ?>) after <?= $daysInProcess ?> day(s) of processing.</li>
                        <?php else: ?>
                            <li>This case has been in process for <?= $daysInProcess ?> day(s) and is currently <?= strtolower(statusLabel($app['status'])) ?>.</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="pr-signoff">
                    <div class="pr-sig-line">
                        <div class="line">Evaluator / Reviewing Officer</div>
                    </div>
                    <div class="pr-sig-line">
                        <div class="line">Authorized Signatory, DMW RO IV-A</div>
                    </div>
                </div>

                <div class="pr-footer">
                    <div class="pr-footer-columns">
                        <div class="pr-footer-left">
                            <p class="pr-footer-disclaimer">
                                This is an official system-generated report from the DMW Application Management System —
                                Regional Office IV-A (CALABARZON). Information is confidential and intended solely for internal
                                evaluation, compliance review, and official case processing.
                            </p>
                        </div>
                        <div class="pr-footer-right">
                            <span>Department of Migrant Workers - RO IV-A (CALABARZON)</span>
                            <span>Application #<?= (int) $app['application_id'] ?></span>
                            <span>System-Generated Document • For Official Use Only</span>
                            <span><?= date('M j, Y g:i A') ?></span>
                        </div>
                    </div>
                </div>

            </div><!-- /#printReport -->

        <?php endif; ?>
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
    </script>
    <script>
        // Converts a same-origin image URL into a base64 data URI. Used so the
        // DMW logo is guaranteed to be fully available as pixel data before
        // html2canvas captures the report (avoids load-timing/CORS issues).
        async function toDataURL(url) {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Logo fetch failed: ' + response.status);
            const blob = await response.blob();
            return await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onloadend = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });
        }

        function loadImage(src) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = reject;
                img.src = src;
            });
        }

        async function exportToPdf() {
            const btn = document.getElementById('exportPdfBtn');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            btn.disabled = true;

            const webView = document.querySelector('.webView');
            const printEl = document.getElementById('printReport');
            const printLogo = document.getElementById('printLogo');
            const prHeader = printEl ? printEl.querySelector('.pr-page-header') : null;
            const originalLogoSrc = printLogo ? printLogo.getAttribute('src') : null;
            const filename = 'Application_#<?= (int) $id ?>_<?= $notFound ? "NotFound" : preg_replace('/[^A-Za-z0-9_-]/', '_', $applicantName) ?>.pdf';

            // Load + downscale the logo once so it can be stamped on every page cheaply
            let logoData = null;
            let logoW = 0,
                logoH = 15; // mm
            if (originalLogoSrc) {
                try {
                    const raw = await toDataURL(originalLogoSrc);
                    printLogo.src = raw;
                    const img = await loadImage(raw);
                    logoW = logoH * (img.naturalWidth / img.naturalHeight);

                    const targetH = 180; // 15 mm needs only ~180 px
                    const scale = Math.min(1, targetH / img.naturalHeight);
                    const c = document.createElement('canvas');
                    c.width = Math.round(img.naturalWidth * scale);
                    c.height = Math.round(img.naturalHeight * scale);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    logoData = c.toDataURL('image/png');
                } catch (err) {
                    console.warn('DMW logo could not be inlined for PDF export:', err);
                    logoData = null;
                }
            }

            if (webView) webView.style.display = 'none';
            if (printEl) printEl.style.display = 'block';
            // Letterhead is drawn on every page by jsPDF below, so hide the in-flow copy
            if (prHeader) prHeader.style.display = 'none';
            document.querySelectorAll('.no-print').forEach(el => el.style.display = 'none');

            const restore = () => {
                if (webView) webView.style.display = '';
                if (printEl) printEl.style.display = 'none';
                if (prHeader) prHeader.style.display = '';
                if (printLogo && originalLogoSrc) printLogo.src = originalLogoSrc;
                document.querySelectorAll('.no-print').forEach(el => el.style.display = '');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            };

            const HEADER_TOP_MARGIN = 34; // mm reserved for the repeating letterhead

            const opt = {
                margin: [HEADER_TOP_MARGIN, 8, 14, 8],
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                },
                pagebreak: {
                    mode: ['avoid-all', 'css', 'legacy']
                }
            };

            try {
                await html2pdf()
                    .set(opt)
                    .from(printEl || document.querySelector('.sheet'))
                    .toPdf()
                    .get('pdf')
                    .then(function(pdf) {
                        const totalPages = pdf.internal.getNumberOfPages();
                        const pageW = pdf.internal.pageSize.getWidth();
                        const pageH = pdf.internal.pageSize.getHeight();
                        const left = 8;

                        for (let i = 1; i <= totalPages; i++) {
                            pdf.setPage(i);

                            // ── Repeating letterhead (same as page 1) ──
                            let textX = left;
                            if (logoData) {
                                pdf.addImage(logoData, 'PNG', left, 7, logoW, logoH, 'dmwLogo', 'FAST');
                                textX = left + logoW + 4;
                            }
                            pdf.setFont('helvetica', 'normal');
                            pdf.setFontSize(7.5);
                            pdf.setTextColor(75, 85, 99);
                            pdf.text('Republic of the Philippines', textX, 9.5);

                            pdf.setFont('helvetica', 'bold');
                            pdf.setFontSize(11);
                            pdf.setTextColor(11, 31, 75);
                            pdf.text('DEPARTMENT OF MIGRANT WORKERS', textX, 14.5);

                            pdf.setFont('helvetica', 'normal');
                            pdf.setFontSize(7);
                            pdf.setTextColor(75, 85, 99);
                            pdf.text('Regional Office IV-A (CALABARZON)', textX, 18.5);
                            pdf.text('Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027', textX, 22);
                            pdf.text('Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph', textX, 25.5);

                            pdf.setDrawColor(11, 31, 75);
                            pdf.setLineWidth(0.8);
                            pdf.line(left, 27.5, pageW - left, 27.5);

                            // ── Footer page number ──
                            pdf.setFontSize(7.3);
                            pdf.setTextColor(150, 150, 150);
                            pdf.text(
                                `Application #<?= (int) $app['application_id'] ?> | Page ${i} of ${totalPages}`,
                                pageW / 2,
                                pageH - 6, {
                                    align: 'center'
                                }
                            );
                        }
                    })
                    .save();

                restore();
            } catch (err) {
                restore();
                alert('Failed to generate PDF. Please try again.');
            }
        }
    </script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/theme.js"></script>
</body>

</html>
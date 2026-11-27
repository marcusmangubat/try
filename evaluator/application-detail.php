<?php
//application-detail.php (EVALUATOR PORTAL)
session_start();
require_once '../db.php'; // gives us $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

/* RESOLVE THE LOGGED-IN EVALUATOR */

$evaluator_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $evaluator_email);
$stmt->execute();
$evalRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$evalRow) {
    header('Location: ../login.php');
    exit;
}

$evaluator_id   = (int) $evalRow['eid'];
$evaluator_name = $evalRow['full_name'] ?: 'Evaluator';
$parts    = explode(' ', trim($evaluator_name));
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

/* Returns Tailwind utility classes (base + color) for a status badge. */
function badgeClass(?string $status): string
{
    $base = 'inline-block px-2.5 py-[3px] rounded-full text-[11px] font-bold ';
    switch ($status) {
        case 'approved':
            return $base . 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/[.18] dark:text-emerald-400';
        case 'disapproved':
            return $base . 'bg-red-100 text-red-700 dark:bg-red-600/20 dark:text-red-400';
        case 'pending':
            return $base . 'bg-amber-100 text-amber-700 dark:bg-amber-600/20 dark:text-amber-400';
        default:
            return $base . 'bg-[#e9edf7] text-dmw-navy dark:bg-indigo-500/[.22] dark:text-indigo-300';
    }
}

function fmtDate(?string $dt, string $format = 'M j, Y g:i A'): string
{
    return $dt ? date($format, strtotime($dt)) : '—';
}

function nz(mixed $v, string $fallback = '—')
{
    return ($v === null || $v === '') ? $fallback : $v;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid application ID.');
}

/*MAIN APPLICATION + APPLICANT + JOB ORDER RECORD */

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

/* ACCESS SCOPE CHECK — an evaluator may only view an application
   they are currently assigned to, or have historically evaluated
   / been assigned to. If the application exists but this evaluator
   has no link to it, treat it exactly like "not found" so guessing
   an application ID in the URL cannot leak another evaluator's case. */
if ($app) {
    $scopeStmt = $database->prepare("
        SELECT 1 FROM applications a
        WHERE a.id = ? AND (
            a.current_evaluator_id = ?
            OR EXISTS (SELECT 1 FROM evaluations e WHERE e.application_id = a.id AND e.evaluator_id = ?)
            OR EXISTS (SELECT 1 FROM assignments asg WHERE asg.application_id = a.id AND asg.evaluator_id = ?)
        )
        LIMIT 1
    ");
    $scopeStmt->bind_param('iiii', $id, $evaluator_id, $evaluator_id, $evaluator_id);
    $scopeStmt->execute();
    $inScope = (bool) $scopeStmt->get_result()->fetch_assoc();
    $scopeStmt->close();
    if (!$inScope) {
        $app = null;
    }
}

// Default values so Intelephense (and PHP) never see these as undefined.
$applicantName        = '';
$age                  = null;
$preferredCountries   = [];
$preferredPositions   = [];
$clientSkills         = [];
$clientLicenses       = [];
$answers              = [];
$documents            = [];
$requiredCount        = 0;
$submittedCount       = 0;
$missingDocs          = [];
$completionPct        = 100;
$evaluations          = [];
$statusHistory        = [];
$assignments          = [];
$daysInProcess        = 0;
$evaluatorNames       = [];
$evaluatorCount       = 0;
$statusChangeCount    = 0;
$complianceRounds     = 0;
$isPastDeadline       = false;
$isClosed             = false;
$isCurrentEvaluator   = false;
$myEvaluationCount    = 0;

if (!$app) {
    $notFound = true;
} else {
    $notFound = false;
    $applicantName = trim(implode(' ', array_filter([$app['first_name'], $app['middle_name'], $app['last_name'], $app['suffix']])));
    $age = $app['dob'] ? floor((strtotime('today') - strtotime($app['dob'])) / 31556926) : null;

    $isCurrentEvaluator = ((int) $app['current_evaluator_id'] === $evaluator_id);

    /*  Preferred countries / positions  */
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

    /*  Document compliance  */
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

    /*  Evaluation history (full trail, not just mine — an evaluator
       needs to see prior compliance rounds and other evaluators' notes
       to make an informed decision after reassignment)  */
    $evaluations = [];
    $s = $database->prepare("
        SELECT e.id, e.evaluation_type, e.decision, e.comments, e.evaluated_at, e.evaluator_id,
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

    $myEvaluationCount = count(array_filter($evaluations, fn($e) => (int) ($e['evaluator_id'] ?? 0) === $evaluator_id));

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
        SELECT asg.id, asg.assigned_at, asg.status, ev.full_name AS evaluator_name, ev.email AS evaluator_email, ev.eid AS evaluator_id
        FROM assignments asg JOIN evaluators ev ON ev.eid = asg.evaluator_id
        WHERE asg.application_id = ? ORDER BY asg.assigned_at ASC
    ");
    $s->bind_param('i', $id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $assignments[] = $row;
    $s->close();

    /*  Derived case-summary metrics  */
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
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]'],
            theme: {
                extend: {
                    colors: {
                        'dmw-navy': 'var(--navy)',
                        'dmw-navy-soft': 'var(--navy-soft)',
                        'dmw-ok': 'var(--ok)',
                        'dmw-red': 'var(--red)',
                        'dmw-t1': 'var(--text1)',
                        'dmw-t2': 'var(--text2)',
                        'dmw-t3': 'var(--text3)',
                        'dmw-bdr': 'var(--bdr)',
                        'dmw-lbl': 'var(--lbl-bg)',
                        'dmw-surf': 'var(--surface)',
                        'dmw-surf2': 'var(--surface2)'
                    },
                    fontFamily: {
                        dmw: ['DM Sans', 'system-ui', 'sans-serif'],
                        dmwpdf: ['DM Sans', 'Arial', 'Helvetica', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <!-- Theme color tokens (consumed by the dmw-* Tailwind colors) + A4 page size. Everything else is Tailwind. -->
    <style>
        :root {
            --navy: #0b1f4b;
            --navy-soft: #13295e;
            --ok: #059669;
            --red: #b91c1c;
            --text1: #1f2937;
            --text2: #4b5563;
            --text3: #9ca3af;
            --bdr: #e5e7eb;
            --lbl-bg: #f8fafc;
            --surface: #ffffff;
            --surface2: #f8fafc;
        }

        [data-theme="dark"] {
            --navy: #3b82f6;
            --navy-soft: #1e3a8a;
            --ok: #34d399;
            --red: #f87171;
            --text1: #e2e8f0;
            --text2: #94a3b8;
            --text3: #64748b;
            --bdr: #2d3748;
            --lbl-bg: #1e293b;
            --surface: #1e293b;
            --surface2: #263449;
        }

        @page {
            size: A4;
            margin: 14mm 12mm;
        }
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body class="m-0 font-dmw bg-[#eef1f6] dark:bg-[#0f172a] text-dmw-t1">

    <header class="topbar no-print print:!hidden">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="!w-[42px] !h-auto !block !bg-transparent ![filter:none]">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; EVALUATOR PORTAL</p>
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

    <div class="sheet max-w-[1000px] mx-auto my-3 sm:my-5 px-3 sm:px-4 pb-[110px] print:!max-w-full print:!m-0 print:!p-0">

        <?php if ($notFound): ?>
            <div class="bg-dmw-surf border border-dmw-bdr rounded-[10px] text-center px-6 py-[60px]">
                <i class="fas fa-circle-exclamation text-4xl text-dmw-red mb-3"></i>
                <h2 class="flex justify-center text-dmw-navy">Application #<?= (int) $id ?> not found</h2>
                <p class="text-dmw-t2 text-[13px]">This application does not exist, or you do not have access to it. You can only view applications you currently handle or have evaluated in the past.</p>
                <a href="evaluator-reports.php" class="inline-flex items-center justify-center gap-1.5 mt-3.5 px-3.5 py-2 rounded-md border border-dmw-navy bg-dmw-navy text-white dark:bg-dmw-surf dark:text-dmw-t1 dark:border-dmw-bdr text-xs font-semibold no-underline"><i class="fas fa-arrow-left"></i> Back to My Reports</a>
            </div>
        <?php else: ?>

            <div class="toolbar-actions no-print print:!hidden flex flex-wrap gap-2 mb-4">
                <a href="evaluator-reports.php" class="inline-flex flex-1 sm:flex-none items-center justify-center gap-1.5 px-3.5 py-2 rounded-md border border-dmw-bdr bg-dmw-surf text-dmw-t1 text-xs font-semibold no-underline cursor-pointer hover:bg-dmw-surf2"><i class="fas fa-arrow-left"></i> Back to My Reports</a>
                <button class="inline-flex flex-1 sm:flex-none items-center justify-center gap-1.5 px-3.5 py-2 rounded-md border border-dmw-bdr bg-dmw-surf text-dmw-t1 text-xs font-semibold cursor-pointer hover:bg-dmw-surf2" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="inline-flex flex-1 sm:flex-none items-center justify-center gap-1.5 px-3.5 py-2 rounded-md border border-dmw-navy bg-dmw-navy text-white dark:bg-dmw-surf dark:text-dmw-t1 dark:border-dmw-bdr text-xs font-semibold cursor-pointer disabled:opacity-70 disabled:cursor-not-allowed" id="exportPdfBtn" onclick="exportToPdf()"><i class="fas fa-file-pdf"></i> Export to PDF</button>
            </div>

            <!--WEB VIEW -->

            <div class="webView print:!hidden">
                <div class="bg-dmw-surf border border-dmw-bdr rounded-[10px] p-4 sm:p-6 lg:px-[34px] lg:pt-[30px] lg:pb-9">

                    <!--  HEADER  -->
                    <div class="w-full flex items-center gap-3 sm:gap-3.5 border-b-[3px] border-dmw-navy pb-2.5 mb-1">
                        <img src="../pictures/dmw_logo.png" alt="DMW logo" class="w-10 sm:w-12 h-auto shrink-0">
                        <div class="min-w-0">
                            <p class="text-[10.5px] text-dmw-t2 m-0">Republic of the Philippines</p>
                            <p class="text-sm sm:text-base font-extrabold text-dmw-navy mt-px mb-0.5">DEPARTMENT OF MIGRANT WORKERS</p>
                            <p class="text-[10.5px] text-dmw-t2 leading-[1.5] m-0 break-words">
                                Regional Office IV-A (CALABARZON)<br>
                                Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                                Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                            </p>
                        </div>
                    </div>

                    <div class="text-center mt-[18px] mb-[3px] text-base sm:text-[17px] font-extrabold text-dmw-navy uppercase tracking-[.02em]">Application Report</div>
                    <div class="text-center text-xs text-dmw-t2 mb-4 break-words">
                        <?= htmlspecialchars($applicantName) ?> — <?= htmlspecialchars($app['position_title']) ?> at <?= htmlspecialchars($app['employer_name']) ?>
                    </div>

                    <table class="w-full mb-5 text-xs border-collapse [&_td]:py-[3px] [&_td]:align-middle [&_td:first-child]:font-bold [&_td:first-child]:w-[110px] sm:[&_td:first-child]:w-40 [&_td:first-child]:text-dmw-t1">
                        <tr>
                            <td>Application No.:</td>
                            <td>#<?= (int) $app['application_id'] ?></td>
                        </tr>
                        <tr>
                            <td>RSF Number:</td>
                            <td class="break-words"><?= htmlspecialchars($app['rsf_number']) ?></td>
                        </tr>
                        <tr>
                            <td>Current Status:</td>
                            <td><span class="<?= badgeClass($app['status']) ?>"><?= statusLabel($app['status']) ?></span></td>
                        </tr>
                    </table>

                    <?php if ($isCurrentEvaluator): ?>
                        <div class="text-xs font-semibold px-3 py-[9px] rounded-md mb-[18px] flex items-start sm:items-center gap-2 bg-emerald-100 border border-emerald-200 text-emerald-700 dark:bg-emerald-500/15 dark:border-emerald-400/35 dark:text-emerald-400">
                            <i class="fas fa-user-check mt-0.5 sm:mt-0"></i>
                            <span>You are the <strong>current evaluator</strong> assigned to this application.
                                <?php if ($myEvaluationCount > 0): ?>&nbsp;You have logged <?= $myEvaluationCount ?> evaluation entr<?= $myEvaluationCount === 1 ? 'y' : 'ies' ?> on this case.<?php endif; ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-xs font-semibold px-3 py-[9px] rounded-md mb-[18px] flex items-start sm:items-center gap-2 bg-[#e9edf7] border border-indigo-200 text-dmw-navy dark:bg-dmw-surf2 dark:border-dmw-bdr">
                            <i class="fas fa-clock-rotate-left mt-0.5 sm:mt-0"></i>
                            <span>This application is no longer assigned to you — it is currently with
                                <strong><?= htmlspecialchars(nz($app['evaluator_name'], 'no evaluator')) ?></strong>.
                                You have <?= $myEvaluationCount ?> past evaluation entr<?= $myEvaluationCount === 1 ? 'y' : 'ies' ?> on record for this case.</span>
                        </div>
                    <?php endif; ?>

                    <p class="text-[12.5px] leading-[1.65] text-dmw-t2 text-left sm:text-justify mb-[18px]">
                        This report presents the complete case record of <strong><?= htmlspecialchars($applicantName) ?></strong>'s
                        application for <strong><?= htmlspecialchars($app['position_title']) ?></strong> at
                        <strong><?= htmlspecialchars($app['employer_name']) ?></strong> (RSF <?= htmlspecialchars($app['rsf_number']) ?>),
                        as processed through the DMW Application Management System. It consolidates the applicant's
                        profile, job order details, submitted answers and documents, and the full evaluation, status,
                        and assignment trail, giving you a single reference for evaluating this case or preparing
                        follow-up action.
                    </p>

                    <?php if ($isPastDeadline): ?>
                        <div class="text-xs font-semibold px-3 py-[9px] rounded-md mb-[18px] flex items-start gap-2 bg-amber-100 border border-amber-200 text-amber-800 dark:bg-amber-600/15 dark:border-amber-400/35 dark:text-amber-400">
                            <i class="fas fa-triangle-exclamation mt-0.5"></i>
                            <span>This application is still <?= strtolower(statusLabel($app['status'])) ?> and the job order deadline
                                (<?= htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) ?>) has already passed. Prioritize review or escalate to the admin.</span>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 sm:grid-cols-[repeat(auto-fit,minmax(150px,1fr))] border border-dmw-bdr rounded-md overflow-hidden mb-[18px] [&>div]:px-3.5 [&>div]:py-3 [&>div]:border-r [&>div]:border-b [&>div]:border-dmw-bdr [&>div]:bg-dmw-surf2 [&>div:last-child]:border-r-0">
                        <div>
                            <div class="text-xl font-extrabold text-dmw-navy leading-[1.1]"><?= $daysInProcess ?></div>
                            <div class="text-[10px] font-semibold text-dmw-t2 uppercase tracking-[.03em] mt-[3px]"><?= $isClosed ? 'Days to Close' : 'Days in Process' ?></div>
                        </div>
                        <div>
                            <div class="text-xl font-extrabold text-dmw-navy leading-[1.1]"><?= $statusChangeCount ?></div>
                            <div class="text-[10px] font-semibold text-dmw-t2 uppercase tracking-[.03em] mt-[3px]">Status Changes</div>
                        </div>
                        <div>
                            <div class="text-xl font-extrabold text-dmw-navy leading-[1.1]"><?= $evaluatorCount ?></div>
                            <div class="text-[10px] font-semibold text-dmw-t2 uppercase tracking-[.03em] mt-[3px]">Evaluator(s) Involved</div>
                        </div>
                        <div>
                            <div class="text-xl font-extrabold leading-[1.1] <?= $complianceRounds > 2 ? 'text-dmw-red' : 'text-dmw-navy' ?>"><?= $complianceRounds ?></div>
                            <div class="text-[10px] font-semibold text-dmw-t2 uppercase tracking-[.03em] mt-[3px]">Compliance Rounds</div>
                        </div>
                        <div>
                            <div class="text-xl font-extrabold leading-[1.1] <?= $completionPct < 100 ? 'text-dmw-red' : 'text-dmw-navy' ?>"><?= $completionPct ?>%</div>
                            <div class="text-[10px] font-semibold text-dmw-t2 uppercase tracking-[.03em] mt-[3px]">Docs Complete</div>
                        </div>
                    </div>

                    <!-- APPLICANT PROFILE  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-user"></i> I. Applicant Profile</div>
                        <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Personal, educational, and preference details as declared by the applicant. Cross-check against submitted documents below.</p>
                        <table class="w-full border-collapse border border-dmw-bdr mb-1 [&_td]:border [&_td]:border-dmw-bdr [&_td]:px-3 [&_td]:py-[7px] [&_td]:align-top [&_td]:text-[12.5px] [&_td]:text-dmw-t1 [&_td]:font-medium [&_td]:break-words [&_td:first-child]:w-[30%] [&_td:first-child]:bg-dmw-lbl [&_td:first-child]:font-bold [&_td:first-child]:text-[11px] max-sm:block max-sm:[&_tbody]:block max-sm:[&_tr]:block max-sm:[&_td]:block max-sm:[&_td:first-child]:w-full">
                            <tr>
                                <td>Full Name</td>
                                <td><?= htmlspecialchars($applicantName) ?></td>
                            </tr>
                            <tr>
                                <td>Email</td>
                                <td><?= htmlspecialchars($app['applicant_email']) ?></td>
                            </tr>
                            <tr>
                                <td>Phone</td>
                                <td><?= htmlspecialchars(nz($app['phone'])) ?></td>
                            </tr>
                            <tr>
                                <td>Date of Birth / Age</td>
                                <td><?= $app['dob'] ? htmlspecialchars(date('M j, Y', strtotime($app['dob']))) . ' (' . $age . ' yrs)' : '—' ?></td>
                            </tr>
                            <tr>
                                <td>Gender</td>
                                <td><?= htmlspecialchars(nz($app['gender'])) ?></td>
                            </tr>
                            <tr>
                                <td>Civil Status</td>
                                <td><?= htmlspecialchars(nz($app['civil_status'])) ?></td>
                            </tr>
                            <tr>
                                <td>Citizenship</td>
                                <td><?= htmlspecialchars(nz($app['citizenship'])) ?></td>
                            </tr>
                            <tr>
                                <td>Religion</td>
                                <td><?= htmlspecialchars(nz($app['religion'])) ?></td>
                            </tr>
                            <tr>
                                <td>Height / Weight</td>
                                <td><?= $app['height_cm'] ? htmlspecialchars($app['height_cm']) . ' cm' : '—' ?> / <?= $app['weight_kg'] ? htmlspecialchars($app['weight_kg']) . ' kg' : '—' ?></td>
                            </tr>
                            <tr>
                                <td>Address</td>
                                <td><?= htmlspecialchars(implode(', ', array_filter([$app['address'], $app['barangay'], $app['city_municipality'], $app['province']]))) ?></td>
                            </tr>
                            <tr>
                                <td>Educational Attainment</td>
                                <td><?= htmlspecialchars(nz($app['educational_attainment'])) ?></td>
                            </tr>
                            <tr>
                                <td>Degree / Course</td>
                                <td><?= htmlspecialchars(nz($app['degree_name'] ?: $app['course_degree'])) ?></td>
                            </tr>
                            <tr>
                                <td>School</td>
                                <td><?= htmlspecialchars(nz($app['school_name'])) ?></td>
                            </tr>
                            <tr>
                                <td>Year Graduated</td>
                                <td><?= htmlspecialchars(nz($app['year_graduated'])) ?></td>
                            </tr>
                            <tr>
                                <td>Work Experience</td>
                                <td><?= $app['work_experience_years'] !== null ? htmlspecialchars($app['work_experience_years']) . ' yr(s)' : '—' ?></td>
                            </tr>
                            <tr>
                                <td>Last Employer / Position</td>
                                <td><?= htmlspecialchars(nz($app['last_employer'])) ?> <?= $app['last_position'] ? '— ' . htmlspecialchars($app['last_position']) : '' ?></td>
                            </tr>
                            <tr>
                                <td>Profile Completed</td>
                                <td><?= $app['profile_completed_at'] ? fmtDate($app['profile_completed_at'], 'M j, Y') : 'Not completed' ?></td>
                            </tr>
                            <tr>
                                <td>Preferred Countries</td>
                                <td>
                                    <?php if ($preferredCountries): ?>
                                        <?php foreach ($preferredCountries as $pc): ?>
                                            <span class="inline-block px-2.5 py-[3px] rounded-full text-[11px] font-semibold bg-dmw-surf2 border border-dmw-bdr my-0.5 mr-1">#<?= (int) $pc['priority_order'] ?> <?= htmlspecialchars($pc['country']) ?></span>
                                        <?php endforeach; ?>
                                        <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Preferred Positions</td>
                                <td>
                                    <?php if ($preferredPositions): ?>
                                        <?php foreach ($preferredPositions as $pp): ?>
                                            <span class="inline-block px-2.5 py-[3px] rounded-full text-[11px] font-semibold bg-dmw-surf2 border border-dmw-bdr my-0.5 mr-1">#<?= (int) $pp['priority_order'] ?> <?= htmlspecialchars($pp['subcategory_name']) ?></span>
                                        <?php endforeach; ?>
                                        <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($clientSkills): ?>
                                <tr>
                                    <td>Skills</td>
                                    <td>
                                        <?php foreach ($clientSkills as $sk): ?>
                                            <span class="inline-block px-2.5 py-[3px] rounded-full text-[11px] font-semibold bg-dmw-surf2 border border-dmw-bdr my-0.5 mr-1"><?= htmlspecialchars($sk['skill_name']) ?> (<?= htmlspecialchars($sk['proficiency_level']) ?>)</span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($clientLicenses): ?>
                                <tr>
                                    <td>Professional Licenses</td>
                                    <td>
                                        <?php foreach ($clientLicenses as $lic): ?>
                                            <span class="inline-block px-2.5 py-[3px] rounded-full text-[11px] font-semibold bg-dmw-surf2 border border-dmw-bdr my-0.5 mr-1"><?= htmlspecialchars($lic['license_name']) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!--  APPLICATION / JOB ORDER INFO  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-briefcase"></i> II. Application &amp; Job Order Information</div>
                        <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Recruitment slot the applicant is being matched against — verify qualifications, salary, and deadline align with the applicant's profile.</p>
                        <table class="w-full border-collapse border border-dmw-bdr mb-1 [&_td]:border [&_td]:border-dmw-bdr [&_td]:px-3 [&_td]:py-[7px] [&_td]:align-top [&_td]:text-[12.5px] [&_td]:text-dmw-t1 [&_td]:font-medium [&_td]:break-words [&_td:first-child]:w-[30%] [&_td:first-child]:bg-dmw-lbl [&_td:first-child]:font-bold [&_td:first-child]:text-[11px] max-sm:block max-sm:[&_tbody]:block max-sm:[&_tr]:block max-sm:[&_td]:block max-sm:[&_td:first-child]:w-full">
                            <tr>
                                <td>RSF Number</td>
                                <td><?= htmlspecialchars($app['rsf_number']) ?></td>
                            </tr>
                            <tr>
                                <td>Position</td>
                                <td><?= htmlspecialchars($app['position_title']) ?></td>
                            </tr>
                            <tr>
                                <td>Category / Subcategory</td>
                                <td><?= htmlspecialchars(nz($app['category_name'])) ?> / <?= htmlspecialchars(nz($app['subcategory_name'])) ?></td>
                            </tr>
                            <tr>
                                <td>Employer</td>
                                <td><?= htmlspecialchars($app['employer_name']) ?></td>
                            </tr>
                            <tr>
                                <td>Worksite Country</td>
                                <td><?= htmlspecialchars(nz($app['worksite_country'])) ?></td>
                            </tr>
                            <tr>
                                <td>Classification</td>
                                <td><?= htmlspecialchars(nz($app['classification'])) ?></td>
                            </tr>
                            <tr>
                                <td>Slots Available</td>
                                <td><?= htmlspecialchars(nz($app['slots_available'])) ?></td>
                            </tr>
                            <tr>
                                <td>Salary Range</td>
                                <td><?= ($app['salary_min'] !== null && $app['salary_max'] !== null) ? htmlspecialchars(number_format($app['salary_min'], 2) . ' – ' . number_format($app['salary_max'], 2) . ' ' . ($app['salary_currency'] ?: 'PHP')) : '—' ?></td>
                            </tr>
                            <tr>
                                <td>Job Order Deadline</td>
                                <td><?= $app['job_deadline'] ? htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) : '—' ?></td>
                            </tr>
                            <tr>
                                <td>Handling Party</td>
                                <td><?= htmlspecialchars(nz($app['handling_party'] ? ucfirst($app['handling_party']) : null)) ?></td>
                            </tr>
                            <tr>
                                <td>Current Evaluator</td>
                                <td>
                                    <?= htmlspecialchars(nz($app['evaluator_name'])) ?>
                                    <?php if ($isCurrentEvaluator): ?><span class="inline-block px-[7px] py-px rounded-full text-[9.5px] font-bold bg-dmw-navy text-white dark:text-[#0f172a] ml-1.5">YOU</span><?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Locked At</td>
                                <td><?= fmtDate($app['locked_at']) ?></td>
                            </tr>
                            <tr>
                                <td>Submitted At</td>
                                <td><?= fmtDate($app['submitted_at']) ?></td>
                            </tr>
                            <tr>
                                <td>Last Updated</td>
                                <td><?= fmtDate($app['updated_at']) ?></td>
                            </tr>
                            <?php if ($app['qualifications']): ?>
                                <tr>
                                    <td>Job Qualifications</td>
                                    <td><?= nl2br(htmlspecialchars($app['qualifications'])) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($app['admin_notes']): ?>
                                <tr>
                                    <td>Admin Notes</td>
                                    <td><?= nl2br(htmlspecialchars($app['admin_notes'])) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!--  APPLICATION ANSWERS  -->
                    <?php if ($answers): ?>
                        <div class="mb-[22px]">
                            <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-circle-question"></i> III. Application Answers</div>
                            <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Screening questions specific to this job order, answered at the time of submission.</p>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[520px] border-collapse text-xs border border-dmw-bdr [&_th]:bg-dmw-lbl [&_th]:text-left [&_th]:px-2.5 [&_th]:py-2 [&_th]:font-bold [&_th]:text-[10.5px] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-dmw-t2 [&_th]:border [&_th]:border-dmw-bdr [&_td]:px-2.5 [&_td]:py-2 [&_td]:border [&_td]:border-dmw-bdr [&_td]:align-top [&_td]:text-dmw-t1 [&_tbody_tr:nth-child(even)]:bg-[#fbfcfe] dark:[&_tbody_tr:nth-child(even)]:bg-[#202f45]">
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
                        </div>
                    <?php endif; ?>

                    <!--  DOCUMENTS  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-folder-open"></i> IV. Documents &amp; Comments</div>
                        <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Files uploaded against this job order's requirements, with any reviewer remarks attached to each file.</p>
                        <p class="text-xs text-dmw-t2 mb-3.5">
                            Compliance: <b><?= $submittedCount ?> / <?= $requiredCount ?></b> required documents submitted
                            (<span class="<?= badgeClass($completionPct == 100 ? 'approved' : 'disapproved') ?>"><?= $completionPct ?>%</span>)
                            <?php if ($missingDocs): ?>&nbsp;•&nbsp; Missing: <b><?= htmlspecialchars(implode(', ', $missingDocs)) ?></b><?php endif; ?>
                        </p>

                        <?php if (!$documents): ?>
                            <p class="text-xs text-dmw-t3 dark:text-dmw-t2 italic py-2.5">No documents uploaded for this application.</p>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                                <div class="border border-dmw-bdr rounded-md px-3.5 py-3 mb-2.5 bg-dmw-surf2">
                                    <div class="flex justify-between items-center flex-wrap gap-2">
                                        <div class="min-w-0">
                                            <div class="font-bold text-[12.5px] break-words"><?= htmlspecialchars($doc['document_name'] ?: ucfirst($doc['document_type'])) ?></div>
                                            <div class="text-[11px] text-dmw-t3 break-all"><?= htmlspecialchars($doc['file_name']) ?> &nbsp;•&nbsp; Uploaded <?= fmtDate($doc['uploaded_at']) ?></div>
                                        </div>
                                        <?php if ($doc['is_required']): ?><span class="<?= badgeClass('other') ?>">Required</span><?php endif; ?>
                                    </div>
                                    <?php foreach ($doc['comments'] as $c): ?>
                                        <div class="bg-dmw-surf border border-dmw-bdr rounded-md px-2.5 py-2 mt-2 text-xs text-dmw-t1 break-words">
                                            <div class="text-[10.5px] text-dmw-t3 mb-[3px]"><?= htmlspecialchars($c['commented_by']) ?> — <?= fmtDate($c['created_at']) ?></div>
                                            <?= htmlspecialchars($c['comment']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!--  EVALUATION HISTORY  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-clipboard-check"></i> V. Evaluation History</div>
                        <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Every decision recorded against this application, in chronological order. Entries you authored are marked <span class="inline-block px-[7px] py-px rounded-full text-[9.5px] font-bold bg-dmw-navy text-white dark:text-[#0f172a]">YOU</span>.</p>
                        <?php if (!$evaluations): ?>
                            <p class="text-xs text-dmw-t3 dark:text-dmw-t2 italic py-2.5">No evaluations recorded yet.</p>
                        <?php else: ?>
                            <div class="border-l-2 border-dmw-bdr ml-1.5 pl-[18px]">
                                <?php foreach ($evaluations as $e): ?>
                                    <?php $isMine = (int) ($e['evaluator_id'] ?? 0) === $evaluator_id; ?>
                                    <div class="relative pb-4 before:content-[''] before:absolute before:-left-[23px] before:top-[3px] before:w-[9px] before:h-[9px] before:rounded-full <?= $isMine ? 'before:bg-dmw-ok' : 'before:bg-dmw-navy' ?>">
                                        <div class="text-[12.5px] font-bold text-dmw-t1">
                                            <?= htmlspecialchars(ucfirst($e['evaluation_type'] ?? 'evaluation')) ?> —
                                            <span class="<?= badgeClass($e['decision']) ?>"><?= statusLabel($e['decision'] ?? '—') ?></span>
                                            by <?= htmlspecialchars($e['evaluator_name'] ?: $e['admin_name'] ?: 'System') ?>
                                            <?php if ($isMine): ?><span class="inline-block px-[7px] py-px rounded-full text-[9.5px] font-bold bg-dmw-navy text-white dark:text-[#0f172a] ml-1.5">YOU</span><?php endif; ?>
                                        </div>
                                        <div class="text-[10.5px] text-dmw-t3 dark:text-dmw-t2"><?= fmtDate($e['evaluated_at']) ?></div>
                                        <?php if ($e['comments']): ?><div class="text-xs text-dmw-t2 mt-0.5 break-words"><?= htmlspecialchars($e['comments']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!--  STATUS HISTORY  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-timeline"></i> VI. Status History</div>
                        <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Full audit trail of status transitions — useful for tracing delays or repeated compliance cycles.</p>
                        <?php if (!$statusHistory): ?>
                            <p class="text-xs text-dmw-t3 dark:text-dmw-t2 italic py-2.5">No status changes recorded yet.</p>
                        <?php else: ?>
                            <div class="border-l-2 border-dmw-bdr ml-1.5 pl-[18px]">
                                <?php foreach ($statusHistory as $h): ?>
                                    <div class="relative pb-4 before:content-[''] before:absolute before:-left-[23px] before:top-[3px] before:w-[9px] before:h-[9px] before:rounded-full before:bg-dmw-navy">
                                        <div class="text-[12.5px] font-bold text-dmw-t1"><?= statusLabel($h['from_status'] ?? '—') ?> → <?= statusLabel($h['to_status'] ?? '—') ?></div>
                                        <div class="text-[10.5px] text-dmw-t3 dark:text-dmw-t2 break-words">By <?= htmlspecialchars($h['changed_by'] ?: 'System') ?> — <?= fmtDate($h['changed_at']) ?></div>
                                        <?php if ($h['reason']): ?><div class="text-xs text-dmw-t2 mt-0.5 break-words"><?= htmlspecialchars($h['reason']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!--  EVALUATOR ASSIGNMENTS  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-user-tie"></i> VII. Evaluator Assignments</div>
                        <p class="text-[11.5px] text-dmw-t3 dark:text-dmw-t2 italic -mt-1 mb-2.5">Which evaluator(s) have handled this case and when — helps confirm accountability and reassignment history.</p>
                        <?php if (!$assignments): ?>
                            <p class="text-xs text-dmw-t3 dark:text-dmw-t2 italic py-2.5">No evaluator has been assigned to this application.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[520px] border-collapse text-xs border border-dmw-bdr [&_th]:bg-dmw-lbl [&_th]:text-left [&_th]:px-2.5 [&_th]:py-2 [&_th]:font-bold [&_th]:text-[10.5px] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-dmw-t2 [&_th]:border [&_th]:border-dmw-bdr [&_td]:px-2.5 [&_td]:py-2 [&_td]:border [&_td]:border-dmw-bdr [&_td]:align-top [&_td]:text-dmw-t1 [&_tbody_tr:nth-child(even)]:bg-[#fbfcfe] dark:[&_tbody_tr:nth-child(even)]:bg-[#202f45]">
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
                                                <td>
                                                    <?= htmlspecialchars($asg['evaluator_name']) ?>
                                                    <?php if ((int) $asg['evaluator_id'] === $evaluator_id): ?><span class="inline-block px-[7px] py-px rounded-full text-[9.5px] font-bold bg-dmw-navy text-white dark:text-[#0f172a] ml-1.5">YOU</span><?php endif; ?>
                                                </td>
                                                <td class="break-all"><?= htmlspecialchars($asg['evaluator_email']) ?></td>
                                                <td><?= fmtDate($asg['assigned_at']) ?></td>
                                                <td><span class="<?= badgeClass('other') ?>"><?= htmlspecialchars(ucfirst($asg['status'])) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!--  NOTES  -->
                    <div class="mb-[22px]">
                        <div class="flex items-center gap-2 bg-dmw-navy text-white dark:bg-dmw-navy-soft dark:text-slate-50 text-xs font-bold px-3 py-[7px] mb-2.5 uppercase tracking-[.04em] rounded-[3px]"><i class="fas fa-lightbulb"></i> VIII. Notes for Evaluation</div>
                        <ul class="text-[12.5px] text-dmw-t2 leading-[1.8] m-0 pl-[18px]">
                            <?php if ($missingDocs): ?>
                                <li><strong>Missing documents:</strong> <?= htmlspecialchars(implode(', ', $missingDocs)) ?> — flag for compliance before approving.</li>
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
                                <li>The job order deadline has passed while this application remains <?= strtolower(statusLabel($app['status'])) ?> — flag for priority handling or escalate to the admin.</li>
                            <?php endif; ?>
                            <?php if ($isClosed): ?>
                                <li>This case is closed (<?= strtolower(statusLabel($app['status'])) ?>) after <?= $daysInProcess ?> day(s) of processing. No further action is required unless reopened.</li>
                            <?php else: ?>
                                <li>This case has been in process for <?= $daysInProcess ?> day(s) and is currently <?= strtolower(statusLabel($app['status'])) ?>.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <p class="text-[10px] text-dmw-t3 dark:text-dmw-t2 text-center border-t border-dmw-bdr pt-2.5 mt-6">Department of Migrant Workers — Application Management System &nbsp;|&nbsp; Application #<?= (int) $app['application_id'] ?> &nbsp;|&nbsp; For official use only</p>

                </div><!-- /doc sheet -->
            </div><!-- /.webView -->

            <!-- PRINT / PDF REPORT -->
            <div id="printReport" class="hidden print:!block bg-white text-[#1f2937] font-dmwpdf w-full max-w-[190mm] mx-auto p-0">

                <div class="flex items-center gap-3.5 border-b-[3px] border-[#0b1f4b] pb-2.5 mb-1.5">
                    <img id="printLogo" class="h-[42px] w-auto" src="../pictures/dmw_logo.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
                    <div>
                        <div class="text-[8.5pt] text-[#4b5563] mb-px">Republic of the Philippines</div>
                        <div class="text-[12.5pt] font-extrabold text-[#0b1f4b] tracking-[.01em]">DEPARTMENT OF MIGRANT WORKERS</div>
                        <div class="text-[8.7pt] text-[#4b5563] mt-0.5 leading-[1.5]">
                            Regional Office IV-A (CALABARZON)<br>
                            Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                            Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3.5 mb-1.5">
                    <h1 class="font-extrabold text-[14pt] m-0 text-[#0b1f4b] uppercase tracking-[.02em]">Application Report</h1>
                    <p class="text-[9.5pt] text-[#6b7280] mt-[3px] mb-0">Application #<?= (int) $app['application_id'] ?> — <?= htmlspecialchars($applicantName) ?></p>
                </div>

                <div class="flex justify-between items-center text-[8.5pt] text-[#4b5563] border-b border-[#e5e7eb] pb-2 mb-4">
                    <span>Generated: <?= date('M j, Y g:i A') ?></span>
                    <span>Status: <span class="font-bold px-[9px] py-0.5 rounded border border-[#9ca3af] text-[#1f2937]"><?= statusLabel($app['status']) ?></span></span>
                    <span>RSF <?= htmlspecialchars($app['rsf_number']) ?></span>
                </div>

                <div class="text-[8.7pt] font-bold px-2.5 py-1.5 rounded mb-3 bg-[#e9edf7] text-[#0b1f4b]">
                    <?php if ($isCurrentEvaluator): ?>
                        You are the current evaluator assigned to this application.
                    <?php else: ?>
                        Currently assigned to <?= htmlspecialchars(nz($app['evaluator_name'], 'no evaluator')) ?> — you have <?= $myEvaluationCount ?> past evaluation entr<?= $myEvaluationCount === 1 ? 'y' : 'ies' ?> on record.
                    <?php endif; ?>
                </div>

                <p class="text-[9pt] leading-[1.6] text-[#374151] text-justify mb-3">
                    This report presents the complete case record of <strong><?= htmlspecialchars($applicantName) ?></strong>'s
                    application for <strong><?= htmlspecialchars($app['position_title']) ?></strong> at
                    <strong><?= htmlspecialchars($app['employer_name']) ?></strong> (RSF <?= htmlspecialchars($app['rsf_number']) ?>),
                    as processed through the DMW Application Management System. It consolidates the applicant's profile,
                    job order details, submitted answers and documents, and the full evaluation, status, and assignment
                    trail for reference during evaluation, audit, or compliance follow-up.
                </p>

                <?php if ($isPastDeadline): ?>
                    <div class="bg-[#fef3c7] border border-[#fde68a] text-[#92400e] text-[8.7pt] font-bold px-2.5 py-1.5 rounded mb-3">
                        <i class="fas fa-triangle-exclamation"></i>
                        Job order deadline (<?= htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) ?>) has passed while this application remains <?= strtolower(statusLabel($app['status'])) ?>.
                    </div>
                <?php endif; ?>

                <table class="w-full border-collapse mb-3.5 table-fixed [&_td]:border [&_td]:border-[#e5e7eb] [&_td]:bg-[#f8fafc] [&_td]:text-center [&_td]:px-1 [&_td]:py-2">
                    <tr>
                        <td>
                            <span class="block text-[13pt] font-extrabold text-[#0b1f4b]"><?= $daysInProcess ?></span>
                            <span class="block text-[6.7pt] font-bold text-[#4b5563] uppercase tracking-[.02em] mt-0.5"><?= $isClosed ? 'Days to Close' : 'Days in Process' ?></span>
                        </td>
                        <td>
                            <span class="block text-[13pt] font-extrabold text-[#0b1f4b]"><?= $statusChangeCount ?></span>
                            <span class="block text-[6.7pt] font-bold text-[#4b5563] uppercase tracking-[.02em] mt-0.5">Status Changes</span>
                        </td>
                        <td>
                            <span class="block text-[13pt] font-extrabold text-[#0b1f4b]"><?= $evaluatorCount ?></span>
                            <span class="block text-[6.7pt] font-bold text-[#4b5563] uppercase tracking-[.02em] mt-0.5">Evaluator(s)</span>
                        </td>
                        <td>
                            <span class="block text-[13pt] font-extrabold text-[#0b1f4b]"><?= $complianceRounds ?></span>
                            <span class="block text-[6.7pt] font-bold text-[#4b5563] uppercase tracking-[.02em] mt-0.5">Compliance Rounds</span>
                        </td>
                        <td>
                            <span class="block text-[13pt] font-extrabold text-[#0b1f4b]"><?= $completionPct ?>%</span>
                            <span class="block text-[6.7pt] font-bold text-[#4b5563] uppercase tracking-[.02em] mt-0.5">Docs Complete</span>
                        </td>
                    </tr>
                </table>

                <!-- Applicant Profile -->
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">I. Applicant Profile</div>
                    <p class="text-[7.8pt] text-[#9ca3af] italic -mt-[5px] mb-1.5">Personal, educational, and preference details as declared by the applicant.</p>
                    <table class="w-full border-collapse mb-1 [&_td]:text-[9pt] [&_td]:px-1.5 [&_td]:py-1 [&_td]:align-top [&_td]:border-b [&_td]:border-dotted [&_td]:border-[#e5e7eb] [&_td:nth-child(odd)]:font-bold [&_td:nth-child(odd)]:text-[#4b5563] [&_td:nth-child(odd)]:whitespace-nowrap [&_td:nth-child(odd)]:w-[16%]">
                        <tr>
                            <td>Full Name</td>
                            <td><?= htmlspecialchars($applicantName) ?></td>
                            <td>Email</td>
                            <td><?= htmlspecialchars($app['applicant_email']) ?></td>
                        </tr>
                        <tr>
                            <td>Phone</td>
                            <td><?= htmlspecialchars(nz($app['phone'])) ?></td>
                            <td>DOB / Age</td>
                            <td><?= $app['dob'] ? htmlspecialchars(date('M j, Y', strtotime($app['dob']))) . ' (' . $age . ' yrs)' : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Gender</td>
                            <td><?= htmlspecialchars(nz($app['gender'])) ?></td>
                            <td>Civil Status</td>
                            <td><?= htmlspecialchars(nz($app['civil_status'])) ?></td>
                        </tr>
                        <tr>
                            <td>Citizenship</td>
                            <td><?= htmlspecialchars(nz($app['citizenship'])) ?></td>
                            <td>Religion</td>
                            <td><?= htmlspecialchars(nz($app['religion'])) ?></td>
                        </tr>
                        <tr>
                            <td>Height / Weight</td>
                            <td><?= $app['height_cm'] ? htmlspecialchars($app['height_cm']) . ' cm' : '—' ?> / <?= $app['weight_kg'] ? htmlspecialchars($app['weight_kg']) . ' kg' : '—' ?></td>
                            <td>Address</td>
                            <td><?= htmlspecialchars(implode(', ', array_filter([$app['address'], $app['barangay'], $app['city_municipality'], $app['province']]))) ?></td>
                        </tr>
                        <tr>
                            <td>Education</td>
                            <td><?= htmlspecialchars(nz($app['educational_attainment'])) ?></td>
                            <td>Degree / Course</td>
                            <td><?= htmlspecialchars(nz($app['degree_name'] ?: $app['course_degree'])) ?></td>
                        </tr>
                        <tr>
                            <td>School</td>
                            <td><?= htmlspecialchars(nz($app['school_name'])) ?></td>
                            <td>Year Graduated</td>
                            <td><?= htmlspecialchars(nz($app['year_graduated'])) ?></td>
                        </tr>
                        <tr>
                            <td>Work Experience</td>
                            <td><?= $app['work_experience_years'] !== null ? htmlspecialchars($app['work_experience_years']) . ' yr(s)' : '—' ?></td>
                            <td>Last Employer / Position</td>
                            <td><?= htmlspecialchars(nz($app['last_employer'])) ?><?= $app['last_position'] ? ' — ' . htmlspecialchars($app['last_position']) : '' ?></td>
                        </tr>
                    </table>

                    <?php if ($preferredCountries || $preferredPositions): ?>
                        <table class="w-full border-collapse mb-1 [&_td]:text-[9pt] [&_td]:px-1.5 [&_td]:py-1 [&_td]:align-top [&_td]:border-b [&_td]:border-dotted [&_td]:border-[#e5e7eb] [&_td:nth-child(odd)]:font-bold [&_td:nth-child(odd)]:text-[#4b5563] [&_td:nth-child(odd)]:whitespace-nowrap [&_td:nth-child(odd)]:w-[16%]">
                            <tr>
                                <td>Pref. Countries</td>
                                <td colspan="3"><?= $preferredCountries ? htmlspecialchars(implode(', ', array_map(fn($pc) => '#' . $pc['priority_order'] . ' ' . $pc['country'], $preferredCountries))) : '—' ?></td>
                            </tr>
                            <tr>
                                <td>Pref. Positions</td>
                                <td colspan="3"><?= $preferredPositions ? htmlspecialchars(implode(', ', array_map(fn($pp) => '#' . $pp['priority_order'] . ' ' . $pp['subcategory_name'], $preferredPositions))) : '—' ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>

                    <?php if ($clientSkills || $clientLicenses): ?>
                        <table class="w-full border-collapse mb-1 [&_td]:text-[9pt] [&_td]:px-1.5 [&_td]:py-1 [&_td]:align-top [&_td]:border-b [&_td]:border-dotted [&_td]:border-[#e5e7eb] [&_td:nth-child(odd)]:font-bold [&_td:nth-child(odd)]:text-[#4b5563] [&_td:nth-child(odd)]:whitespace-nowrap [&_td:nth-child(odd)]:w-[16%]">
                            <?php if ($clientSkills): ?>
                                <tr>
                                    <td>Skills</td>
                                    <td colspan="3"><?= htmlspecialchars(implode(', ', array_map(fn($sk) => $sk['skill_name'] . ' (' . $sk['proficiency_level'] . ')', $clientSkills))) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($clientLicenses): ?>
                                <tr>
                                    <td>Licenses</td>
                                    <td colspan="3"><?= htmlspecialchars(implode(', ', array_column($clientLicenses, 'license_name'))) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Application & Job Order -->
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">II. Application &amp; Job Order Information</div>
                    <p class="text-[7.8pt] text-[#9ca3af] italic -mt-[5px] mb-1.5">Recruitment slot the applicant is being matched against.</p>
                    <table class="w-full border-collapse mb-1 [&_td]:text-[9pt] [&_td]:px-1.5 [&_td]:py-1 [&_td]:align-top [&_td]:border-b [&_td]:border-dotted [&_td]:border-[#e5e7eb] [&_td:nth-child(odd)]:font-bold [&_td:nth-child(odd)]:text-[#4b5563] [&_td:nth-child(odd)]:whitespace-nowrap [&_td:nth-child(odd)]:w-[16%]">
                        <tr>
                            <td>RSF Number</td>
                            <td><?= htmlspecialchars($app['rsf_number']) ?></td>
                            <td>Position</td>
                            <td><?= htmlspecialchars($app['position_title']) ?></td>
                        </tr>
                        <tr>
                            <td>Category</td>
                            <td><?= htmlspecialchars(nz($app['category_name'])) ?> / <?= htmlspecialchars(nz($app['subcategory_name'])) ?></td>
                            <td>Employer</td>
                            <td><?= htmlspecialchars($app['employer_name']) ?></td>
                        </tr>
                        <tr>
                            <td>Worksite Country</td>
                            <td><?= htmlspecialchars(nz($app['worksite_country'])) ?></td>
                            <td>Classification</td>
                            <td><?= htmlspecialchars(nz($app['classification'])) ?></td>
                        </tr>
                        <tr>
                            <td>Slots Available</td>
                            <td><?= htmlspecialchars(nz($app['slots_available'])) ?></td>
                            <td>Salary Range</td>
                            <td><?= ($app['salary_min'] !== null && $app['salary_max'] !== null) ? htmlspecialchars(number_format($app['salary_min'], 2) . ' – ' . number_format($app['salary_max'], 2) . ' ' . ($app['salary_currency'] ?: 'PHP')) : '—' ?></td>
                        </tr>
                        <tr>
                            <td>Job Deadline</td>
                            <td><?= $app['job_deadline'] ? htmlspecialchars(date('M j, Y', strtotime($app['job_deadline']))) : '—' ?></td>
                            <td>Handling Party</td>
                            <td><?= htmlspecialchars(nz($app['handling_party'] ? ucfirst($app['handling_party']) : null)) ?></td>
                        </tr>
                        <tr>
                            <td>Current Evaluator</td>
                            <td><?= htmlspecialchars(nz($app['evaluator_name'])) ?></td>
                            <td>Submitted At</td>
                            <td><?= fmtDate($app['submitted_at']) ?></td>
                        </tr>
                        <tr>
                            <td>Locked At</td>
                            <td><?= fmtDate($app['locked_at']) ?></td>
                            <td>Last Updated</td>
                            <td><?= fmtDate($app['updated_at']) ?></td>
                        </tr>
                    </table>
                    <?php if ($app['qualifications']): ?>
                        <table class="w-full border-collapse mb-1 [&_td]:text-[9pt] [&_td]:px-1.5 [&_td]:py-1 [&_td]:align-top [&_td]:border-b [&_td]:border-dotted [&_td]:border-[#e5e7eb] [&_td:nth-child(odd)]:font-bold [&_td:nth-child(odd)]:text-[#4b5563] [&_td:nth-child(odd)]:whitespace-nowrap [&_td:nth-child(odd)]:w-[16%]">
                            <tr>
                                <td>Qualifications</td>
                                <td colspan="3"><?= nl2br(htmlspecialchars($app['qualifications'])) ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                    <?php if ($app['admin_notes']): ?>
                        <table class="w-full border-collapse mb-1 [&_td]:text-[9pt] [&_td]:px-1.5 [&_td]:py-1 [&_td]:align-top [&_td]:border-b [&_td]:border-dotted [&_td]:border-[#e5e7eb] [&_td:nth-child(odd)]:font-bold [&_td:nth-child(odd)]:text-[#4b5563] [&_td:nth-child(odd)]:whitespace-nowrap [&_td:nth-child(odd)]:w-[16%]">
                            <tr>
                                <td>Admin Notes</td>
                                <td colspan="3"><?= nl2br(htmlspecialchars($app['admin_notes'])) ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Application Answers -->
                <?php if ($answers): ?>
                    <div class="mb-4 break-inside-avoid">
                        <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">III. Application Answers</div>
                        <table class="w-full border-collapse text-[8.5pt] mt-1 [&_th]:bg-[#f8fafc] [&_th]:border [&_th]:border-[#e5e7eb] [&_th]:text-left [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-[8pt] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-[#374151] [&_td]:border [&_td]:border-[#e5e7eb] [&_td]:px-2 [&_td]:py-1.5 [&_td]:align-top [&_thead]:table-header-group [&_tr]:break-inside-avoid [&_tbody_tr:nth-child(even)]:bg-[#f8fafc]">
                            <thead>
                                <tr>
                                    <th class="w-[48%]">Question</th>
                                    <th class="w-[32%]">Answer</th>
                                    <th class="w-[20%]">Answered At</th>
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
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">IV. Document Compliance</div>
                    <p class="text-[7.8pt] text-[#9ca3af] italic -mt-[5px] mb-1.5">Files uploaded against this job order's requirements, with reviewer remarks.</p>
                    <p class="text-[8.5pt] text-[#4b5563] mb-2">
                        Required documents submitted: <b><?= $submittedCount ?> / <?= $requiredCount ?></b>
                        (<span class="font-bold <?= $completionPct == 100 ? 'text-[#047857]' : 'text-[#b91c1c]' ?>"><?= $completionPct ?>%</span>)
                        <?php if ($missingDocs): ?> — Missing: <b><?= htmlspecialchars(implode(', ', $missingDocs)) ?></b><?php endif; ?>
                    </p>
                    <?php if (!$documents): ?>
                        <p class="text-[8.5pt] text-[#9ca3af] italic py-1">No documents uploaded for this application.</p>
                    <?php else: ?>
                        <table class="w-full border-collapse text-[8.5pt] mt-1 [&_th]:bg-[#f8fafc] [&_th]:border [&_th]:border-[#e5e7eb] [&_th]:text-left [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-[8pt] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-[#374151] [&_td]:border [&_td]:border-[#e5e7eb] [&_td]:px-2 [&_td]:py-1.5 [&_td]:align-top [&_thead]:table-header-group [&_tr]:break-inside-avoid [&_tbody_tr:nth-child(even)]:bg-[#f8fafc]">
                            <thead>
                                <tr>
                                    <th class="w-[20%]">Document</th>
                                    <th class="w-[22%]">File Name</th>
                                    <th class="w-[14%]">Uploaded</th>
                                    <th class="w-[10%]">Required</th>
                                    <th class="w-[34%]">Comments</th>
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
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">V. Evaluation History</div>
                    <?php if (!$evaluations): ?>
                        <p class="text-[8.5pt] text-[#9ca3af] italic py-1">No evaluations recorded yet.</p>
                    <?php else: ?>
                        <table class="w-full border-collapse text-[8.5pt] mt-1 [&_th]:bg-[#f8fafc] [&_th]:border [&_th]:border-[#e5e7eb] [&_th]:text-left [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-[8pt] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-[#374151] [&_td]:border [&_td]:border-[#e5e7eb] [&_td]:px-2 [&_td]:py-1.5 [&_td]:align-top [&_thead]:table-header-group [&_tr]:break-inside-avoid [&_tbody_tr:nth-child(even)]:bg-[#f8fafc]">
                            <thead>
                                <tr>
                                    <th class="w-[16%]">Date</th>
                                    <th class="w-[12%]">Type</th>
                                    <th class="w-[14%]">Decision</th>
                                    <th class="w-[18%]">By</th>
                                    <th class="w-[40%]">Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluations as $e): ?>
                                    <tr>
                                        <td><?= fmtDate($e['evaluated_at']) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($e['evaluation_type'] ?? '—')) ?></td>
                                        <td><?= statusLabel($e['decision'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($e['evaluator_name'] ?: $e['admin_name'] ?: 'System') ?><?= (int) ($e['evaluator_id'] ?? 0) === $evaluator_id ? ' (You)' : '' ?></td>
                                        <td><?= htmlspecialchars(nz($e['comments'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Status History -->
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">VI. Status History</div>
                    <?php if (!$statusHistory): ?>
                        <p class="text-[8.5pt] text-[#9ca3af] italic py-1">No status changes recorded yet.</p>
                    <?php else: ?>
                        <table class="w-full border-collapse text-[8.5pt] mt-1 [&_th]:bg-[#f8fafc] [&_th]:border [&_th]:border-[#e5e7eb] [&_th]:text-left [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-[8pt] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-[#374151] [&_td]:border [&_td]:border-[#e5e7eb] [&_td]:px-2 [&_td]:py-1.5 [&_td]:align-top [&_thead]:table-header-group [&_tr]:break-inside-avoid [&_tbody_tr:nth-child(even)]:bg-[#f8fafc]">
                            <thead>
                                <tr>
                                    <th class="w-[16%]">Date</th>
                                    <th class="w-[16%]">From</th>
                                    <th class="w-[16%]">To</th>
                                    <th class="w-[18%]">By</th>
                                    <th class="w-[34%]">Reason</th>
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
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">VII. Evaluator Assignments</div>
                    <?php if (!$assignments): ?>
                        <p class="text-[8.5pt] text-[#9ca3af] italic py-1">No evaluator has been assigned to this application.</p>
                    <?php else: ?>
                        <table class="w-full border-collapse text-[8.5pt] mt-1 [&_th]:bg-[#f8fafc] [&_th]:border [&_th]:border-[#e5e7eb] [&_th]:text-left [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-[8pt] [&_th]:uppercase [&_th]:tracking-[.02em] [&_th]:text-[#374151] [&_td]:border [&_td]:border-[#e5e7eb] [&_td]:px-2 [&_td]:py-1.5 [&_td]:align-top [&_thead]:table-header-group [&_tr]:break-inside-avoid [&_tbody_tr:nth-child(even)]:bg-[#f8fafc]">
                            <thead>
                                <tr>
                                    <th class="w-[28%]">Evaluator</th>
                                    <th class="w-[32%]">Email</th>
                                    <th class="w-[24%]">Assigned At</th>
                                    <th class="w-[16%]">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $asg): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($asg['evaluator_name']) ?><?= (int) $asg['evaluator_id'] === $evaluator_id ? ' (You)' : '' ?></td>
                                        <td><?= htmlspecialchars($asg['evaluator_email']) ?></td>
                                        <td><?= fmtDate($asg['assigned_at']) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($asg['status'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <div class="mb-4 break-inside-avoid">
                    <div class="text-[10.5pt] font-bold text-white bg-[#0b1f4b] px-[9px] py-[5px] mb-2 uppercase tracking-[.03em]">VIII. Notes for Evaluation</div>
                    <ul class="text-[8.7pt] text-[#374151] leading-[1.7] m-0 pl-[15px]">
                        <?php if ($missingDocs): ?>
                            <li><strong>Missing documents:</strong> <?= htmlspecialchars(implode(', ', $missingDocs)) ?> — flag for compliance before approving.</li>
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

                <div class="mt-[46px] flex justify-between text-[8.5pt] break-inside-avoid">
                    <div class="w-[42%] text-center">
                        <div class="border-t border-[#1f2937] mt-[34px] pt-1 text-[#374151]">Evaluator</div>
                    </div>
                    <div class="w-[42%] text-center">
                        <div class="border-t border-[#1f2937] mt-[34px] pt-1 text-[#374151]">Authorized Signatory, DMW RO IV-A</div>
                    </div>
                </div>

                <div class="mt-[22px] pt-2 border-t border-[#9ca3af]">
                    <div class="flex items-stretch gap-4">
                        <div class="flex-[1_1_60%] flex items-center">
                            <p class="text-[7.3pt] text-[#6b7280] leading-[1.55] text-left m-0">
                                This is an official system-generated report from the DMW Application Management System —
                                Regional Office IV-A (CALABARZON). Information is confidential and intended solely for internal
                                evaluation, compliance review, and official case processing.
                            </p>
                        </div>
                        <div class="flex-none basis-[34%] border-l border-[#d1d5db] pl-3.5 flex flex-col justify-center gap-[3px] text-[7.3pt] text-[#6b7280] [&>span:first-child]:text-[#374151]">
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

        async function exportToPdf() {
            const btn = document.getElementById('exportPdfBtn');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            btn.disabled = true;

            const webView = document.querySelector('.webView');
            const printEl = document.getElementById('printReport');
            const printLogo = document.getElementById('printLogo');
            const originalLogoSrc = printLogo ? printLogo.getAttribute('src') : null;
            const filename = 'Application_#<?= (int) $id ?>_<?= $notFound ? "NotFound" : preg_replace('/[^A-Za-z0-9_-]/', '_', $applicantName) ?>.pdf';

            if (printLogo && originalLogoSrc) {
                try {
                    printLogo.src = await toDataURL(originalLogoSrc);
                } catch (err) {
                    console.warn('DMW logo could not be inlined for PDF export:', err);
                }
            }

            if (webView) webView.style.display = 'none';
            if (printEl) printEl.style.display = 'block';
            document.querySelectorAll('.no-print').forEach(el => el.style.display = 'none');

            const restore = () => {
                if (webView) webView.style.display = '';
                if (printEl) printEl.style.display = 'none';
                if (printLogo && originalLogoSrc) printLogo.src = originalLogoSrc;
                document.querySelectorAll('.no-print').forEach(el => el.style.display = '');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            };

            const opt = {
                margin: [10, 8, 14, 8],
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

                        for (let i = 1; i <= totalPages; i++) {
                            pdf.setPage(i);
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
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
<?php
session_start();
require_once '../db.php';
require_once '../auth.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}
date_default_timezone_set('Asia/Manila');

$client_email = $_SESSION['user_email'];
$client_name  = $_SESSION['full_name'] ?? 'Client';
$client_id    = null;
$app_count    = 0;
$my_apps      = [];
$pic_row      = null;

$stmt = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    $client_id = (int)$row['cid'];
    // Fetch profile picture path
    $pic_stmt = $database->prepare("SELECT profile_picture FROM clients WHERE cid = ? LIMIT 1");
    $pic_stmt->bind_param("i", $client_id);
    $pic_stmt->execute();
    $pic_row = $pic_stmt->get_result()->fetch_assoc();
    $pic_stmt->close();
    $cnt = $database->query("SELECT COUNT(*) as c FROM applications WHERE client_id = $client_id");
    if ($cnt) $app_count = (int)$cnt->fetch_assoc()['c'];
    $res = $database->query("
        SELECT a.id, a.status, a.updated_at, r.position_title, r.worksite_country, r.rsf_number, r.slots_available
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.client_id = $client_id
        ORDER BY a.updated_at DESC
        LIMIT 10
    ");
    if ($res) $my_apps = $res->fetch_all(MYSQLI_ASSOC);
}

$dc_stmt = $database->prepare("
    SELECT dc.id AS comment_id, dc.comment, dc.created_at, dc.commented_by,
           ad.document_type, ad.application_id, w.usertype AS commenter_type,
           r.rsf_number, r.employer_name, r.position_title, r.worksite_country
    FROM document_comments dc
    JOIN application_documents ad ON dc.document_id = ad.id
    JOIN applications a ON ad.application_id = a.id
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN webuser w ON w.email = dc.commented_by
    WHERE a.client_id = ?
    AND a.status != 'approved'
    AND a.status != 'disapproved'
    AND dc.commented_by != ?
    AND dc.id = (
          SELECT MAX(dc2.id) FROM document_comments dc2 WHERE dc2.document_id = dc.document_id
      )
    ORDER BY dc.created_at DESC
    LIMIT 10
");
$dc_stmt->bind_param("is", $client_id, $client_email);
$dc_stmt->execute();
$doc_comments_raw = $dc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dc_stmt->close();

// Group comments by application so multiple pending comments on the
// same application render together as one card with a bulleted list.
$doc_comments = [];
foreach ($doc_comments_raw as $dc) {
    $aid = (int) $dc['application_id'];
    if (!isset($doc_comments[$aid])) {
        $doc_comments[$aid] = [
            'application_id'   => $aid,
            'rsf_number'       => $dc['rsf_number'],
            'employer_name'    => $dc['employer_name'],
            'position_title'   => $dc['position_title'],
            'worksite_country' => $dc['worksite_country'],
            'items'            => [],
        ];
    }
    $doc_comments[$aid]['items'][] = $dc;
}
$doc_comments = array_slice(array_values($doc_comments), 0, 5);
$doc_comments_count = count($doc_comments_raw);

// Reassignment history — evaluator handoffs per application, derived from
// assignments (who handled the case, in order) plus status_history rows
// whose reason is exactly "Admin reopened case to original evaluator".
// A "reopen" event is only shown alongside a real reassignment for that
// same application, since on its own it's just a compliance back-and-forth,
// not a change of who's handling the case.
$reassignments = [];
if ($client_id && !empty($my_apps)) {
    $app_ids      = array_column($my_apps, 'id');
    $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
    $types        = str_repeat('i', count($app_ids));

    $asg_stmt = $database->prepare("
        SELECT asg.application_id, asg.evaluator_id, asg.assigned_at,
               ev.full_name AS evaluator_name,
               r.rsf_number, r.employer_name, r.position_title, r.worksite_country
        FROM assignments asg
        JOIN evaluators ev ON asg.evaluator_id = ev.eid
        JOIN applications a ON asg.application_id = a.id
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE asg.application_id IN ($placeholders)
        ORDER BY asg.application_id, asg.assigned_at ASC
    ");
    $asg_stmt->bind_param($types, ...$app_ids);
    $asg_stmt->execute();
    $assignment_rows = $asg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $asg_stmt->close();

    $by_app = [];
    foreach ($assignment_rows as $row) {
        $aid = (int) $row['application_id'];
        $by_app[$aid]['meta']   = $row;
        $by_app[$aid]['rows'][] = $row;
    }

    foreach ($by_app as $aid => $bundle) {
        $rows = $bundle['rows'];
        for ($i = 1; $i < count($rows); $i++) {
            if ((int) $rows[$i]['evaluator_id'] !== (int) $rows[$i - 1]['evaluator_id']) {
                $reassignments[$aid]['meta']     = $bundle['meta'];
                $reassignments[$aid]['events'][] = [
                    'type' => 'reassign',
                    'ts'   => strtotime($rows[$i]['assigned_at']),
                    'from' => $rows[$i - 1]['evaluator_name'],
                    'to'   => $rows[$i]['evaluator_name'],
                ];
            }
        }
    }

    if (!empty($reassignments)) {
        $sh_stmt = $database->prepare("
            SELECT application_id, changed_at
            FROM status_history
            WHERE application_id IN ($placeholders)
              AND reason = 'Admin reopened case to original evaluator'
            ORDER BY application_id, changed_at ASC
        ");
        $sh_stmt->bind_param($types, ...$app_ids);
        $sh_stmt->execute();
        $reopen_rows = $sh_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sh_stmt->close();

        foreach ($reopen_rows as $row) {
            $aid = (int) $row['application_id'];
            if (!isset($reassignments[$aid])) continue;
            $reassignments[$aid]['events'][] = [
                'type' => 'reopen',
                'ts'   => strtotime($row['changed_at']),
            ];
        }
    }

    foreach ($reassignments as &$bundle) {
        usort($bundle['events'], fn($a, $b) => $a['ts'] <=> $b['ts']);
    }
    unset($bundle);

    $reassignments = array_slice($reassignments, 0, 3, true);
}

$latest_app = !empty($my_apps) ? $my_apps[0] : null;
$latest_status = $latest_app['status'] ?? null;
// Open application whose job order can no longer take anyone else.
$latest_slots_full = $latest_app
    && $latest_app['slots_available'] !== null
    && (int) $latest_app['slots_available'] <= 0
    && !in_array($latest_status, ['draft', 'approved', 'disapproved'], true);

// ── Profile completeness gate ────────────────────────────────────────────────
$profile_complete = false;
if ($client_id) {
    $pstmt = $database->prepare(
        "SELECT profile_completed_at FROM client_profiles WHERE client_id = ? LIMIT 1"
    );
    $pstmt->bind_param("i", $client_id);
    $pstmt->execute();
    $prow = $pstmt->get_result()->fetch_assoc();
    $pstmt->close();
    $profile_complete = ($prow && !empty($prow['profile_completed_at']));
}

// Show profile modal automatically on new registration OR if profile incomplete
$show_profile_modal = !$profile_complete;

// Degree programs, grouped by category, for the education step's degree picker
$degreeGroups = [];
if ($show_profile_modal) {
    $dgResult = $database->query("SELECT id, degree_name, degree_category FROM degree_programs ORDER BY degree_category ASC, degree_name ASC");
    $degreeRows = $dgResult ? $dgResult->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($degreeRows as $d) {
        $cat = $d['degree_category'] ?: 'Others';
        $degreeGroups[$cat][] = $d;
    }
    // Ensure "Others" renders as the last group
    if (isset($degreeGroups['Others'])) {
        $othersGroup = $degreeGroups['Others'];
        unset($degreeGroups['Others']);
        $degreeGroups['Others'] = $othersGroup;
    }
}

// Avatar initials from session name (first + last initial)
$full_name = $_SESSION['full_name'] ?? 'Client';
$parts     = preg_split('/\s+/', trim($full_name));
$initials  = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($full_name, 0, 2));

// Profile picture (fetched earlier into $pic_row)
$has_picture = !empty($pic_row['profile_picture'] ?? null);
$picture_url = $has_picture ? '../' . $pic_row['profile_picture'] : null;

// Aliases so the shared avatar-menu markup works on this page
$client_name     = $full_name;
$client_initials = $initials ?: 'U';

// Greeting
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first    = $parts[0] ?: $client_name;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Client Dashboard – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/darkmode.css">

    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        @keyframes fi2 {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-overlay.overlay-hidden {
            display: none;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Recent Applications: show ~5 rows, scroll for the rest */
        #recent-apps-list {
            max-height: 322px;
            overflow-y: auto;
        }

        #recent-apps-list::-webkit-scrollbar {
            width: 5px;
        }

        #recent-apps-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        #recent-apps-list::-webkit-scrollbar-track {
            background: transparent;
        }

        /* Reassignment History tab panels: scroll only when an application
           has more than 5 events, instead of expanding the whole page */
        .reassign-scroll {
            scrollbar-width: thin;
        }

        .reassign-scroll::-webkit-scrollbar {
            width: 5px;
        }

        .reassign-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .reassign-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        [data-theme="dark"] .bg-white {
            background-color: #1e293b !important;
        }

        [data-theme="dark"] .bg-\[\#eff6ff\] {
            background-color: rgba(59, 130, 246, .15) !important;
        }

        [data-theme="dark"] .bg-\[\#eef2ff\] {
            background-color: rgba(99, 102, 241, .15) !important;
        }

        [data-theme="dark"] .bg-\[\#ecfdf5\] {
            background-color: rgba(16, 185, 129, .15) !important;
        }

        [data-theme="dark"] .bg-\[\#d1fae5\] {
            background-color: rgba(16, 185, 129, .2) !important;
        }

        [data-theme="dark"] .bg-\[\#fff7ed\] {
            background-color: rgba(217, 119, 6, .18) !important;
        }

        [data-theme="dark"] .bg-\[\#fee2e2\] {
            background-color: rgba(220, 38, 38, .2) !important;
        }

        [data-theme="dark"] .bg-\[\#f1f5f9\] {
            background-color: rgba(148, 163, 184, .15) !important;
        }

        [data-theme="dark"] .bg-\[\#fef3c7\] {
            background-color: rgba(217, 119, 6, .2) !important;
        }

        [data-theme="dark"] .bg-\[\#fffbeb\] {
            background-color: rgba(217, 119, 6, .12) !important;
        }

        [data-theme="dark"] .text-\[\#3b82f6\] {
            color: #60a5fa !important;
        }

        [data-theme="dark"] .text-\[\#6366f1\] {
            color: #818cf8 !important;
        }

        [data-theme="dark"] .text-\[\#059669\] {
            color: #34d399 !important;
        }

        [data-theme="dark"] .text-\[\#065f46\] {
            color: #34d399 !important;
        }

        [data-theme="dark"] .text-\[\#3730a3\] {
            color: #a5b4fc !important;
        }

        [data-theme="dark"] .text-\[\#92400e\] {
            color: #fbbf24 !important;
        }

        [data-theme="dark"] .text-\[\#1e40af\] {
            color: #93c5fd !important;
        }

        [data-theme="dark"] .text-\[\#991b1b\] {
            color: #f87171 !important;
        }

        [data-theme="dark"] .text-\[\#475569\] {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .text-\[\#b91c1c\] {
            color: #f87171 !important;
        }

        [data-theme="dark"] .text-\[\#78350f\] {
            color: #fbbf24 !important;
        }

        [data-theme="dark"] .border-\[\#fde68a\] {
            border-color: rgba(251, 191, 36, .35) !important;
        }

        [data-theme="dark"] .border-\[\#bfdbfe\] {
            border-color: rgba(96, 165, 250, .35) !important;
        }

        [data-theme="dark"] .hover\:bg-\[\#f4f6fb\]:hover {
            background-color: #263449 !important;
        }

        [data-theme="dark"] .hover\:bg-\[\#f1f5fb\]:hover {
            background-color: #263449 !important;
        }

        [data-theme="dark"] .status-banner-box {
            background: var(--sb-dbg) !important;
            border-color: var(--sb-dbd) !important;
        }

        [data-theme="dark"] .status-banner-h {
            color: var(--sb-dh) !important;
        }

        [data-theme="dark"] .status-banner-p {
            color: var(--sb-dp) !important;
        }

        [data-theme="dark"] #getStartedBox h4 {
            color: #93c5fd !important;
        }

        [data-theme="dark"] #getStartedBox p {
            color: #bfdbfe !important;
        }

        /* ── Readability scale (older users): ~+1.5px per size ──
           Tailwind CDN injects its sheet after this block, so !important is required. */
        .text-\[9px\] {
            font-size: 10.5px !important;
        }

        .text-\[9\.5px\] {
            font-size: 11px !important;
        }

        .text-\[10px\] {
            font-size: 11.5px !important;
        }

        .text-\[10\.5px\] {
            font-size: 12px !important;
        }

        .text-\[11px\] {
            font-size: 12.5px !important;
        }

        .text-\[11\.5px\] {
            font-size: 13px !important;
        }

        .text-\[12px\],
        .text-xs {
            font-size: 13.5px !important;
        }

        .text-\[12\.5px\] {
            font-size: 14px !important;
        }

        .text-\[13px\] {
            font-size: 14.5px !important;
        }

        .text-\[15px\] {
            font-size: 16.5px !important;
        }

        .text-base {
            font-size: 17.5px !important;
        }
    </style>
    </style>
    <script>
        (function() {
            const t = localStorage.getItem('dmw_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body>

    <!--TOPBAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="w-[42px] h-auto block bg-transparent [filter:none]">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; APPLICANT PORTAL</p>
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
            <div class="relative" id="avatarMenuWrap">
                <button type="button" id="avatarMenuBtn"
                    class="topbar-avatar cursor-pointer border-0 p-0"
                    aria-haspopup="menu" aria-expanded="false" aria-controls="avatarMenu" aria-label="Account menu"
                    <?= $has_picture ? "style=\"background-image:url('" . htmlspecialchars($picture_url, ENT_QUOTES) . "');background-size:cover;background-position:center;\"" : '' ?>>
                    <?= $has_picture ? '' : htmlspecialchars($client_initials) ?>
                </button>

                <div id="avatarMenu" role="menu"
                    class="hidden absolute right-0 top-[calc(100%+10px)] w-[240px] bg-[var(--card)] border border-[var(--border)] rounded-[14px] shadow-[0_16px_40px_rgba(11,31,75,.18)] overflow-hidden z-[700]">

                    <!-- Header: who is signed in -->
                    <div class="py-3 px-4 border-b border-[var(--border)] bg-[var(--surface)]">
                        <div class="text-[12.5px] font-bold text-[var(--text1)] truncate"><?= htmlspecialchars($client_name) ?></div>
                        <div class="text-[10.5px] text-[var(--text3)] truncate"><?= htmlspecialchars($client_email) ?></div>
                    </div>

                    <!-- Navigation -->
                    <div class="py-1.5">
                        <a href="profile.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-user-gear w-4 text-center text-[var(--blue-lt)]"></i> My Profile
                        </a>
                        <a href="my-applications.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-file-lines w-4 text-center text-[var(--blue-lt)]"></i> My Applications
                        </a>
                        <a href="jobs.php" role="menuitem"
                            class="flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[var(--text2)] no-underline transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-briefcase w-4 text-center text-[var(--blue-lt)]"></i> Browse Jobs
                        </a>
                    </div>

                    <!-- Logout (reuses your existing logout() function) -->
                    <div class="border-t border-[var(--border)] py-1.5">
                        <button type="button" role="menuitem" onclick="logout('Applicant')"
                            class="w-full flex items-center gap-2.5 py-2 px-4 text-[12.5px] font-semibold text-[#dc2626] bg-transparent border-0 cursor-pointer text-left transition-colors hover:bg-[var(--surface)]">
                            <i class="fas fa-arrow-right-from-bracket w-4 text-center"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= $greeting ?>, <?= htmlspecialchars($first) ?></h2>
                <p><?= $app_count > 0
                        ? number_format($app_count) . ' active application' . ($app_count !== 1 ? 's' : '')
                        : 'No applications yet — browse jobs to get started' ?></p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT
         NOTE: container widened + responsive padding so the page scales
         with the viewport instead of capping at a narrow centered column. -->
    <div id="mainContent" class="max-w-[1440px] mx-auto px-4 md:px-6 lg:px-10 pt-3.5 lg:pt-5 pb-[calc(var(--dock-h)_+_36px_+_env(safe-area-inset-bottom))]">

        <!-- Quick Actions 
        <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-[var(--text3)] mb-2 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.04s]">Quick Actions</p>
        <div class="grid grid-cols-3 gap-2.5 lg:gap-4 mb-3 lg:mb-5 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.08s]">

            <button class="bg-[var(--card)] border-none rounded-[14px] pt-3.5 px-2 pb-3 lg:pt-5 lg:pb-4 flex flex-col items-center gap-[7px] no-underline text-[var(--text2)] text-[11px] lg:text-[12.5px] font-bold text-center cursor-pointer transition-all hover:bg-[#f4f6fb] hover:-translate-y-[3px] hover:shadow-[0_8px_20px_-4px_rgba(11,31,75,0.1)] active:scale-[.97]" onclick="showBrowseWarningModal()">
                <div class="w-[38px] h-[38px] lg:w-11 lg:h-11 rounded-[11px] flex items-center justify-center text-[15px] lg:text-[17px] bg-[#eff6ff] text-[#3b82f6]">
                    <i class="fas fa-briefcase"></i>
                </div>
                Browse Jobs
            </button>

            <a href="my-applications.php" class="bg-[var(--card)] border-none rounded-[14px] pt-3.5 px-2 pb-3 lg:pt-5 lg:pb-4 flex flex-col items-center gap-[7px] no-underline text-[var(--text2)] text-[11px] lg:text-[12.5px] font-bold text-center cursor-pointer transition-all hover:bg-[#f4f6fb] hover:-translate-y-[3px] hover:shadow-[0_8px_20px_-4px_rgba(11,31,75,0.1)] active:scale-[.97]">
                <div class="w-[38px] h-[38px] lg:w-11 lg:h-11 rounded-[11px] flex items-center justify-center text-[15px] lg:text-[17px] bg-[#eef2ff] text-[#6366f1]">
                    <i class="fas fa-file-lines"></i>
                </div>
                My Applications
            </a>

            <a href="notifications.php" class="bg-[var(--card)] border-none rounded-[14px] pt-3.5 px-2 pb-3 lg:pt-5 lg:pb-4 flex flex-col items-center gap-[7px] no-underline text-[var(--text2)] text-[11px] lg:text-[12.5px] font-bold text-center cursor-pointer transition-all hover:bg-[#f4f6fb] hover:-translate-y-[3px] hover:shadow-[0_8px_20px_-4px_rgba(11,31,75,0.1)] active:scale-[.97]">
                <div class="w-[38px] h-[38px] lg:w-11 lg:h-11 rounded-[11px] flex items-center justify-center text-[15px] lg:text-[17px] bg-[#ecfdf5] text-[#059669]">
                    <i class="fas fa-bell"></i>
                </div>
                Notifications
            </a>

        </div>
-->
        <!-- KPI Stats -->
        <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-[var(--text3)] mb-2 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.08s]">Overview</p>
        <div class="z-stats opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.12s]">

            <div class="kpi-card c-blue">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">My Applications</div>
                        <div class="kpi-value c-blue"><?= $app_count ?></div>
                    </div>
                    <div class="kpi-icon c-blue"><i class="fas fa-file-lines"></i></div>
                </div>
                <div class="kpi-sub">Total submitted</div>
            </div>

            <?php
            $statusCounts = ['approved' => 0, 'under_evaluation' => 0, 'for_compliance' => 0];
            foreach ($my_apps as $a) {
                $s = $a['status'];
                if ($s === 'approved') {
                    $statusCounts['approved']++;
                } elseif (in_array($s, ['under_evaluation', 'assign', 'assigned'], true)) {
                    $statusCounts['under_evaluation']++;
                } elseif ($s === 'for_compliance') {
                    $statusCounts['for_compliance']++;
                }
            }
            ?>

            <div class="kpi-card c-green">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Approved</div>
                        <div class="kpi-value c-green"><?= $statusCounts['approved'] ?></div>
                    </div>
                    <div class="kpi-icon c-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="kpi-sub">Applications approved</div>
            </div>

            <div class="kpi-card c-indigo">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Under Review</div>
                        <div class="kpi-value c-indigo"><?= $statusCounts['under_evaluation'] ?></div>
                    </div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-magnifying-glass"></i></div>
                </div>
                <div class="kpi-sub">Being evaluated now</div>
            </div>

            <div class="kpi-card c-amber">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">For Compliance</div>
                        <div class="kpi-value c-amber"><?= $statusCounts['for_compliance'] ?></div>
                    </div>
                    <div class="kpi-icon c-amber"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-sub">Needs your attention</div>
            </div>

        </div>

        <!-- Recent Applications + Status: single column on mobile/tablet,
             two-column (2fr list / 1fr sticky sidebar) on desktop (lg+) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-5 mt-1 lg:items-start">

            <!-- Recent Applications + Reassignment History (wide column) -->
            <div class="lg:col-span-2 order-2 lg:order-1">
                <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-[var(--text3)] mb-2 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.16s]" style="margin-top:1.3rem">My Recent Applications</p>
                <div class="bg-[var(--card)] border border-[var(--border)] rounded-[14px] overflow-hidden mb-2.5 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.16s]">
                    <div class="flex items-center justify-between py-3 px-4 border-b border-[var(--border)] bg-[var(--surface)]">
                        <span class="text-[10.5px] font-bold tracking-[.07em] uppercase text-[var(--text2)] flex items-center gap-1.5">
                            <span class="w-[7px] h-[7px] rounded-full bg-[var(--blue-lt)]"></span>
                            Applications
                        </span>
                        <a href="my-applications.php" class="text-[11px] font-semibold text-[var(--blue-lt)] no-underline flex items-center gap-1 opacity-75 transition-opacity hover:opacity-100">
                            View all <i class="fas fa-arrow-right text-[9px]"></i>
                        </a>
                    </div>

                    <?php if (empty($my_apps)): ?>
                        <div class="py-9 px-4 text-center">
                            <i class="fas fa-inbox text-3xl text-[var(--border)] block mb-2.5"></i>
                            <p class="text-[12.5px] font-semibold text-[var(--text3)]">No applications yet</p>
                            <span class="text-[11px] text-[var(--text3)]"><a href="jobs.php" class="text-[var(--blue-lt)] font-semibold no-underline">Browse job opportunities</a> to submit your first application.</span>
                        </div>
                    <?php else: ?>
                        <div id="recent-apps-list">
                            <?php foreach ($my_apps as $app): ?>
                                <?php
                                $s    = strtolower($app['status'] ?? '');
                                $stxt = str_replace('_', ' ', ucwords($s));
                                $pillClasses = match ($s) {
                                    'approved'         => 'bg-[#d1fae5] text-[#065f46]',
                                    'under_evaluation' => 'bg-[#eef2ff] text-[#3730a3]',
                                    'for_compliance'   => 'bg-[#fff7ed] text-[#92400e]',
                                    'assign'           => 'bg-[#eff6ff] text-[#1e40af]',
                                    'assigned'         => 'bg-[#eff6ff] text-[#1e40af]',
                                    'disapproved'      => 'bg-[#fee2e2] text-[#991b1b]',
                                    'resubmitted'      => 'bg-[#eef2ff] text-[#3730a3]',
                                    'unassign'         => 'bg-[#f1f5f9] text-[#475569]',
                                    'unassigned'       => 'bg-[#f1f5f9] text-[#475569]',
                                    'pending'          => 'bg-[#f1f5f9] text-[#475569]',
                                    default            => 'bg-[#f1f5f9] text-[#475569]',
                                };
                                $posInitials = strtoupper(substr($app['position_title'] ?? 'J', 0, 2));
                                ?>
                                <a href="my-applications.php?id=<?= (int) ($app['id'] ?? 0) ?>" class="flex items-center gap-2.5 py-[11px] px-4 border-b border-[var(--border)] last:border-b-0 no-underline text-inherit transition-colors hover:bg-[var(--surface)]">
                                    <div class="w-9 h-9 rounded-[10px] bg-[#eff6ff] text-[var(--blue)] text-[11px] font-extrabold flex items-center justify-center flex-shrink-0"><?= htmlspecialchars($posInitials) ?></div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[12.5px] font-bold text-[var(--text1)] whitespace-nowrap overflow-hidden text-ellipsis"><?= htmlspecialchars($app['position_title'] ?? '—') ?></div>
                                        <div class="text-[10.5px] text-[var(--text3)] font-medium mt-[1px] whitespace-nowrap overflow-hidden text-ellipsis">
                                            <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                                            &nbsp;·&nbsp;<?= htmlspecialchars($app['worksite_country'] ?? '—') ?>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <span class="inline-block text-[9.5px] font-bold tracking-[.04em] uppercase py-[3px] px-2.5 rounded-full <?= $pillClasses ?>"><?= htmlspecialchars($stxt) ?></span>
                                        <div class="text-[10px] text-[var(--text3)] font-medium mt-[3px]">
                                            <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div><!-- /recent-apps-list -->
                    <?php endif; ?>
                </div>

                <!-- Reassignment History — full width, same column as
                     Applications, organized as one tab per application.
                     Any application with more than 5 events scrolls
                     inside its own panel instead of growing the page. -->
                <?php if (!empty($reassignments)): ?>
                    <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-[var(--text3)] mb-2 mt-4 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.28s]">Reassignment History</p>
                    <div class="bg-[var(--card)] border border-[var(--border)] rounded-[14px] overflow-hidden mb-2.5 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.28s]">
                        <div class="flex items-center justify-between py-3 px-4 border-b border-[var(--border)] bg-[var(--surface)]">
                            <span class="text-[10.5px] font-bold tracking-[.07em] uppercase text-[var(--text2)] flex items-center gap-1.5">
                                <span class="w-[7px] h-[7px] rounded-full bg-[#6366f1]"></span>
                                Reassignment History
                            </span>
                        </div>

                        <!-- Tab buttons: one per application -->
                        <div class="flex gap-1.5 overflow-x-auto py-2.5 px-3 border-b border-[var(--border)]" id="reassignTabs">
                            <?php $isFirst = true;
                            foreach ($reassignments as $aid => $bundle): $meta = $bundle['meta']; ?>
                                <a href="my-applications.php?id=<?= (int) $aid ?>"
                                    class="reassign-tab-btn flex-shrink-0 py-[7px] px-3 rounded-[9px] text-[11px] font-bold whitespace-nowrap border transition-colors no-underline
    <?= $isFirst ? 'text-white' : 'bg-[var(--surface)] text-[var(--text2)] border-[var(--border)] hover:bg-[var(--border)]' ?>"
                                    style="<?= $isFirst ? 'background:#0b1f4b;border-color:#0b1f4b;' : '' ?>"
                                    data-tab-target="reassign-panel-<?= (int) $aid ?>"
                                    onclick="switchReassignTab(this, <?= (int) $aid ?>); return false;">
                                    <?= htmlspecialchars($meta['rsf_number'] ?? '—') ?>
                                </a>
                            <?php $isFirst = false;
                            endforeach; ?>
                        </div>

                        <!-- Tab panels: one per application -->
                        <div class="p-3">
                            <?php $isFirst = true;
                            foreach ($reassignments as $aid => $bundle):
                                $meta   = $bundle['meta'];
                                $events = $bundle['events'];
                            ?>
                                <div id="reassign-panel-<?= (int) $aid ?>" class="reassign-tab-panel <?= $isFirst ? '' : 'hidden' ?>">
                                    <a href="my-applications.php?id=<?= (int) $aid ?>" class="block mb-2.5 p-2 -mx-2 rounded-[10px] no-underline text-inherit transition-colors hover:bg-[var(--surface)]">
                                        <div class="text-[11.5px] font-bold text-[var(--text1)]"><?= htmlspecialchars($meta['position_title'] ?? '—') ?></div>
                                        <div class="text-[10.5px] text-[var(--text3)] font-medium mt-[1px]">
                                            <?= htmlspecialchars($meta['rsf_number'] ?? '—') ?>
                                            &nbsp;·&nbsp;<?= htmlspecialchars($meta['employer_name'] ?? '—') ?>
                                            &nbsp;·&nbsp;<?= htmlspecialchars($meta['worksite_country'] ?? '—') ?>
                                        </div>
                                    </a>
                                    <div class="<?= count($events) > 5 ? 'max-h-[220px] overflow-y-auto pr-1 reassign-scroll' : '' ?>">
                                        <?php foreach ($events as $ev): ?>
                                            <div class="flex gap-2.5 mb-2 last:mb-0">
                                                <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-white text-[10px]"
                                                    style="background:<?= $ev['type'] === 'reassign' ? '#6366f1' : '#94a3b8' ?>">
                                                    <i class="fas <?= $ev['type'] === 'reassign' ? 'fa-user-check' : 'fa-rotate-left' ?>"></i>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <?php if ($ev['type'] === 'reassign'): ?>
                                                        <div class="text-[11.5px] font-semibold text-[var(--text1)]">
                                                            Reassigned from <strong><?= htmlspecialchars($ev['from']) ?></strong> to <strong><?= htmlspecialchars($ev['to']) ?></strong> by Admin
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-[11.5px] text-[var(--text2)]">Case reopened to original evaluator by Admin</div>
                                                    <?php endif; ?>
                                                    <div class="text-[10px] text-[var(--text3)] mt-[1px]"><?= date('M d · g:i A', $ev['ts']) ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php $isFirst = false;
                            endforeach; ?>
                            <p class="text-[10.5px] text-[var(--text3)] pt-2 mt-1 border-t border-[var(--border)]">Reassignments are normal — this just keeps you informed on who's handling your case.</p>
                        </div>
                    </div>
                <?php endif; ?>

            </div><!-- /lg:col-span-2 -->

            <!-- Status / Get Started + Document Comments (sidebar column on desktop) -->
            <div class="lg:col-span-1 lg:sticky lg:top-4 order-1 lg:order-2 flex flex-col gap-2.5">
                <div class="bg-[var(--card)] border border-[var(--border)] rounded-[14px] overflow-hidden opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.20s]">

                    <!-- Status banner -->
                    <?php if ($latest_status): ?>
                        <?php
                        $bannerColor = match (true) {
                            in_array($latest_status, ['pending', 'unassign', 'unassigned'], true) => ['bg' => '#fffbeb', 'border' => '#fde68a', 'h' => '#92400e', 'p' => '#78350f', 'dbg' => 'rgba(217,119,6,.12)', 'dbd' => 'rgba(251,191,36,.35)', 'dh' => '#fbbf24', 'dp' => '#fcd34d'],
                            in_array($latest_status, ['assign', 'assigned', 'under_evaluation'], true) => ['bg' => '#eef2ff', 'border' => '#c7d2fe', 'h' => '#3730a3', 'p' => '#4338ca', 'dbg' => 'rgba(99,102,241,.15)', 'dbd' => 'rgba(129,140,248,.35)', 'dh' => '#a5b4fc', 'dp' => '#c7d2fe'],
                            $latest_status === 'resubmitted'                         => ['bg' => '#e0f2fe', 'border' => '#7dd3fc', 'h' => '#0369a1', 'p' => '#0c4a6e', 'dbg' => 'rgba(14,165,233,.15)', 'dbd' => 'rgba(56,189,248,.35)', 'dh' => '#7dd3fc', 'dp' => '#bae6fd'],
                            $latest_status === 'for_compliance'                        => ['bg' => '#fff7ed', 'border' => '#fed7aa', 'h' => '#9a3412', 'p' => '#7c2d12', 'dbg' => 'rgba(217,119,6,.15)', 'dbd' => 'rgba(251,146,60,.35)', 'dh' => '#fdba74', 'dp' => '#fed7aa'],
                            $latest_status === 'approved'                              => ['bg' => '#d1fae5', 'border' => '#a7f3d0', 'h' => '#065f46', 'p' => '#047857', 'dbg' => 'rgba(16,185,129,.15)', 'dbd' => 'rgba(52,211,153,.35)', 'dh' => '#34d399', 'dp' => '#6ee7b7'],
                            $latest_status === 'disapproved'                           => ['bg' => '#fee2e2', 'border' => '#fecaca', 'h' => '#991b1b', 'p' => '#b91c1c', 'dbg' => 'rgba(220,38,38,.15)', 'dbd' => 'rgba(248,113,113,.35)', 'dh' => '#f87171', 'dp' => '#fca5a5'],
                            default                                                    => ['bg' => '#f1f5f9', 'border' => '#e2e8f0', 'h' => '#1e293b', 'p' => '#475569', 'dbg' => 'rgba(148,163,184,.15)', 'dbd' => 'rgba(148,163,184,.35)', 'dh' => '#e2e8f0', 'dp' => '#94a3b8'],
                        };
                        $bannerMsg = match (true) {
                            in_array($latest_status, ['pending', 'unassign', 'unassigned'], true) => 'Your application has been submitted and is awaiting evaluator assignment.',
                            in_array($latest_status, ['assign', 'assigned', 'under_evaluation'], true) => 'Your application is being evaluated. You will be notified if additional information is needed.',
                            $latest_status === 'resubmitted'                         => 'You resubmitted your application. An evaluator will review your updates.',
                            $latest_status === 'for_compliance'                        => 'Please review the evaluator\'s notes and update your application in My Applications.',
                            $latest_status === 'approved'                              => 'Congratulations! Your application has been approved.',
                            $latest_status === 'disapproved'                           => 'Your application was not approved. You may apply for other positions.',
                            default                                                    => 'Track your application progress in My Applications.',
                        };
                        if ($latest_slots_full) {
                            $slotJob = trim((string) ($latest_app['position_title'] ?? ''));
                            $slotWhere = $slotJob !== '' ? $slotJob : 'the position you applied for';
                            $bannerColor = ['bg' => '#fffbeb', 'border' => '#fde68a', 'h' => '#92400e', 'p' => '#78350f', 'dbg' => 'rgba(217,119,6,.12)', 'dbd' => 'rgba(251,191,36,.35)', 'dh' => '#fbbf24', 'dp' => '#fcd34d'];
                            $bannerMsg = 'Unfortunately, the job order you applied for (' . $slotWhere . ') has reached its maximum slot capacity. Your application cannot be accommodated because all available slots are now occupied. Your current status is unchanged. You may wait for new job opportunities or apply to other available positions.';
                        }
                        ?>
                        <div class="status-banner-box mx-4 my-4 rounded-xl py-3.5 px-4 border"
                            style="--sb-bg:<?= $bannerColor['bg'] ?>;--sb-border:<?= $bannerColor['border'] ?>;--sb-dbg:<?= $bannerColor['dbg'] ?>;--sb-dbd:<?= $bannerColor['dbd'] ?>;background:var(--sb-bg);border-color:var(--sb-border);">
                            <h4 class="status-banner-h text-[12.5px] font-extrabold mb-1" style="--sb-h:<?= $bannerColor['h'] ?>;--sb-dh:<?= $bannerColor['dh'] ?>;color:var(--sb-h);">
                                Latest Status: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $latest_status))) ?><?= $latest_slots_full ? ' — Slots Fully Occupied' : '' ?>
                            </h4>
                            <p class="status-banner-p text-[11.5px] font-medium leading-relaxed" style="--sb-p:<?= $bannerColor['p'] ?>;--sb-dp:<?= $bannerColor['dp'] ?>;color:var(--sb-p);"><?= htmlspecialchars($bannerMsg) ?></p>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <a href="my-applications.php" class="inline-flex items-center gap-1.5 py-2 px-4 rounded-[9px] text-[11.5px] font-bold no-underline transition-opacity hover:opacity-85" style="background:#0b1f4b;color:#ffffff;">
                                    <i class="fas fa-file-lines"></i> View My Applications
                                </a>
                                <a href="jobs.php" class="inline-flex items-center gap-1.5 py-2 px-4 rounded-[9px] text-[11.5px] font-bold no-underline transition-opacity hover:opacity-85" style="background:var(--card);border:1px solid var(--border);color:var(--text2);">
                                    <i class="fas fa-briefcase"></i> Browse More Jobs
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div id="getStartedBox" class="status-banner-box mx-4 my-4 rounded-xl py-3.5 px-4 border" style="--sb-bg:#eff6ff;--sb-border:#bfdbfe;--sb-dbg:rgba(59,130,246,.12);--sb-dbd:rgba(96,165,250,.35);background:var(--sb-bg);border-color:var(--sb-border);">
                            <h4 class="text-[12.5px] font-extrabold mb-1" style="color:var(--navy)">Get Started</h4>
                            <p class="text-[11.5px] font-medium leading-relaxed" style="color:var(--blue)">Browse job opportunities and apply for positions that match your qualifications.</p>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <button onclick="showBrowseWarningModal()" class="inline-flex items-center gap-1.5 py-2 px-4 rounded-[9px] text-[11.5px] font-bold border-none cursor-pointer transition-opacity hover:opacity-85" style="background:var(--blue);color:#fff;">
                                    <i class="fas fa-briefcase"></i> Browse Jobs
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- /status-card -->

                <?php if (!empty($doc_comments)): ?>
                    <div class="bg-[var(--card)] border border-[var(--border)] rounded-[14px] overflow-hidden opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.24s]">
                        <div class="flex items-center justify-between py-3 px-4 border-b border-[var(--border)] bg-[var(--surface)]">
                            <span class="text-[10.5px] font-bold tracking-[.07em] uppercase text-[var(--text2)] flex items-center gap-1.5">
                                <span class="w-[7px] h-[7px] rounded-full bg-[#d97706]"></span>
                                Document Comments
                            </span>
                            <span class="text-[9.5px] font-extrabold py-[3px] px-2 rounded-full bg-[#fee2e2] text-[#b91c1c]">
                                <?= $doc_comments_count ?> pending
                            </span>
                        </div>
                        <div class="p-2">
                            <?php foreach ($doc_comments as $group): ?>
                                <a href="my-applications.php?id=<?= (int) $group['application_id'] ?>"
                                    class="flex gap-2.5 py-2.5 px-2 rounded-[10px] no-underline text-inherit transition-colors hover:bg-[var(--surface)] border-b border-[var(--border)] last:border-b-0">
                                    <div class="w-8 h-8 rounded-lg bg-[#fef3c7] text-[#92400e] flex items-center justify-center flex-shrink-0 text-[12px]">
                                        <i class="fas fa-comment-dots"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[11.5px] font-bold text-[var(--text1)]">
                                            <?= htmlspecialchars($group['position_title'] ?? '—') ?>
                                        </div>
                                        <div class="text-[10.5px] text-[var(--text3)] font-medium mt-[1px]">
                                            <?= htmlspecialchars($group['rsf_number'] ?? '—') ?>
                                            &nbsp;·&nbsp;<?= htmlspecialchars($group['employer_name'] ?? '—') ?>
                                            &nbsp;·&nbsp;<?= htmlspecialchars($group['worksite_country'] ?? '—') ?>
                                        </div>

                                        <?php if (count($group['items']) === 1):
                                            $dc = $group['items'][0];
                                            $role_label = match ($dc['commenter_type'] ?? '') {
                                                'evaluator' => 'Evaluator',
                                                'admin'     => 'Admin',
                                                default     => 'Reviewer',
                                            };
                                            $doc_label = ucwords(str_replace('_', ' ', $dc['document_type'] ?? 'Document'));
                                        ?>
                                            <div class="text-[11px] text-[var(--text2)] italic mt-[3px]">
                                                <?= htmlspecialchars($doc_label) ?>: "<?= htmlspecialchars($dc['comment']) ?>"
                                            </div>
                                            <div class="text-[10px] text-[var(--text3)] mt-[2px]">
                                                <?= htmlspecialchars($role_label) ?> · <?= date('M d, Y g:i A', strtotime($dc['created_at'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <ul class="mt-[4px] pl-[16px] list-disc space-y-1">
                                                <?php foreach ($group['items'] as $dc):
                                                    $role_label = match ($dc['commenter_type'] ?? '') {
                                                        'evaluator' => 'Evaluator',
                                                        'admin'     => 'Admin',
                                                        default     => 'Reviewer',
                                                    };
                                                    $doc_label = ucwords(str_replace('_', ' ', $dc['document_type'] ?? 'Document'));
                                                ?>
                                                    <li class="text-[11px] text-[var(--text2)]">
                                                        <span class="italic"><?= htmlspecialchars($doc_label) ?>: "<?= htmlspecialchars($dc['comment']) ?>"</span>
                                                        <span class="text-[10px] text-[var(--text3)]"> — <?= htmlspecialchars($role_label) ?> · <?= date('M d, Y g:i A', strtotime($dc['created_at'])) ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div><!-- /lg:col-span-1 -->

        </div><!-- /two-column row -->

    </div><!-- /main -->


    <!--FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk active">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="jobs.php" class="dk ">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>

            <div class="dk-div"></div>

            <a href="my-applications.php" class="dk ">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?>
                    <span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span>
                <?php endif; ?>
            </a>

            <!-- <a href="notifications.php" class="dk">
                <i class="fas fa-bell"></i>
                <span class="dk-lbl">Alerts</span>
            </a> -->

            <div class="dk-div"></div>

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <div class="dk" onclick="logout('Applicant')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <!--BROWSE WARNING MODAL -->
    <div id="browseWarningModal" class="modal-overlay overlay-hidden fixed inset-0 bg-[rgba(11,31,75,0.6)] backdrop-blur-sm flex items-center justify-center z-[500] p-4">
        <div class="bg-white rounded-[18px] w-full max-w-[560px] max-h-[90vh] overflow-y-auto shadow-[0_24px_60px_rgba(11,31,75,0.3)]">
            <div class="flex items-center justify-between py-4 px-[18px] border-b border-[var(--border)] sticky top-0 bg-white rounded-t-[18px] z-[2]">
                <h3 class="font-['DM_Serif_Display'] text-base text-[var(--navy)] flex items-center gap-[9px]">
                    <span style="width:32px;height:32px;border-radius:9px;background:#fffbeb;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:14px;flex-shrink:0;">
                        <i class="fas fa-shield-halved"></i>
                    </span>
                    Before Browsing Jobs
                </h3>
                <button class="w-[30px] h-[30px] rounded-lg border border-[var(--border)] bg-[var(--surface)] text-[var(--text2)] text-[15px] flex items-center justify-center cursor-pointer transition-colors flex-shrink-0 hover:bg-[var(--border)]" onclick="closeBrowseModal()"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="p-[18px]">
                <p class="text-[12.5px] font-bold text-[#92400e] mb-1.5">Only use official and verified job orders!</p>
                <ul class="pl-[18px] mt-[10px]">
                    <li class="text-[12.5px] text-[var(--text2)] leading-relaxed mb-1.5">All positions come from <strong>approved RSF job orders</strong> issued by the <strong>Department of Migrant Workers (DMW)</strong>.</li>
                    <li class="text-[12.5px] text-[var(--text2)] leading-relaxed mb-1.5"><strong>NEVER PAY ANYTHING</strong> before deployment — legitimate employers do not require upfront fees.</li>
                    <li class="text-[12.5px] text-[var(--text2)] leading-relaxed mb-1.5">Beware of <strong>illegal recruiters</strong>, social media offers, and "fixers" asking for money outside official channels.</li>
                    <li class="text-[12.5px] text-[var(--text2)] leading-relaxed mb-1.5">Always read the full RSF PDF before applying — it contains complete job details, salary, and contract terms.</li>
                    <li class="text-[12.5px] text-[var(--text2)] leading-relaxed mb-1.5">If something seems suspicious, contact DMW Regional Office IV-A immediately.</li>
                </ul>
                <div class="bg-[#fffbeb] border border-[#fde68a] rounded-[11px] py-3 px-3.5 mt-3">
                    <p class="text-xs font-semibold text-[#92400e]">Hotline / Report Illegal Recruitment:</p>
                    <span class="text-[11.5px] text-[#92400e]">DMW Hotline: <strong>1348</strong> (nationwide) or <strong>(02) 8722-1144</strong></span><br>
                    <span class="text-[11px] text-[#78350f]">Official website: <strong>dmw.gov.ph</strong></span>
                </div>
                <div class="flex items-center gap-[9px] mt-3.5 pt-3 border-t border-[var(--border)]">
                    <input type="checkbox" id="dontShowAgain" class="w-4 h-4 cursor-pointer accent-[var(--blue)]">
                    <label for="dontShowAgain" class="text-xs text-[var(--text2)] font-medium cursor-pointer">Do not show this message again today</label>
                </div>
            </div>
            <div class="flex justify-end gap-2 py-[14px] px-[18px] border-t border-[var(--border)] bg-[var(--surface)] rounded-b-[18px]">
                <button class="py-[9px] px-5 rounded-[10px] text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--surface)] border border-[var(--border)] text-[var(--text2)]" onclick="closeBrowseModal()">Cancel</button>
                <button class="py-[9px] px-5 rounded-[10px] text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--blue)] text-white" id="proceedBtn">
                    Proceed to Jobs &nbsp;<i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        /*  Live date + time  */
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
        setInterval(updateLiveDateTime, 1000); // refresh every second

        /* Browse warning modal */
        function showBrowseWarningModal() {
            const lastShown = localStorage.getItem('browseWarningLastShown');
            const today = new Date().toDateString();
            if (lastShown === today) {
                window.location.href = 'jobs.php';
            } else {
                document.getElementById('browseWarningModal').classList.remove('overlay-hidden');
            }
        }

        function closeBrowseModal() {
            document.getElementById('browseWarningModal').classList.add('overlay-hidden');
        }

        document.getElementById('proceedBtn').addEventListener('click', function() {
            if (document.getElementById('dontShowAgain').checked) {
                localStorage.setItem('browseWarningLastShown', new Date().toDateString());
            }
            closeBrowseModal();
            window.location.href = 'jobs.php';
        });

        /* Close modal on backdrop click */
        document.getElementById('browseWarningModal').addEventListener('click', function(e) {
            if (e.target === this) this.classList.add('overlay-hidden');
        });

        /* Reassignment History tabs */
        function switchReassignTab(btn, aid) {
            document.querySelectorAll('.reassign-tab-btn').forEach(b => {
                b.classList.remove('text-white');
                b.classList.add('bg-[var(--surface)]', 'text-[var(--text2)]', 'border-[var(--border)]');
                b.style.background = '';
                b.style.borderColor = '';
            });
            btn.classList.remove('bg-[var(--surface)]', 'text-[var(--text2)]', 'border-[var(--border)]');
            btn.classList.add('text-white');
            btn.style.background = '#0b1f4b';
            btn.style.borderColor = '#0b1f4b';

            document.querySelectorAll('.reassign-tab-panel').forEach(p => p.classList.add('hidden'));
            const target = document.getElementById('reassign-panel-' + aid);
            if (target) target.classList.remove('hidden');
        }
    </script>

    <!--PROFILE COMPLETION MODAL -->
    <?php if ($show_profile_modal): ?>

        <div id="profileModal" class="modal-overlay fixed inset-0 bg-[rgba(11,31,75,0.6)] backdrop-blur-sm flex items-center justify-center p-4" style="z-index:600;">
            <div class="bg-white rounded-[18px] w-full max-h-[90vh] overflow-y-auto shadow-[0_24px_60px_rgba(11,31,75,0.3)]" style="max-width:600px;">
                <div class="flex items-center justify-between py-4 px-[18px] border-b border-[var(--border)] sticky top-0 bg-white rounded-t-[18px] z-[2]">
                    <h3 class="font-['DM_Serif_Display'] text-base text-[var(--navy)] flex items-center gap-[9px]">
                        <span style="width:32px;height:32px;border-radius:9px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:var(--blue);font-size:14px;flex-shrink:0;">
                            <i class="fas fa-id-card"></i>
                        </span>
                        Complete Your Applicant Profile
                    </h3>
                    <!-- No close button — must complete -->
                </div>
                <div class="p-[18px]">

                    <!-- Step indicator -->
                    <div class="flex items-center gap-0 mb-[22px]" id="profileStepIndicator">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--navy)] text-white" id="sn1">1</div>
                        <div class="flex-1 h-0.5 bg-[var(--border)] transition-colors" id="sl1"></div>
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--border)] text-[var(--text3)]" id="sn2">2</div>
                        <div class="flex-1 h-0.5 bg-[var(--border)] transition-colors" id="sl2"></div>
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--border)] text-[var(--text3)]" id="sn3">3</div>
                    </div>

                    <form id="profileForm">

                        <!-- ── STEP 1: Personal Info ── -->
                        <div class="block" id="stepPanel1">
                            <p class="font-['DM_Serif_Display'] text-[15px] text-[var(--navy)] mb-3.5">Personal Information</p>
                            <p class="text-[11.5px] text-[var(--text3)] mb-[18px] leading-relaxed">Please provide accurate details. These will be used to match you with job opportunities.</p>
                            <div class="grid grid-cols-1 min-[500px]:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Citizenship <span class="text-red-500">*</span></label>
                                    <input type="text" name="citizenship" value="Filipino" placeholder="e.g. Filipino" required
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Civil Status <span class="text-red-500">*</span></label>
                                    <select name="civil_status" required
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                        <option value="">Select…</option>
                                        <option>Single</option>
                                        <option>Married</option>
                                        <option>Widowed</option>
                                        <option>Separated</option>
                                        <option>Annulled</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Religion</label>
                                    <select name="religion" id="religionSelect"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                        <option value="">Select…</option>
                                        <option>Catholic</option>
                                        <option>Iglesia ni Cristo</option>
                                        <option>Islam</option>
                                        <option>Protestant</option>
                                        <option>Born Again Christian</option>
                                        <option>Seventh-day Adventist</option>
                                        <option>Jehovah's Witness</option>
                                        <option>Buddhist</option>
                                        <option value="Others">Others</option>
                                    </select>
                                    <input type="text" name="religion_other" id="religionOtherInput"
                                        placeholder="Please specify your religion"
                                        class="hidden mt-2 w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div class="col-span-full grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Height (cm) <span class="text-red-500">*</span></label>
                                        <input type="number" name="height_cm" placeholder="e.g. 165" min="100" max="250" step="0.5" required
                                            class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                    </div>
                                    <div>
                                        <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Weight (kg) <span class="text-red-500">*</span></label>
                                        <input type="number" name="weight_kg" placeholder="e.g. 60" min="30" max="300" step="0.5" required
                                            class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!--  STEP 2: Education  -->
                        <div class="hidden" id="stepPanel2">
                            <p class="font-['DM_Serif_Display'] text-[15px] text-[var(--navy)] mb-3.5">Educational Background</p>
                            <p class="text-[11.5px] text-[var(--text3)] mb-[18px] leading-relaxed">This information is required for evaluating your eligibility for specific job orders.</p>
                            <div class="grid grid-cols-1 min-[500px]:grid-cols-2 gap-3">
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Highest Educational Attainment <span class="text-red-500">*</span></label>
                                    <select name="educational_attainment" id="eduSelect" required
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                        <option value="">Select…</option>
                                        <option>Elementary Graduate</option>
                                        <option>High School Graduate</option>
                                        <option>Senior High School Graduate</option>
                                        <option>Vocational/Technical</option>
                                        <option>College Undergraduate</option>
                                        <option>College Graduate</option>
                                        <option>Post Graduate</option>
                                    </select>
                                </div>
                                <div class="col-span-full hidden" id="courseDegreeField">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Course / Degree</label>
                                    <select name="degree_id" id="degreeSelect"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                        <option value="">Select…</option>
                                        <?php foreach ($degreeGroups as $category => $degrees): ?>
                                            <optgroup label="<?= htmlspecialchars($category) ?>">
                                                <?php foreach ($degrees as $d): ?>
                                                    <option value="<?= (int) $d['id'] ?>"
                                                        data-name="<?= htmlspecialchars($d['degree_name'], ENT_QUOTES) ?>"
                                                        <?= strcasecmp($d['degree_name'], 'Others') === 0 ? 'data-others="1"' : '' ?>>
                                                        <?= htmlspecialchars($d['degree_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="course_degree" id="courseDegreeInput"
                                        placeholder="Please specify your course/degree"
                                        class="hidden mt-2 w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">School / Institution Name</label>
                                    <input type="text" name="school_name" placeholder="e.g. City College of Calamba"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">School Address</label>
                                    <input type="text" name="school_address" placeholder="e.g. Calamba City, Laguna"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Year Graduated</label>
                                    <input type="number" name="year_graduated" placeholder="e.g. 2022" min="1970" max="<?= date('Y') ?>"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                            </div>
                        </div>

                        <!--  STEP 3: Work Experience  -->
                        <div class="hidden" id="stepPanel3">
                            <p class="font-['DM_Serif_Display'] text-[15px] text-[var(--navy)] mb-3.5">Work Experience</p>
                            <p class="text-[11.5px] text-[var(--text3)] mb-[18px] leading-relaxed">If you have no prior work experience, you may leave these fields blank.</p>
                            <div class="grid grid-cols-1 min-[500px]:grid-cols-2 gap-3">
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Years of Work Experience</label>
                                    <input type="number" name="work_experience_years" placeholder="e.g. 3" min="0" max="50"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Last / Current Employer</label>
                                    <input type="text" name="last_employer" placeholder="e.g. ABC Manufacturing Corp."
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Last / Current Position</label>
                                    <input type="text" name="last_position" placeholder="e.g. Production Operator"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)] box-border">
                                </div>
                            </div>
                        </div>

                    </form><!-- profileForm -->

                    <div id="profileError" class="hidden bg-red-100 border border-red-200 rounded-[9px] py-[10px] px-[13px] mt-3 text-xs font-semibold text-red-800"></div>

                </div><!-- /modal-body -->
                <div class="flex justify-end gap-2 py-[14px] px-[18px] border-t border-[var(--border)] bg-[var(--surface)] rounded-b-[18px]" id="profileModalFooter">
                    <button id="profileBackBtn" style="display:none;" onclick="profileStep(-1)"
                        class="py-[9px] px-5 rounded-[10px] text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--surface)] border border-[var(--border)] text-[var(--text2)]">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button id="profileNextBtn" onclick="profileStep(1)"
                        class="py-[9px] px-5 rounded-[10px] text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--blue)] text-white">
                        Next &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const TOTAL_STEPS = 3;
                let currentStep = 1;

                const STEP_NODE_BASE = 'w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors';
                const STEP_NODE_DONE = STEP_NODE_BASE + ' bg-[var(--blue)] text-white';
                const STEP_NODE_ACTIVE = STEP_NODE_BASE + ' bg-[var(--navy)] text-white';
                const STEP_NODE_IDLE = STEP_NODE_BASE + ' bg-[var(--border)] text-[var(--text3)]';
                const STEP_LINE_BASE = 'flex-1 h-0.5 bg-[var(--border)] transition-colors';
                const STEP_LINE_DONE = 'flex-1 h-0.5 bg-[var(--blue)] transition-colors';

                // Edu field toggle
                document.getElementById('eduSelect').addEventListener('change', function() {
                    const showCourse = ['College Undergraduate', 'College Graduate', 'Post Graduate',
                        'Vocational/Technical', 'Senior High School Graduate'
                    ].includes(this.value);
                    document.getElementById('courseDegreeField').classList.toggle('hidden', !showCourse);
                });

                // Degree picker: auto-fill course_degree from the selected degree name,
                // or reveal a free-text field when "Others" is chosen.
                document.getElementById('degreeSelect').addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    const input = document.getElementById('courseDegreeInput');
                    if (!this.value) {
                        input.classList.add('hidden');
                        input.value = '';
                        return;
                    }
                    if (opt.dataset.others === '1') {
                        input.classList.remove('hidden');
                        input.value = '';
                        input.placeholder = 'Please specify your course/degree';
                        input.focus();
                    } else {
                        input.classList.add('hidden');
                        input.value = opt.dataset.name || '';
                    }
                });

                /* 
                  CUSTOM SELECT — matches the styled dropdown used
                  elsewhere in this app (jobs.php, profile.php). The
                  original <select> stays in the DOM (hidden) as the
                  source of truth, so the eduSelect/degreeSelect
                  'change' listeners above keep firing exactly as
                  before — our trigger dispatches a real 'change'
                  event whenever a row is picked. */
                function enhanceSelect(select, opts) {
                    opts = opts || {};
                    const searchable = opts.searchable !== false && select.options.length > 8;
                    const openUp = opts.openUp === true;
                    const placeholderOpt = select.querySelector('option[value=""]');
                    const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

                    const wrapper = document.createElement('div');
                    wrapper.className = 'relative';
                    select.parentNode.insertBefore(wrapper, select);
                    wrapper.appendChild(select);
                    select.classList.add('hidden');
                    select.tabIndex = -1;

                    const trigger = document.createElement('button');
                    trigger.type = 'button';
                    trigger.setAttribute('aria-haspopup', 'listbox');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.className = 'w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] bg-white flex items-center justify-between gap-2 cursor-pointer transition-colors hover:border-[var(--blue-lt)] box-border';
                    trigger.innerHTML = '<span class="cs-label truncate text-[var(--text3)]"></span>' +
                        '<i class="fas fa-chevron-down text-[10px] text-[var(--text3)] transition-transform flex-shrink-0"></i>';
                    wrapper.appendChild(trigger);

                    const panel = document.createElement('div');
                    panel.setAttribute('role', 'listbox');
                    panel.className = 'hidden absolute left-0 right-0 ' + (openUp ? 'bottom-full mb-1.5' : 'mt-1.5') + ' bg-white border-[1.5px] border-[var(--border)] rounded-[9px] shadow-[0_10px_30px_rgba(11,31,75,.16)] z-[80] overflow-hidden';
                    let searchInput = null;
                    if (searchable) {
                        panel.innerHTML =
                            '<div class="p-2 border-b border-[var(--border)]">' +
                            '  <div class="relative">' +
                            '    <i class="fas fa-magnifying-glass absolute left-2.5 top-1/2 -translate-y-1/2 text-[10px] text-[var(--text3)]"></i>' +
                            '    <input type="text" class="cs-search w-full py-1.5 pl-7 pr-2 border border-[var(--border)] rounded-md text-[11.5px] outline-none focus:border-[var(--blue-lt)]" placeholder="Search…">' +
                            '  </div>' +
                            '</div>' +
                            '<div class="cs-list max-h-[210px] overflow-y-auto py-1"></div>';
                    } else {
                        panel.innerHTML = '<div class="cs-list max-h-[210px] overflow-y-auto py-1"></div>';
                    }
                    wrapper.appendChild(panel);
                    const list = panel.querySelector('.cs-list');
                    if (searchable) searchInput = panel.querySelector('.cs-search');

                    function buildOptionRow(optionEl) {
                        const row = document.createElement('div');
                        row.setAttribute('role', 'option');
                        row.className = 'flex items-center justify-between gap-2 py-[7px] px-3 text-[12.5px] text-[var(--text1)] cursor-pointer hover:bg-[#f1f5fb] transition-colors';
                        row.dataset.value = optionEl.value;
                        const label = document.createElement('span');
                        label.className = 'truncate';
                        label.textContent = optionEl.textContent;
                        row.appendChild(label);
                        row.addEventListener('click', () => {
                            select.value = optionEl.value;
                            closePanel();
                            refresh();
                            select.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        });
                        return row;
                    }

                    function renderList(filterText) {
                        list.innerHTML = '';
                        const filter = (filterText || '').trim().toLowerCase();
                        let any = false;
                        Array.from(select.children).forEach(child => {
                            if (child.tagName === 'OPTGROUP') {
                                const items = Array.from(child.children).filter(o => !filter || o.textContent.toLowerCase().includes(filter));
                                if (!items.length) return;
                                any = true;
                                const groupLabel = document.createElement('div');
                                groupLabel.className = 'px-3 pt-2 pb-1 text-[9.5px] font-bold uppercase tracking-[.05em] text-[var(--text3)]';
                                groupLabel.textContent = child.label;
                                list.appendChild(groupLabel);
                                items.forEach(o => list.appendChild(buildOptionRow(o)));
                            } else if (child.tagName === 'OPTION') {
                                if (child.value === '') return;
                                if (filter && !child.textContent.toLowerCase().includes(filter)) return;
                                any = true;
                                list.appendChild(buildOptionRow(child));
                            }
                        });
                        if (!any) {
                            const empty = document.createElement('div');
                            empty.className = 'py-4 text-center text-[11px] text-[var(--text3)]';
                            empty.textContent = 'No matches found.';
                            list.appendChild(empty);
                        }
                        markSelected();
                    }

                    function markSelected() {
                        list.querySelectorAll('[role="option"]').forEach(row => {
                            const selected = select.value !== '' && row.dataset.value === select.value;
                            row.classList.toggle('bg-[#eff6ff]', selected);
                            row.classList.toggle('text-[var(--navy)]', selected);
                            row.classList.toggle('font-bold', selected);
                            let check = row.querySelector('.fa-check');
                            if (selected && !check) {
                                const icon = document.createElement('i');
                                icon.className = 'fas fa-check text-[10px] text-[var(--blue)] flex-shrink-0';
                                row.appendChild(icon);
                            } else if (!selected && check) {
                                check.remove();
                            }
                        });
                    }

                    function refresh() {
                        const opt = select.options[select.selectedIndex];
                        const label = trigger.querySelector('.cs-label');
                        if (select.value === '' || !opt) {
                            label.textContent = placeholder;
                            label.classList.add('text-[var(--text3)]');
                            label.classList.remove('text-[var(--text1)]', 'font-semibold');
                        } else {
                            label.textContent = opt.textContent;
                            label.classList.remove('text-[var(--text3)]');
                            label.classList.add('text-[var(--text1)]', 'font-semibold');
                        }
                        markSelected();
                    }

                    function openPanel() {
                        document.querySelectorAll('[role="listbox"]').forEach(p => {
                            if (p !== panel) p.classList.add('hidden');
                        });
                        panel.classList.remove('hidden');
                        trigger.classList.add('border-[var(--blue-lt)]');
                        trigger.setAttribute('aria-expanded', 'true');
                        trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
                        renderList('');
                        if (searchInput) {
                            searchInput.value = '';
                            setTimeout(() => searchInput.focus(), 10);
                        }
                    }

                    function closePanel() {
                        panel.classList.add('hidden');
                        trigger.classList.remove('border-[var(--blue-lt)]');
                        trigger.setAttribute('aria-expanded', 'false');
                        trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
                    }

                    trigger.addEventListener('click', (e) => {
                        e.stopPropagation();
                        panel.classList.contains('hidden') ? openPanel() : closePanel();
                    });
                    trigger.addEventListener('keydown', (e) => {
                        if (['Enter', ' ', 'ArrowDown'].includes(e.key)) {
                            e.preventDefault();
                            openPanel();
                        }
                    });
                    panel.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') {
                            closePanel();
                            trigger.focus();
                        }
                    });
                    panel.addEventListener('click', (e) => e.stopPropagation());
                    if (searchInput) {
                        searchInput.addEventListener('input', () => renderList(searchInput.value));
                        searchInput.addEventListener('click', (e) => e.stopPropagation());
                    }
                    document.addEventListener('click', closePanel);

                    refresh();
                    return {
                        refresh,
                        closePanel
                    };
                }

                enhanceSelect(document.querySelector('[name="civil_status"]'), {
                    searchable: false
                });
                enhanceSelect(document.getElementById('religionSelect'), {
                    searchable: false,
                    openUp: true
                });
                enhanceSelect(document.getElementById('eduSelect'), {
                    searchable: false
                });
                enhanceSelect(document.getElementById('degreeSelect'), {
                    searchable: true
                });

                function updateStepUI() {
                    for (let i = 1; i <= TOTAL_STEPS; i++) {
                        document.getElementById('stepPanel' + i).classList.toggle('hidden', i !== currentStep);
                        const node = document.getElementById('sn' + i);
                        if (i < currentStep) node.className = STEP_NODE_DONE;
                        else if (i === currentStep) node.className = STEP_NODE_ACTIVE;
                        else node.className = STEP_NODE_IDLE;
                        if (i < TOTAL_STEPS) {
                            document.getElementById('sl' + i).className = i < currentStep ? STEP_LINE_DONE : STEP_LINE_BASE;
                        }
                    }
                    document.getElementById('profileBackBtn').style.display = currentStep > 1 ? '' : 'none';
                    const nextBtn = document.getElementById('profileNextBtn');
                    if (currentStep === TOTAL_STEPS) {
                        nextBtn.innerHTML = '<i class="fas fa-check"></i> Submit Profile';
                    } else {
                        nextBtn.innerHTML = 'Next &nbsp;<i class="fas fa-arrow-right"></i>';
                    }
                }

                function validateStep(step) {
                    const panel = document.getElementById('stepPanel' + step);
                    const required = panel.querySelectorAll('[required]');
                    for (const el of required) {
                        if (!el.value.trim()) {
                            el.focus();
                            showError('Please fill in all required fields (marked with *).');
                            return false;
                        }
                    }

                    const yearField = panel.querySelector('[name="year_graduated"]');
                    if (yearField && yearField.value.trim()) {
                        const currentYear = new Date().getFullYear();
                        const enteredYear = parseInt(yearField.value, 10);
                        if (isNaN(enteredYear) || enteredYear > currentYear) {
                            yearField.focus();
                            showError('Year Graduated cannot be later than the current year (' + currentYear + ').');
                            return false;
                        }
                    }

                    hideError();
                    return true;
                }

                function showError(msg) {
                    const el = document.getElementById('profileError');
                    el.textContent = msg;
                    el.classList.remove('hidden');
                }

                function hideError() {
                    document.getElementById('profileError').classList.add('hidden');
                }

                window.profileStep = function(dir) {
                    if (dir === 1) {
                        if (!validateStep(currentStep)) return;
                        if (currentStep === TOTAL_STEPS) {
                            submitProfile();
                            return;
                        }
                        currentStep++;
                    } else {
                        currentStep = Math.max(1, currentStep - 1);
                    }
                    updateStepUI();
                };

                function submitProfile() {
                    const btn = document.getElementById('profileNextBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

                    const formData = new FormData(document.getElementById('profileForm'));

                    fetch('complete-profile.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('profileModal').style.display = 'none';
                                // Reload to refresh dashboard state
                                window.location.replace('index.php');
                            } else {
                                showError(data.message || 'Something went wrong. Please try again.');
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fas fa-check"></i> Submit Profile';
                            }
                        })
                        .catch(() => {
                            showError('Network error. Please check your connection and try again.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Submit Profile';
                        });
                }

                updateStepUI();

                // Prevent closing modal on backdrop click
                document.getElementById('profileModal').addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            })();
        </script>
    <?php endif; ?>

    <!-- Lock overlay for incomplete profile on interactive elements -->
    <?php if ($show_profile_modal): ?>
        <script>
            // Override Browse Jobs button & jobs.php / my-applications.php links
            // to redirect back to modal instead of navigating away
            document.addEventListener('DOMContentLoaded', function() {
                // Replace Browse Jobs warning modal trigger
                window.showBrowseWarningModal = function() {
                    document.getElementById('profileModal').style.display = 'flex';
                };

                // Intercept all navigation links to gated pages
                const gated = ['jobs.php', 'my-applications.php'];
                document.querySelectorAll('a').forEach(function(a) {
                    const href = (a.getAttribute('href') || '');
                    if (gated.some(g => href.includes(g))) {
                        a.addEventListener('click', function(e) {
                            e.preventDefault();
                            document.getElementById('profileModal').style.display = 'flex';
                        });
                    }
                });
            });
        </script>
    <?php endif; ?>

    <!-- Lock banner injected into page when profile incomplete -->
    <?php if ($show_profile_modal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const main = document.getElementById('mainContent');
                if (!main) return;
                const banner = document.createElement('div');
                banner.className = 'opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] bg-[#fff7ed] border-[1.5px] border-[#fed7aa] rounded-xl py-3.5 px-4 mb-3 flex items-start gap-3';
                banner.innerHTML = `
            <i class="fas fa-lock text-[#f97316] text-lg mt-0.5"></i>
            <div>
                <h4 class="text-[12.5px] font-extrabold text-[#9a3412]">Complete Your Profile to Unlock the Dashboard</h4>
                <p class="text-[11.5px] text-[#c2410c] mt-[3px] leading-relaxed">You must fill in your applicant profile before you can browse jobs or submit applications. This ensures accurate matching with available job orders.</p>
                <button class="mt-2.5 bg-[var(--navy)] text-white border-none py-2 px-4 rounded-[9px] text-xs font-bold cursor-pointer" onclick="document.getElementById('profileModal').style.display='flex'">
                    <i class="fas fa-id-card"></i> &nbsp; Complete My Profile
                </button>
            </div>
        `;
                main.insertBefore(banner, main.firstChild);
            });
        </script>
    <?php endif; ?>

    <script type="module" src="../js/main.js"></script>
    <?php
    if (!$profile_complete) {
        $login_toast_title = 'Welcome, ' . htmlspecialchars($first, ENT_QUOTES) . '!';
    }
    include '../includes/login_toast.php';
    ?>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
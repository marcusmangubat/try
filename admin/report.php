<?php

/**
 * report.php – DMW RO IV-A Admin Dashboard
 * Printable / PDF-exportable listing of whatever the admin currently has
 * filtered on dashboard.php (month, province, job type, destination
 * country). Styled to match application-detail.php's document/report
 * design system so every official DMW export looks consistent.
 */
session_start();
require_once '../db.php';

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

// These are only used to pre-render the filter summary before JS kicks in;
// the actual data always comes from dashboard-api.php so it can never
// drift from what's really in the database.
$qMonth    = trim($_GET['month'] ?? '');
$qProvince = trim($_GET['province'] ?? '');
$qJobType  = trim($_GET['job_type'] ?? '');
$qCountry  = trim($_GET['country'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Application Report – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        /*DESIGN SYSTEM — shared with application-detail.php so every
           official DMW document (certificates, case reports, listings)
           looks like it came from the same system. */
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

        .btn[disabled] {
            opacity: .55;
            cursor: not-allowed;
        }

        .sheet {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 16px 60px;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        /* ── Document sheet ── */
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
            margin-bottom: 16px;
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

        /* ── Summary panel (matches detail page's Case Summary) ── */
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

        .section-hint {
            font-size: 11.5px;
            color: var(--text3);
            font-style: italic;
            margin: -4px 0 10px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
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
            white-space: nowrap;
        }

        table.detail-table td {
            padding: 8px 10px;
            border: 1px solid var(--bdr);
            vertical-align: top;
        }

        table.detail-table tbody tr:nth-child(even) {
            background: #fbfcfe;
        }

        [data-theme="dark"] .section-title {
            background: var(--navy-soft);
            color: #f8fafc;
        }

        .empty-note,
        .loading-state {
            font-size: 12px;
            color: var(--text3);
            font-style: italic;
            padding: 30px 0;
            text-align: center;
        }

        .footer-note {
            font-size: 10px;
            color: var(--text3);
            text-align: center;
            border-top: 1px solid var(--bdr);
            padding-top: 10px;
            margin-top: 24px;
        }

        /* ============================================================
           PRINT / PDF REPORT — separate, print-optimized layout.
           Hidden on screen; shown only for window.print() (@media print
           below) and for html2pdf export (toggled by JS in
           exportToPdf(), since html2canvas ignores @media print).
           ============================================================ */
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
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 6px 14px;
            align-items: center;
            font-size: 8.5pt;
            color: #4b5563;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }

        #printReport .pr-intro {
            font-size: 9pt;
            line-height: 1.6;
            color: #374151;
            text-align: justify;
            margin: 0 0 12px;
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

        #printReport .pr-section-hint {
            font-size: 7.8pt;
            color: #9ca3af;
            font-style: italic;
            margin: -5px 0 6px;
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
            white-space: nowrap;
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

        #printReport .pr-signoff {
            margin-top: 40px;
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
        [data-theme="dark"] .loading-state,
        [data-theme="dark"] .footer-note {
            color: var(--text2);
        }

        [data-theme="dark"] .pill {
            background: var(--surface2);
            border-color: var(--bdr);
            color: var(--text1);
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

        [data-theme="dark"] table.detail-table {
            border-color: var(--bdr);
        }

        [data-theme="dark"] table.detail-table th {
            background: var(--lbl-bg);
            color: var(--text2);
            border-color: var(--bdr);
        }

        [data-theme="dark"] table.detail-table td {
            border-color: var(--bdr);
            color: var(--text1);
        }

        [data-theme="dark"] table.detail-table tbody tr:nth-child(even) {
            background: #202f45;
        }

        /* Badges — same tint-not-solid approach used elsewhere in the project */
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
            <?php include __DIR__ . '/avatar-menu.php'; ?>
        </div>
    </header>

    <div class="sheet">

        <div class="toolbar-actions no-print">
            <a href="dashboard.php"><button class="btn" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back</button></a>
            <button class="btn" id="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button class="btn btn-primary" id="btn-pdf" onclick="exportToPdf()"><i class="fas fa-file-pdf"></i> Export to PDF</button>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             WEB VIEW — interactive on-screen layout.
             ══════════════════════════════════════════════════════════ -->
        <div class="webView">
            <div class="doc-sheet">

                <div class="doc-header-table">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW logo">
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
                <div class="doc-subtitle">Generated by <?= htmlspecialchars($admin_name) ?></div>

                <table class="ref-table">
                    <tr>
                        <td class="ref-label">Filters Applied:</td>
                        <td id="web-filters">—</td>
                    </tr>
                    <tr>
                        <td class="ref-label">Generated At:</td>
                        <td id="web-generated">—</td>
                    </tr>
                </table>

                <p class="intro-note">
                    This report presents all applications currently matching the selected filters, generated directly
                    from the Application Management System's live database. It is intended to give the reviewing
                    officer a consolidated, exportable view of applicant status, placement, and processing progress
                    for monitoring, auditing, or reporting purposes.
                </p>

                <div class="summary-panel" id="web-summary">
                    <div class="summary-cell">
                        <div class="sc-num">—</div>
                        <div class="sc-lbl">Total Applications</div>
                    </div>
                </div>

                <div class="section">
                    <div class="section-title"><i class="fas fa-table-list"></i> Application Records</div>
                    <p class="section-hint">Every application matching the current filters, most recently submitted first is not guaranteed — order reflects the underlying dataset.</p>
                    <div id="web-body">
                        <div class="loading-state"><i class="fas fa-spinner fa-spin"></i> Loading applications&hellip;</div>
                    </div>
                </div>

                <p class="footer-note" id="web-footer">Department of Migrant Workers — Application Management System</p>

            </div>
        </div>

        <!-- PRINT / PDF REPORT — populated by JS, matches
             application-detail.php's print layout. -->
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
                <p>Generated by <?= htmlspecialchars($admin_name) ?></p>
            </div>

            <div class="pr-meta-bar">
                <span id="pr-filters">Filters: —</span>
                <span id="pr-generated">Generated: —</span>
                <span id="pr-count">Total records: —</span>
            </div>

            <p class="pr-intro">
                This report presents all applications currently matching the selected filters, generated directly
                from the Application Management System's live database. It is intended to give the reviewing
                officer a consolidated, exportable view of applicant status, placement, and processing progress
                for monitoring, auditing, or reporting purposes.
            </p>

            <table class="pr-summary-panel" id="pr-summary">
                <tr>
                    <td>
                        <span class="sc-num">—</span>
                        <span class="sc-lbl">Total Applications</span>
                    </td>
                </tr>
            </table>

            <div class="pr-section">
                <div class="pr-section-title">Application Records</div>
                <p class="pr-section-hint">Every application matching the current filters, as of the generation timestamp above.</p>
                <div id="pr-body">
                    <p class="pr-empty">Loading…</p>
                </div>
            </div>

            <div class="pr-signoff">
                <div class="pr-sig-line">
                    <div class="line">Prepared By</div>
                </div>
                <div class="pr-sig-line">
                    <div class="line">Certified By, DMW RO IV-A</div>
                </div>
            </div>

            <div class="pr-footer">
                <div class="pr-footer-columns">
                    <div class="pr-footer-left">
                        <p class="pr-footer-disclaimer">
                            This is an official system-generated report from the DMW Application Management System —
                            Regional Office IV-A (CALABARZON). Information is confidential and intended solely for
                            internal monitoring, compliance review, and official reporting.
                        </p>
                    </div>
                    <div class="pr-footer-right">
                        <span>Department of Migrant Workers - RO IV-A (CALABARZON)</span>
                        <span id="pr-footer-filters">Filters: —</span>
                        <span>System-Generated Document • For Official Use Only</span>
                        <span id="pr-footer-date"><?= date('M j, Y g:i A') ?></span>
                    </div>
                </div>
            </div>

        </div><!-- /#printReport -->

    </div><!-- /.sheet -->

    <script>
        const API = 'dashboard-api.php';

        const STATUS_LABELS = {
            pending: 'Pending',
            unassign: 'Unassigned',
            unassigned: 'Unassigned',
            assign: 'Assigned',
            assigned: 'Assigned',
            under_evaluation: 'Under Evaluation',
            for_compliance: 'For Compliance',
            resubmitted: 'Resubmitted',
            approved: 'Approved',
            disapproved: 'Disapproved',
        };

        const FILTER_LABELS = {
            month: 'Month',
            province: 'Province',
            job_type: 'Job Type',
            country: 'Destination'
        };

        function statusLabel(status) {
            if (STATUS_LABELS[status]) return STATUS_LABELS[status];
            return String(status ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || '—';
        }

        function badgeClass(status) {
            switch (status) {
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

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [m]));
        }

        function fmtDate(s) {
            if (!s) return '—';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        function fmtDateTime(s) {
            if (!s) return '—';
            const d = (s instanceof Date) ? s : new Date(String(s).replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        function api(action, params = {}) {
            const qs = new URLSearchParams({
                action,
                ...params
            }).toString();
            return fetch(`${API}?${qs}`).then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            });
        }

        function getFiltersFromUrl() {
            const p = new URLSearchParams(window.location.search);
            const f = {};
            ['month', 'province', 'job_type', 'country'].forEach(k => {
                const v = p.get(k);
                if (v) f[k] = v;
            });
            return f;
        }

        function fmtMonthLabel(value) {
            // Expects "YYYY-MM" (e.g. "2026-08") as passed from dashboard.php's month filter.
            const match = /^(\d{4})-(\d{2})$/.exec(value);
            if (!match) return value; // fallback: show as-is if format is unexpected
            const [, year, month] = match;
            const d = new Date(Number(year), Number(month) - 1, 1);
            return d.toLocaleDateString('en-US', {
                month: 'long',
                year: 'numeric'
            }); // "August 2026"
        }

        function filterSummaryText(filters) {
            const applied = Object.entries(filters || {}).filter(([, v]) => v);
            if (!applied.length) return 'None (all applications)';
            return applied.map(([k, v]) => {
                const value = k === 'month' ? fmtMonthLabel(v) : v;
                return `${FILTER_LABELS[k] || k}: ${value}`;
            }).join('  •  ');
        }

        function computeStats(rows) {
            const total = rows.length;
            let approved = 0,
                disapproved = 0;
            rows.forEach(r => {
                if (r.status === 'approved') approved++;
                else if (r.status === 'disapproved') disapproved++;
            });
            return {
                total,
                approved,
                disapproved,
                inProcess: total - approved - disapproved
            };
        }

        function buildRowsHtml(rows) {
            return rows.map(r => `
                <tr>
                    <td>${escapeHtml(r.applicant)}</td>
                    <td>${escapeHtml(r.province)}${r.city ? ', ' + escapeHtml(r.city) : ''}</td>
                    <td>${escapeHtml(r.position_title)}</td>
                    <td>${escapeHtml(r.employer_name)}</td>
                    <td>${escapeHtml(r.country)}</td>
                    <td><span class="badge ${badgeClass(r.status)}">${escapeHtml(statusLabel(r.status))}</span></td>
                    <td>${fmtDate(r.submitted_at)}</td>
                </tr>`).join('');
        }

        function renderWeb(data, stats) {
            document.getElementById('web-filters').textContent = filterSummaryText(data.filters);
            document.getElementById('web-generated').textContent = fmtDateTime(data.generated_at);

            document.getElementById('web-summary').innerHTML = `
                <div class="summary-cell">
                    <div class="sc-num">${stats.total}</div>
                    <div class="sc-lbl">Total Applications</div>
                </div>
                <div class="summary-cell">
                    <div class="sc-num">${stats.approved}</div>
                    <div class="sc-lbl">Approved</div>
                </div>
                <div class="summary-cell ${stats.disapproved > 0 ? 'sc-warn' : ''}">
                    <div class="sc-num">${stats.disapproved}</div>
                    <div class="sc-lbl">Disapproved</div>
                </div>
                <div class="summary-cell">
                    <div class="sc-num">${stats.inProcess}</div>
                    <div class="sc-lbl">Still In Process</div>
                </div>`;

            const body = document.getElementById('web-body');
            if (!data.rows || !data.rows.length) {
                body.innerHTML = '<p class="empty-note"><i class="fas fa-inbox"></i> No applications match these filters.</p>';
            } else {
                body.innerHTML = `
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Province / City</th>
                                <th>Position</th>
                                <th>Employer</th>
                                <th>Destination</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>${buildRowsHtml(data.rows)}</tbody>
                    </table>`;
            }

            document.getElementById('web-footer').textContent =
                `Department of Migrant Workers — Application Management System  |  Total records: ${stats.total}  |  For official use only`;
        }

        function renderPrint(data, stats) {
            const filterText = filterSummaryText(data.filters);
            document.getElementById('pr-filters').textContent = `Filters: ${filterText}`;
            document.getElementById('pr-generated').textContent = `Generated: ${fmtDateTime(data.generated_at)}`;
            document.getElementById('pr-count').textContent = `Total records: ${stats.total}`;
            document.getElementById('pr-footer-filters').textContent = `Filters: ${filterText}`;

            document.getElementById('pr-summary').innerHTML = `
                <tr>
                    <td>
                        <span class="sc-num">${stats.total}</span>
                        <span class="sc-lbl">Total Applications</span>
                    </td>
                    <td>
                        <span class="sc-num">${stats.approved}</span>
                        <span class="sc-lbl">Approved</span>
                    </td>
                    <td>
                        <span class="sc-num">${stats.disapproved}</span>
                        <span class="sc-lbl">Disapproved</span>
                    </td>
                    <td>
                        <span class="sc-num">${stats.inProcess}</span>
                        <span class="sc-lbl">Still In Process</span>
                    </td>
                </tr>`;

            const body = document.getElementById('pr-body');
            if (!data.rows || !data.rows.length) {
                body.innerHTML = '<p class="pr-empty">No applications match these filters.</p>';
            } else {
                body.innerHTML = `
                    <table class="pr-table">
                        <thead>
                            <tr>
                                <th style="width:20%;">Applicant</th>
                                <th style="width:15%;">Province / City</th>
                                <th style="width:17%;">Position</th>
                                <th style="width:17%;">Employer</th>
                                <th style="width:11%;">Destination</th>
                                <th style="width:11%;">Status</th>
                                <th style="width:9%;">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>${buildRowsHtml(data.rows)}</tbody>
                    </table>`;
            }
        }

        async function loadReport() {
            const filters = getFiltersFromUrl();
            const generatedAt = new Date(); // captured on the client, at the moment the report loads

            let data;
            try {
                data = await api('report', filters);
            } catch (err) {
                document.getElementById('web-body').innerHTML =
                    `<p class="empty-note"><i class="fas fa-triangle-exclamation"></i> Couldn't load the report (${escapeHtml(err.message)}).</p>`;
                document.getElementById('pr-body').innerHTML =
                    `<p class="pr-empty">Couldn't load the report (${escapeHtml(err.message)}).</p>`;
                document.getElementById('btn-pdf').disabled = true;
                return;
            }
            data.filters = data.filters || filters;
            data.generated_at = generatedAt; // override server timestamp with local one
            const stats = computeStats(data.rows || []);
            renderWeb(data, stats);
            renderPrint(data, stats);
        }

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

        // Converts a same-origin image URL into a base64 data URI so the DMW
        // logo is guaranteed to be fully available as pixel data before
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
            const btn = document.getElementById('btn-pdf');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            btn.disabled = true;

            const webView = document.querySelector('.webView');
            const printEl = document.getElementById('printReport');
            const printLogo = document.getElementById('printLogo');
            const prHeader = printEl.querySelector('.pr-page-header');
            const originalLogoSrc = printLogo ? printLogo.getAttribute('src') : null;
            const filename = `Application_Report_${new Date().toISOString().slice(0, 10)}.pdf`;

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
                    .from(printEl)
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
                                `Application Report | Page ${i} of ${totalPages}`,
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

        loadReport();
    </script>
    <script src="../js/theme.js"></script>
    <script type="module" src="../js/main.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
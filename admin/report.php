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
    <script>
        // Dark mode follows the app's existing data-theme="dark" switch.
        // Design tokens map to the CSS variables defined in the <style> block below,
        // so light/dark colors stay in one place.
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]'],
            theme: {
                extend: {
                    colors: {
                        navy: {
                            DEFAULT: 'var(--navy)',
                            soft: 'var(--navy-soft)'
                        },
                        ink: {
                            1: 'var(--text1)',
                            2: 'var(--text2)',
                            3: 'var(--text3)'
                        },
                        line: 'var(--bdr)',
                        lbl: 'var(--lbl-bg)',
                        surf: {
                            DEFAULT: 'var(--surface)',
                            2: 'var(--surface2)'
                        },
                        danger: 'var(--red)'
                    },
                    fontFamily: {
                        dm: ["'DM Sans'", 'system-ui', 'sans-serif'],
                        report: ["'DM Sans'", 'Arial', 'Helvetica', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="stylesheet" href="../css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <!-- Only what Tailwind utilities cannot express: theme tokens and the @page rule. -->
    <style>
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

<body class="m-0 bg-[#eef1f6] font-dm text-ink-1 dark:bg-[#0f172a] print:bg-white">

    <header class="topbar no-print print:!hidden">
        <div class="topbar-brand">
            <img src="../pictures/dmw_logo.png" alt="DMW" class="!h-auto !w-[42px] block bg-transparent !filter-none">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date max-sm:hidden" id="live-date"></span>

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

    <div class="mx-auto my-3 max-w-[1000px] px-3 pb-10 sm:my-5 sm:px-4 sm:pb-[60px] print:m-0 print:max-w-full print:p-0">

        <div class="no-print mb-4 grid grid-cols-3 gap-2 sm:flex sm:flex-wrap print:!hidden">
            <a href="dashboard.php" class="block"><button class="inline-flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-md border border-line bg-surf px-3.5 py-2 text-xs font-semibold text-ink-1 hover:bg-surf-2 disabled:cursor-not-allowed disabled:opacity-[.55] max-sm:px-2 sm:w-auto" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back</button></a>
            <button class="inline-flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-md border border-line bg-surf px-3.5 py-2 text-xs font-semibold text-ink-1 hover:bg-surf-2 disabled:cursor-not-allowed disabled:opacity-[.55] max-sm:px-2 sm:w-auto" id="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button class="inline-flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-md border border-navy bg-navy px-3.5 py-2 text-xs font-semibold text-white hover:bg-navy-soft disabled:cursor-not-allowed disabled:opacity-[.55] max-sm:px-2 sm:w-auto" id="btn-pdf" onclick="exportToPdf()"><i class="fas fa-file-pdf"></i> <span class="max-[420px]:hidden">Export to&nbsp;</span>PDF</button>
        </div>

        <!-- WEB VIEW — interactive on-screen layout. -->
        <div class="webView print:!hidden">
            <div class="rounded-[10px] border border-line bg-surf px-4 pb-7 pt-5 sm:px-6 sm:pt-6 md:px-[34px] md:pb-9 md:pt-[30px]">

                <div class="mb-1 flex w-full items-center gap-3.5 border-b-[3px] border-navy pb-2.5">
                    <img id="printLogo" class="h-auto w-12 shrink-0" src="../pictures/dmw_logo.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
                    <div class="min-w-0">
                        <p class="m-0 text-[10.5px] text-ink-2">Republic of the Philippines</p>
                        <p class="mb-0.5 mt-px text-sm font-extrabold text-navy sm:text-base">DEPARTMENT OF MIGRANT WORKERS</p>
                        <p class="m-0 text-[10.5px] leading-normal text-ink-2">
                            Regional Office IV-A (CALABARZON)<br>
                            Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                            Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                        </p>
                    </div>
                </div>

                <div class="mb-[3px] mt-[18px] text-center text-[15px] font-extrabold uppercase tracking-[.02em] text-navy sm:text-[17px]">Application Report</div>
                <div class="mb-4 text-center text-xs text-ink-2">Generated by <?= htmlspecialchars($admin_name) ?></div>

                <div class="mb-4 grid grid-cols-1 gap-x-2 gap-y-0.5 text-xs sm:grid-cols-[160px_1fr]">
                    <div class="mt-1 font-bold text-ink-1 sm:mt-0">Filters Applied:</div>
                    <div id="web-filters" class="break-words">—</div>
                    <div class="mt-1 font-bold text-ink-1 sm:mt-0">Generated At:</div>
                    <div id="web-generated">—</div>
                </div>

                <p class="mb-[18px] text-left text-[12.5px] leading-[1.65] text-ink-2 sm:text-justify">
                    This report presents all applications currently matching the selected filters, generated directly
                    from the Application Management System's live database. It is intended to give the reviewing
                    officer a consolidated, exportable view of applicant status, placement, and processing progress
                    for monitoring, auditing, or reporting purposes.
                </p>

                <div class="mb-[18px] grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-4 [&>:only-child]:col-span-full" id="web-summary">
                    <div class="bg-surf-2 px-3.5 py-3">
                        <div class="text-lg font-extrabold leading-[1.1] text-navy sm:text-xl">—</div>
                        <div class="mt-[3px] text-[10px] font-semibold uppercase tracking-[.03em] text-ink-2">Total Applications</div>
                    </div>
                </div>

                <div class="mb-[22px]">
                    <div class="mb-2.5 flex items-center gap-2 rounded-[3px] bg-navy px-3 py-[7px] text-xs font-bold uppercase tracking-[.04em] text-white dark:bg-navy-soft dark:text-slate-50"><i class="fas fa-table-list"></i> Application Records</div>
                    <p class="-mt-1 mb-2.5 text-[11.5px] italic text-ink-3 dark:text-ink-2">Every application matching the current filters, most recently submitted first is not guaranteed — order reflects the underlying dataset.</p>
                    <div id="web-body" class="md:overflow-x-auto">
                        <div class="py-[30px] text-center text-xs italic text-ink-3 dark:text-ink-2"><i class="fas fa-spinner fa-spin"></i> Loading applications&hellip;</div>
                    </div>
                </div>

                <p class="mt-6 border-t border-line pt-2.5 text-center text-[10px] text-ink-3 dark:text-ink-2" id="web-footer">Department of Migrant Workers — Application Management System</p>

            </div>
        </div>

        <!-- PRINT / PDF REPORT — populated by JS, matches
             application-detail.php's print layout. Hidden on screen,
             shown for window.print() and toggled on by exportToPdf().
             Intentionally has no responsive breakpoints so the printed
             and PDF output is identical on every device. -->
        <div id="printReport" class="mx-auto hidden w-full max-w-[190mm] bg-white p-0 font-report text-[#1f2937] print:!block">

            <div class="pr-page-header mb-1.5 flex items-center gap-3.5 border-b-[3px] border-[#0b1f4b] pb-2.5">
                <img id="printLogo" class="h-[42px] w-auto" src="../pictures/dmw_logo.png" alt="DMW logo" onerror="this.style.visibility='hidden';">
                <div>
                    <div class="mb-px text-[8.5pt] text-[#4b5563]">Republic of the Philippines</div>
                    <div class="text-[12.5pt] font-extrabold tracking-[.01em] text-[#0b1f4b]">DEPARTMENT OF MIGRANT WORKERS</div>
                    <div class="mt-0.5 text-[8.7pt] leading-normal text-[#4b5563]">
                        Regional Office IV-A (CALABARZON)<br>
                        Andenson Bldg 3, Brgy. Parian, Calamba, Philippines, 4027<br>
                        Tel: 0962 671 9976 | Email: calamba@dmw.gov.ph | dmw.gov.ph
                    </div>
                </div>
            </div>

            <div class="mb-1.5 mt-3.5 text-center">
                <h1 class="m-0 font-report text-[14pt] font-extrabold uppercase tracking-[.02em] text-[#0b1f4b]">Application Report</h1>
                <p class="mb-0 mt-[3px] text-[9.5pt] text-[#6b7280]">Generated by <?= htmlspecialchars($admin_name) ?></p>
            </div>

            <div class="mb-4 flex flex-wrap items-center justify-between gap-x-3.5 gap-y-1.5 border-b border-[#e5e7eb] pb-2 text-[8.5pt] text-[#4b5563]">
                <span id="pr-filters">Filters: —</span>
                <span id="pr-generated">Generated: —</span>
                <span id="pr-count">Total records: —</span>
            </div>

            <p class="mb-3 text-justify text-[9pt] leading-[1.6] text-[#374151]">
                This report presents all applications currently matching the selected filters, generated directly
                from the Application Management System's live database. It is intended to give the reviewing
                officer a consolidated, exportable view of applicant status, placement, and processing progress
                for monitoring, auditing, or reporting purposes.
            </p>

            <table class="mb-3.5 w-full table-fixed border-collapse" id="pr-summary">
                <tr>
                    <td class="border border-[#e5e7eb] bg-[#f8fafc] px-1 py-2 text-center">
                        <span class="block text-[13pt] font-extrabold text-[#0b1f4b]">—</span>
                        <span class="mt-0.5 block text-[6.7pt] font-bold uppercase tracking-[.02em] text-[#4b5563]">Total Applications</span>
                    </td>
                </tr>
            </table>

            <div class="mb-4 [page-break-inside:avoid]">
                <div class="mb-2 bg-[#0b1f4b] px-[9px] py-[5px] text-[10.5pt] font-bold uppercase tracking-[.03em] text-white">Application Records</div>
                <p class="-mt-[5px] mb-1.5 text-[7.8pt] italic text-[#9ca3af]">Every application matching the current filters, as of the generation timestamp above.</p>
                <div id="pr-body">
                    <p class="py-1 text-[8.5pt] italic text-[#9ca3af]">Loading…</p>
                </div>
            </div>

            <div class="mt-10 flex justify-between text-[8.5pt] [page-break-inside:avoid]">
                <div class="w-[42%] text-center">
                    <div class="mt-[34px] border-t border-[#1f2937] pt-1 text-[#374151]">Prepared By</div>
                </div>
                <div class="w-[42%] text-center">
                    <div class="mt-[34px] border-t border-[#1f2937] pt-1 text-[#374151]">Certified By, DMW RO IV-A</div>
                </div>
            </div>

            <div class="mt-[22px] border-t border-[#9ca3af] pt-2">
                <div class="flex items-stretch gap-4">
                    <div class="flex flex-[1_1_60%] items-center">
                        <p class="m-0 text-left text-[7.3pt] leading-[1.55] text-[#6b7280]">
                            This is an official system-generated report from the DMW Application Management System —
                            Regional Office IV-A (CALABARZON). Information is confidential and intended solely for
                            internal monitoring, compliance review, and official reporting.
                        </p>
                    </div>
                    <div class="flex flex-[0_0_34%] flex-col justify-center gap-[3px] border-l border-[#d1d5db] pl-3.5 text-[7.3pt] text-[#6b7280] [&>span:first-child]:text-[#374151]">
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

        /* ── Tailwind class sets used by the JS-rendered markup (presentation only) ── */
        // Web: table collapses into labelled cards below the md breakpoint.
        const WEB_TABLE = 'w-full border-collapse border border-line text-xs md:min-w-[640px] max-md:block max-md:border-0';
        const WEB_TH = 'whitespace-nowrap border border-line bg-lbl px-2.5 py-2 text-left text-[10.5px] font-bold uppercase tracking-[.02em] text-ink-2';
        const WEB_TR = 'md:even:bg-[#fbfcfe] dark:md:even:bg-[#202f45] max-md:mb-3 max-md:block max-md:rounded-lg max-md:border max-md:border-line max-md:bg-surf max-md:px-3 max-md:py-1';
        const WEB_TD = 'border border-line px-2.5 py-2 align-top text-ink-1 ' +
            'max-md:flex max-md:items-start max-md:justify-between max-md:gap-3 max-md:border-0 max-md:border-t max-md:px-0 max-md:py-2 max-md:text-right max-md:first:border-t-0 ' +
            'max-md:before:shrink-0 max-md:before:text-left max-md:before:text-[10.5px] max-md:before:font-bold max-md:before:uppercase max-md:before:tracking-[.02em] max-md:before:text-ink-2 max-md:before:content-[attr(data-label)]';
        const WEB_BADGE = 'inline-block whitespace-nowrap rounded-full px-2.5 py-[3px] text-[11px] font-bold';
        const WEB_EMPTY = 'py-[30px] text-center text-xs italic text-ink-3 dark:text-ink-2';

        const SC_CELL = 'bg-surf-2 px-3.5 py-3';
        const SC_NUM = 'text-lg font-extrabold leading-[1.1] text-navy sm:text-xl';
        const SC_NUM_WARN = 'text-lg font-extrabold leading-[1.1] text-danger sm:text-xl';
        const SC_LBL = 'mt-[3px] text-[10px] font-semibold uppercase tracking-[.03em] text-ink-2';

        // Print / PDF: fixed layout, light colors only (the document is always white paper).
        const PR_TABLE = 'mt-1 w-full border-collapse text-[8.5pt]';
        const PR_TH = 'whitespace-nowrap border border-[#e5e7eb] bg-[#f8fafc] px-2 py-1.5 text-left text-[8pt] uppercase tracking-[.02em] text-[#374151]';
        const PR_TR = 'even:bg-[#f8fafc] [page-break-inside:avoid]';
        const PR_TD = 'border border-[#e5e7eb] px-2 py-1.5 align-top';
        const PR_BADGE = 'inline-block whitespace-nowrap rounded-full px-2.5 py-[3px] text-[11px] font-bold';
        const PR_SUM_TD = 'border border-[#e5e7eb] bg-[#f8fafc] px-1 py-2 text-center';
        const PR_SUM_NUM = 'block text-[13pt] font-extrabold text-[#0b1f4b]';
        const PR_SUM_LBL = 'mt-0.5 block text-[6.7pt] font-bold uppercase tracking-[.02em] text-[#4b5563]';
        const PR_EMPTY = 'py-1 text-[8.5pt] italic text-[#9ca3af]';

        const BADGE_WEB = {
            approved: 'bg-[#d1fae5] text-[#047857] dark:bg-[rgba(16,185,129,.18)] dark:text-[#34d399]',
            disapproved: 'bg-[#fee2e2] text-[#b91c1c] dark:bg-[rgba(220,38,38,.2)] dark:text-[#f87171]',
            pending: 'bg-[#fef3c7] text-[#b45309] dark:bg-[rgba(217,119,6,.2)] dark:text-[#fbbf24]',
            other: 'bg-[#e9edf7] text-navy dark:bg-[rgba(99,102,241,.22)] dark:text-[#a5b4fc]'
        };
        const BADGE_PRINT = {
            approved: 'bg-[#d1fae5] text-[#047857]',
            disapproved: 'bg-[#fee2e2] text-[#b91c1c]',
            pending: 'bg-[#fef3c7] text-[#b45309]',
            other: 'bg-[#e9edf7] text-[#0b1f4b]'
        };

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

        function badgeClass(status, variant = 'web') {
            const map = variant === 'print' ? BADGE_PRINT : BADGE_WEB;
            switch (status) {
                case 'approved':
                    return map.approved;
                case 'disapproved':
                    return map.disapproved;
                case 'pending':
                    return map.pending;
                default:
                    return map.other;
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

        function buildRowsHtml(rows, variant = 'web') {
            const isPrint = variant === 'print';
            const tr = isPrint ? PR_TR : WEB_TR;
            const td = isPrint ? PR_TD : WEB_TD;
            const badge = isPrint ? PR_BADGE : WEB_BADGE;
            return rows.map(r => `
                <tr class="${tr}">
                    <td class="${td}" data-label="Applicant">${escapeHtml(r.applicant)}</td>
                    <td class="${td}" data-label="Province / City">${escapeHtml(r.province)}${r.city ? ', ' + escapeHtml(r.city) : ''}</td>
                    <td class="${td}" data-label="Position">${escapeHtml(r.position_title)}</td>
                    <td class="${td}" data-label="Employer">${escapeHtml(r.employer_name)}</td>
                    <td class="${td}" data-label="Destination">${escapeHtml(r.country)}</td>
                    <td class="${td}" data-label="Status"><span class="${badge} ${badgeClass(r.status, variant)}">${escapeHtml(statusLabel(r.status))}</span></td>
                    <td class="${td}" data-label="Submitted">${fmtDate(r.submitted_at)}</td>
                </tr>`).join('');
        }

        function renderWeb(data, stats) {
            document.getElementById('web-filters').textContent = filterSummaryText(data.filters);
            document.getElementById('web-generated').textContent = fmtDateTime(data.generated_at);

            document.getElementById('web-summary').innerHTML = `
                <div class="${SC_CELL}">
                    <div class="${SC_NUM}">${stats.total}</div>
                    <div class="${SC_LBL}">Total Applications</div>
                </div>
                <div class="${SC_CELL}">
                    <div class="${SC_NUM}">${stats.approved}</div>
                    <div class="${SC_LBL}">Approved</div>
                </div>
                <div class="${SC_CELL}">
                    <div class="${stats.disapproved > 0 ? SC_NUM_WARN : SC_NUM}">${stats.disapproved}</div>
                    <div class="${SC_LBL}">Disapproved</div>
                </div>
                <div class="${SC_CELL}">
                    <div class="${SC_NUM}">${stats.inProcess}</div>
                    <div class="${SC_LBL}">Still In Process</div>
                </div>`;

            const body = document.getElementById('web-body');
            if (!data.rows || !data.rows.length) {
                body.innerHTML = `<p class="${WEB_EMPTY}"><i class="fas fa-inbox"></i> No applications match these filters.</p>`;
            } else {
                body.innerHTML = `
                    <table class="${WEB_TABLE}">
                        <thead class="max-md:hidden">
                            <tr>
                                <th class="${WEB_TH}">Applicant</th>
                                <th class="${WEB_TH}">Province / City</th>
                                <th class="${WEB_TH}">Position</th>
                                <th class="${WEB_TH}">Employer</th>
                                <th class="${WEB_TH}">Destination</th>
                                <th class="${WEB_TH}">Status</th>
                                <th class="${WEB_TH}">Submitted</th>
                            </tr>
                        </thead>
                        <tbody class="max-md:block">${buildRowsHtml(data.rows, 'web')}</tbody>
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
                    <td class="${PR_SUM_TD}">
                        <span class="${PR_SUM_NUM}">${stats.total}</span>
                        <span class="${PR_SUM_LBL}">Total Applications</span>
                    </td>
                    <td class="${PR_SUM_TD}">
                        <span class="${PR_SUM_NUM}">${stats.approved}</span>
                        <span class="${PR_SUM_LBL}">Approved</span>
                    </td>
                    <td class="${PR_SUM_TD}">
                        <span class="${PR_SUM_NUM}">${stats.disapproved}</span>
                        <span class="${PR_SUM_LBL}">Disapproved</span>
                    </td>
                    <td class="${PR_SUM_TD}">
                        <span class="${PR_SUM_NUM}">${stats.inProcess}</span>
                        <span class="${PR_SUM_LBL}">Still In Process</span>
                    </td>
                </tr>`;

            const body = document.getElementById('pr-body');
            if (!data.rows || !data.rows.length) {
                body.innerHTML = `<p class="${PR_EMPTY}">No applications match these filters.</p>`;
            } else {
                body.innerHTML = `
                    <table class="${PR_TABLE}">
                        <thead class="table-header-group">
                            <tr>
                                <th class="${PR_TH} w-[20%]">Applicant</th>
                                <th class="${PR_TH} w-[15%]">Province / City</th>
                                <th class="${PR_TH} w-[17%]">Position</th>
                                <th class="${PR_TH} w-[17%]">Employer</th>
                                <th class="${PR_TH} w-[11%]">Destination</th>
                                <th class="${PR_TH} w-[11%]">Status</th>
                                <th class="${PR_TH} w-[9%]">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>${buildRowsHtml(data.rows, 'print')}</tbody>
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
                    `<p class="${WEB_EMPTY}"><i class="fas fa-triangle-exclamation"></i> Couldn't load the report (${escapeHtml(err.message)}).</p>`;
                document.getElementById('pr-body').innerHTML =
                    `<p class="${PR_EMPTY}">Couldn't load the report (${escapeHtml(err.message)}).</p>`;
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
<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// CSRF token for all mutating requests on this page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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

// Dynamic badge count for the Apps dock item (matches dashboard.php)
$badge_stmt = $database->prepare("
    SELECT COUNT(*) as total
    FROM applications
    WHERE status IN ('pending', 'for_compliance', 'resubmitted')
");
$badge_stmt->execute();
$badge_result = $badge_stmt->get_result()->fetch_assoc();
$active_apps = $badge_result['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Settings – DMW RO IV-A Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/darkmode.css">
    <?php include '../includes/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]']
        };
    </script>
    <style>
        body {
            font-family: 'DM Sans', sans-serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* ── Shell layout ─────────────────────────────────────────────
           Wider max-width so the page actually uses desktop real estate,
           tighter gap between nav/panel, grid for the two top-level regions. */
        .settings-shell {
            display: grid;
            grid-template-columns: 224px minmax(0, 1fr);
            gap: 22px;
            padding: 20px 24px;
            padding-bottom: 110px;
            /* clears the floating dock, matches .main spacing in dashboard.php */
            max-width: 1440px;
            margin: 0 auto;
        }

        .settings-nav {
            width: 100%;
            min-width: 0;
            position: sticky;
            top: var(--sticky-top, 160px);
            align-self: start;
        }

        .settings-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: background .15s, color .15s;
            margin-bottom: 2px;
        }

        .settings-nav-item i {
            width: 16px;
            text-align: center;
            font-size: 12px;
        }

        .settings-nav-item:hover {
            background: #f1f5f9;
        }

        .settings-nav-item.active {
            background: #1d4ed8;
            color: #fff;
        }

        .settings-nav-group-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #94a3b8;
            margin: 14px 0 6px 13px;
        }

        .settings-panel {
            min-width: 0;
        }

        .s-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px 22px;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }

        .s-card:last-child {
            margin-bottom: 0;
        }

        .s-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .s-card-sub {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 16px;
        }

        /* ── Form field grid ──────────────────────────────────────────
           Pairs related short fields side-by-side instead of stacking
           every input full-width, which is what was wasting horizontal
           space on desktop. */
        .s-form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2px 16px;
        }

        .s-form-grid.s-form-grid-3 {
            grid-template-columns: repeat(3, 1fr);
        }

        .s-field-full {
            grid-column: 1 / -1;
        }

        .s-field {
            margin-bottom: 14px;
            min-width: 0;
        }

        .s-field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }

        .s-field input,
        .s-field select {
            width: 100%;
            font-size: 13px;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            outline: none;
            transition: border-color .15s;
        }

        .s-field input:focus,
        .s-field select:focus {
            border-color: #1d4ed8;
        }

        .s-field input[readonly] {
            background: #f8fafc;
            color: #64748b;
        }

        .s-hint {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .s-btn {
            font-size: 12px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: opacity .15s;
        }

        .s-btn:hover {
            opacity: .88;
        }

        .s-btn-primary {
            background: #1d4ed8;
            color: #fff;
        }

        .s-btn-danger {
            background: #dc2626;
            color: #fff;
        }

        .s-btn-outline {
            background: #fff;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .s-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.s-table {
            width: 100%;
            min-width: 480px;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        table.s-table th {
            text-align: left;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
            padding: 8px 10px;
            border-bottom: 2px solid #f1f5f9;
        }

        table.s-table td {
            padding: 10px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        table.s-table tr:hover td {
            background: #f8fafc;
        }

        .s-badge {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .s-badge.ok {
            background: #dcfce7;
            color: #16a34a;
        }

        .s-badge.fail {
            background: #fee2e2;
            color: #dc2626;
        }

        .s-icon-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: #475569;
            margin-right: 4px;
        }

        .s-icon-btn:hover {
            background: #f1f5f9;
        }

        .s-icon-btn.danger:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .s-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .s-search {
            font-size: 12.5px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            width: 260px;
            max-width: 100%;
        }

        .settings-tab-panel {
            display: none;
        }

        .settings-tab-panel.active {
            display: block;
        }

        .s-skeleton-row td {
            height: 38px;
        }

        /* ── Security tab layout ──────────────────────────────────────
           Change Password sits next to Active Sessions + Recent Login
           Activity instead of three full-width cards stacked in a
           single column — makes far better use of desktop width. */
        .security-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            align-items: start;
        }

        /* Subbar breadcrumb pills — mirror the .filter-pill look, act as the
           tab switcher on mobile where the sidebar is hidden */
        .settings-crumb-pill {
            cursor: pointer;
            user-select: none;
            transition: background .15s, border-color .15s;
        }

        .settings-crumb-pill.active {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .settings-crumb-pill.active span,
        .settings-crumb-pill.active i {
            color: #fff !important;
        }

        /*  CHANGE: password visibility toggle + live requirements checklist styles
           (ported from create-account.php's .input-field / #eyeIcon / #password-requirements pattern,
           re-themed to match the s-field / s-hint styling already used on this page) */
        .s-pass-wrap {
            position: relative;
        }

        .s-pass-wrap input {
            padding-right: 38px;
        }

        .s-pass-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 12px;
            padding: 4px;
            line-height: 1;
        }

        .s-pass-toggle:hover {
            color: #475569;
        }

        .s-pass-requirements {
            margin-top: 8px;
            font-size: 11px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 3px 10px;
        }

        .s-pass-requirements.hidden {
            display: none;
        }

        .s-pass-requirements .req-item {
            color: #94a3b8;
            transition: color .15s;
        }

        .s-pass-requirements .req-item.met {
            color: #16a34a;
        }

        /* ── Responsive breakpoints ─────────────────────────────────── */
        @media (max-width: 1000px) {
            .security-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 860px) {
            .settings-shell {
                grid-template-columns: 1fr;
                padding: 14px;
                padding-bottom: 110px;
                gap: 14px;
            }

            /* Sidebar nav is redundant with the subbar pills on mobile */
            .settings-nav {
                display: none;
            }

            .s-card {
                padding: 16px;
            }

            .s-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .s-search {
                width: 100%;
            }

            .s-toolbar button[data-add] {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 640px) {
            .subbar-greeting h2 {
                font-size: 16px;
            }

            .subbar-greeting p {
                font-size: 11px;
            }

            .s-card {
                padding: 14px;
                border-radius: 12px;
            }

            .s-card-title {
                font-size: 14px;
            }

            .s-form-grid,
            .s-form-grid.s-form-grid-3 {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .s-pass-requirements {
                grid-template-columns: 1fr;
            }

            table.s-table {
                font-size: 11.5px;
            }

            table.s-table th,
            table.s-table td {
                padding: 7px 8px;
            }

            .s-field input,
            .s-field select {
                font-size: 13px;
                padding: 8px 10px;
            }

            .s-btn {
                width: 100%;
                text-align: center;
            }
        }

        /* Pin the subbar under the topbar so it doesn't scroll with content */
        .subbar {
            position: fixed;
            left: 0;
            right: 0;
            z-index: 40;
            background: #fff;
            /* top is set by JS to match the real topbar height */
        }

        /* Sub-bar tab pills: hidden on desktop (the sidebar nav handles it),
   shown only when the sidebar is hidden (≤860px) */
        .subbar .filter-scroll {
            display: none;
        }

        @media (max-width: 860px) {
            .subbar .filter-scroll {
                display: flex;
            }
        }

        /* Reserve space so page content isn't hidden under the fixed topbar+subbar */
        .settings-shell {
            /* padding-top is also set by JS, this is just a fallback */
            padding-top: 140px;
        }

        @media (max-width: 640px) {
            .settings-shell {
                padding-top: 160px;
            }
        }

        @media (max-width: 420px) {
            .topbar-date {
                display: none;
            }

            /* s-table-wrap already scrolls horizontally at this width,
               which is safer than hiding columns since column count
               differs per lookup table */
        }

        .s-used-in-box {
            background: #f0f9ff;
            border: 1px solid #dbeafe;
            color: #1d4ed8;
        }

        [data-theme="dark"] body {
            background: #0f172a;
        }

        [data-theme="dark"] .subbar {
            background: #1e293b;
        }

        [data-theme="dark"] .settings-nav-item {
            color: #94a3b8;
        }

        [data-theme="dark"] .settings-nav-item:hover {
            background: #263449;
        }

        [data-theme="dark"] .settings-nav-item.active {
            background: #3b82f6;
            color: #fff;
        }

        [data-theme="dark"] .settings-nav-group-label {
            color: #64748b;
        }

        [data-theme="dark"] .s-card {
            background: #1e293b;
            border-color: #2d3748;
            box-shadow: none;
        }

        [data-theme="dark"] .s-card-title {
            color: #e2e8f0;
        }

        [data-theme="dark"] .s-card-sub {
            color: #94a3b8;
        }

        [data-theme="dark"] .s-field label {
            color: #cbd5e1;
        }

        [data-theme="dark"] .s-field input,
        [data-theme="dark"] .s-field select,
        [data-theme="dark"] .s-search {
            background: #0f172a;
            color: #e2e8f0;
            border-color: #334155;
        }

        [data-theme="dark"] .s-field input:focus,
        [data-theme="dark"] .s-field select:focus {
            border-color: #3b82f6;
        }

        [data-theme="dark"] .s-field input[readonly] {
            background: #1a2332;
            color: #64748b;
        }

        [data-theme="dark"] .s-hint {
            color: #64748b;
        }

        [data-theme="dark"] .s-used-in-box {
            background: rgba(59, 130, 246, .12);
            border-color: rgba(96, 165, 250, .35);
            color: #60a5fa;
        }

        [data-theme="dark"] .s-btn-outline {
            background: #1e293b;
            color: #cbd5e1;
            border-color: #334155;
        }

        [data-theme="dark"] table.s-table th {
            color: #64748b;
            border-bottom-color: #2d3748;
        }

        [data-theme="dark"] table.s-table td {
            border-bottom-color: #2d3748;
            color: #cbd5e1;
        }

        [data-theme="dark"] table.s-table tr:hover td {
            background: #202a3d;
        }

        [data-theme="dark"] .s-badge.ok {
            background: rgba(16, 185, 129, .18);
            color: #34d399;
        }

        [data-theme="dark"] .s-badge.fail {
            background: rgba(220, 38, 38, .18);
            color: #f87171;
        }

        [data-theme="dark"] .s-icon-btn {
            border-color: #334155;
            background: #1e293b;
            color: #94a3b8;
        }

        [data-theme="dark"] .s-icon-btn:hover {
            background: #263449;
        }

        [data-theme="dark"] .s-icon-btn.danger:hover {
            background: rgba(220, 38, 38, .18);
            color: #f87171;
        }

        [data-theme="dark"] .settings-crumb-pill.active {
            background: #3b82f6;
            border-color: #3b82f6;
        }

        [data-theme="dark"] .s-pass-toggle {
            color: #64748b;
        }

        [data-theme="dark"] .s-pass-toggle:hover {
            color: #cbd5e1;
        }

        [data-theme="dark"] .s-pass-requirements .req-item {
            color: #64748b;
        }

        [data-theme="dark"] .s-pass-requirements .req-item.met {
            color: #34d399;
        }

        /* ── Dark mode: keep inputs dark when the browser autofills them ── */
        [data-theme="dark"] .s-field input:-webkit-autofill,
        [data-theme="dark"] .s-field input:-webkit-autofill:hover,
        [data-theme="dark"] .s-field input:-webkit-autofill:focus,
        [data-theme="dark"] .s-field input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px #0f172a inset !important;
            box-shadow: 0 0 0 1000px #0f172a inset !important;
            -webkit-text-fill-color: #e2e8f0 !important;
            caret-color: #e2e8f0;
            border-color: #334155;
            transition: background-color 9999s ease-in-out 0s;
        }

        [data-theme="dark"] .s-field input:-webkit-autofill:focus {
            border-color: #3b82f6;
        }

        /* Firefox autofill */
        [data-theme="dark"] .s-field input:autofill {
            box-shadow: 0 0 0 1000px #0f172a inset !important;
            -webkit-text-fill-color: #e2e8f0 !important;
        }

        /* Makes native controls (autofill highlight, scrollbars, select popups) use dark palette */
        [data-theme="dark"] .s-field input,
        [data-theme="dark"] .s-field select {
            color-scheme: dark;
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

    <!-- TOP BAR  -->
    <header class="topbar">
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

    <!--  SUBBAR  -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Account &amp; System Settings</h2>
                <p id="subbar-section-label">Manage your profile, security, and shared reference data used across the system.</p>
            </div>
        </div>
        <div class="filter-scroll">
            <div class="filter-pill settings-crumb-pill active" data-tab="profile">
                <i class="fas fa-user" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Profile</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="security">
                <i class="fas fa-shield-halved" style="color:var(--ok,#10b981)"></i>
                <span style="font-weight:600;font-size:11px">Security</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="email">
                <i class="fas fa-envelope" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Email</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="job_categories">
                <i class="fas fa-layer-group" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Job Categories</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="job_subcategories">
                <i class="fas fa-sitemap" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Job Subcategories</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="degree_programs">
                <i class="fas fa-graduation-cap" style="color:var(--indigo)"></i>
                <span style="font-weight:600;font-size:11px">Degree Programs</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="professional_licenses">
                <i class="fas fa-id-card" style="color:var(--indigo)"></i>
                <span style="font-weight:600;font-size:11px">Licenses</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="salary_ranges">
                <i class="fas fa-sack-dollar" style="color:var(--gold)"></i>
                <span style="font-weight:600;font-size:11px">Salary Ranges</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="skills">
                <i class="fas fa-screwdriver-wrench" style="color:var(--gold)"></i>
                <span style="font-weight:600;font-size:11px">Skills</span>
            </div>
        </div>
    </div>

    <!--  SETTINGS SHELL  -->
    <div class="settings-shell">

        <!-- Side nav (hidden on mobile — the subbar pills above act as the nav there) -->
        <nav class="settings-nav">
            <div class="s-card" style="padding:12px">
                <div class="settings-nav-group-label" style="margin-top:0">Account</div>
                <div class="settings-nav-item active" data-tab="profile"><i class="fas fa-user"></i> Profile</div>
                <div class="settings-nav-item" data-tab="security"><i class="fas fa-shield-halved"></i> Security</div>

                <div class="settings-nav-group-label">System</div>
                <div class="settings-nav-item" data-tab="email"><i class="fas fa-envelope"></i> Email Configuration</div>

                <div class="settings-nav-group-label">Reference Data</div>
                <div class="settings-nav-item" data-tab="job_categories"><i class="fas fa-layer-group"></i> Job Categories</div>
                <div class="settings-nav-item" data-tab="job_subcategories"><i class="fas fa-sitemap"></i> Job Subcategories</div>
                <div class="settings-nav-item" data-tab="degree_programs"><i class="fas fa-graduation-cap"></i> Degree Programs</div>
                <div class="settings-nav-item" data-tab="professional_licenses"><i class="fas fa-id-card"></i> Professional Licenses</div>
                <div class="settings-nav-item" data-tab="salary_ranges"><i class="fas fa-sack-dollar"></i> Salary Ranges</div>
                <div class="settings-nav-item" data-tab="skills"><i class="fas fa-screwdriver-wrench"></i> Skills</div>
            </div>
        </nav>

        <!-- Panels -->
        <div class="settings-panel">

            <!-- PROFILE -->
            <section class="settings-tab-panel active" id="panel-profile">
                <div class="s-card">
                    <div class="s-card-title">Profile</div>
                    <div class="s-card-sub">Update your personal account information.</div>
                    <form id="profile-form">
                        <div class="s-form-grid">
                            <div class="s-field">
                                <label>Full Name</label>
                                <input type="text" name="full_name" id="pf-full-name" maxlength="100" required>
                            </div>
                            <div class="s-field">
                                <label>Phone</label>
                                <input type="tel" name="phone" id="pf-phone"
                                    inputmode="numeric" maxlength="11"
                                    pattern="09[0-9]{9}"
                                    title="Enter an 11-digit mobile number starting with 09 (e.g., 09123456789)"
                                    autocomplete="tel" placeholder="09123456789">
                                <div class="s-hint" id="pf-phone-msg">Format: 09123456789 (11 digits, numbers only)</div>
                            </div>
                        </div>
                        <div class="s-field s-field-full">
                            <label>Email Address</label>
                            <input type="email" name="email" id="pf-email" maxlength="100" required>
                            <div class="s-hint">Changing your email updates your login across the system. You'll need to log in again.</div>
                        </div>
                        <div class="s-form-grid s-form-grid-3">
                            <div class="s-field">
                                <label>Account Type</label>
                                <input type="text" id="pf-usertype" readonly>
                            </div>
                            <div class="s-field">
                                <label>Member Since</label>
                                <input type="text" id="pf-created" readonly>
                            </div>
                            <div class="s-field">
                                <label>Last Profile Update</label>
                                <input type="text" id="pf-updated" readonly>
                            </div>
                        </div>
                        <button type="submit" class="s-btn s-btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    </form>
                </div>
            </section>

            <!-- SECURITY -->
            <section class="settings-tab-panel" id="panel-security">
                <!-- Full-width so the Device/User-Agent column has room to breathe
                     instead of being squeezed into the narrow side column. -->
                <div class="s-card" id="activity-card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="s-card-title">Activity &amp; Audit Log</div>
                            <div class="s-card-sub" style="margin-bottom:0">Sign-in attempts and system actions in one timeline.</div>
                        </div>
                        <span id="al-total" class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">0 records</span>
                    </div>

                    <!-- Toolbar -->
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <div id="al-source" class="inline-flex rounded-lg border border-slate-300 p-0.5 dark:border-slate-700" role="tablist">
                            <button type="button" data-source="all" data-active="true"
                                class="rounded-md px-3 py-1.5 text-[12px] font-semibold text-slate-600 data-[active=true]:bg-blue-700 data-[active=true]:text-white dark:text-slate-300 dark:data-[active=true]:bg-blue-500">All</button>
                            <button type="button" data-source="login"
                                class="rounded-md px-3 py-1.5 text-[12px] font-semibold text-slate-600 data-[active=true]:bg-blue-700 data-[active=true]:text-white dark:text-slate-300 dark:data-[active=true]:bg-blue-500">Sign-ins</button>
                            <button type="button" data-source="system"
                                class="rounded-md px-3 py-1.5 text-[12px] font-semibold text-slate-600 data-[active=true]:bg-blue-700 data-[active=true]:text-white dark:text-slate-300 dark:data-[active=true]:bg-blue-500">System</button>
                        </div>
                        <input id="al-q" type="search" placeholder="Search details or IP…"
                            class="min-w-[180px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-[13px] outline-none focus:border-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:focus:border-blue-500">
                        <button type="button" id="al-filter-toggle" class="s-btn s-btn-outline"><i class="fas fa-sliders"></i> Filters</button>
                    </div>

                    <!-- Advanced filters -->
                    <div id="al-filters" class="mt-3 hidden rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/40">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">From
                                <input type="date" id="al-from" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:[color-scheme:dark]"></label>
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">To
                                <input type="date" id="al-to" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:[color-scheme:dark]"></label>
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">Role
                                <select id="al-role" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                    <option value="">All</option>
                                    <option value="client">Applicant</option>
                                    <option value="evaluator">Evaluator</option>
                                    <option value="admin">Admin</option>
                                </select></label>
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">Module
                                <select id="al-module" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                    <option value="">All</option>
                                </select></label>
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">Action
                                <select id="al-action" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                    <option value="">All</option>
                                </select></label>
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">User email
                                <input type="text" id="al-user" maxlength="100" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"></label>
                            <label class="text-[12px] font-semibold text-slate-700 dark:text-slate-300">Application #
                                <input type="text" id="al-app" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-[13px] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"></label>
                            <label class="flex items-end gap-2 pb-2 text-[12px] font-semibold text-slate-700 dark:text-slate-300">
                                <input type="checkbox" id="al-mine" class="h-4 w-4 rounded border-slate-300 text-blue-700"> Only my account</label>
                        </div>
                        <div class="mt-3">
                            <button type="button" id="al-reset" class="s-btn s-btn-outline"><i class="fas fa-rotate-left"></i> Reset filters</button>
                        </div>
                    </div>

                    <div id="al-notice" class="s-hint s-used-in-box mt-3 hidden" style="padding:8px 12px;border-radius:8px;font-weight:600">
                        <i class="fas fa-circle-info" style="margin-right:5px"></i>The <code>activity_logs</code> table was not found, so only sign-in attempts are shown.
                    </div>

                    <div class="s-table-wrap mt-4">
                        <table class="s-table" style="min-width:820px">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Module</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>App #</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody id="al-body">
                                <tr class="s-skeleton-row">
                                    <td colspan="8"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-[12px] text-slate-500 dark:text-slate-400">
                        <span id="al-page-label">Page 1 of 1</span>
                        <div class="flex gap-2">
                            <button type="button" id="al-prev" class="s-btn s-btn-outline" style="width:auto">Previous</button>
                            <button type="button" id="al-next" class="s-btn s-btn-outline" style="width:auto">Next</button>
                        </div>
                    </div>
                </div>
                <div class="security-grid">
                    <div class="s-card">
                        <div class="s-card-title">Change Password</div>
                        <div class="s-card-sub">Choose a strong password you don't use elsewhere.</div>
                        <form id="password-form">
                            <div class="s-field s-field-full">
                                <label>Current Password</label>
                                <!--  CHANGE: wrapped in .s-pass-wrap + added eye-toggle button (same pattern as create-account.php) -->
                                <div class="s-pass-wrap">
                                    <input type="password" name="current_password" id="sec-current-pass" required autocomplete="current-password">
                                    <button type="button" class="s-pass-toggle" id="toggleCurrentPass" tabindex="-1">
                                        <i class="fas fa-eye" id="eyeCurrentPass"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="s-form-grid">
                                <div class="s-field">
                                    <label>New Password</label>
                                    <!--  CHANGE: wrapped in .s-pass-wrap + added eye-toggle button -->
                                    <div class="s-pass-wrap">
                                        <input type="password" name="new_password" id="sec-new-pass" minlength="8" required autocomplete="new-password">
                                        <button type="button" class="s-pass-toggle" id="toggleNewPass" tabindex="-1">
                                            <i class="fas fa-eye" id="eyeNewPass"></i>
                                        </button>
                                    </div>
                                    <div class="s-hint">Minimum 8 characters.</div>
                                </div>
                                <div class="s-field">
                                    <label>Confirm New Password</label>
                                    <!--  CHANGE: wrapped in .s-pass-wrap + added eye-toggle button -->
                                    <div class="s-pass-wrap">
                                        <input type="password" name="confirm_password" id="sec-confirm-pass" minlength="8" required autocomplete="new-password">
                                        <button type="button" class="s-pass-toggle" id="toggleConfirmPass" tabindex="-1">
                                            <i class="fas fa-eye" id="eyeConfirmPass"></i>
                                        </button>
                                    </div>
                                    <!--  CHANGE: added live match indicator (same logic as create-account.php) -->
                                    <div id="sec-password-match" class="s-hint" style="min-height:1.1em"></div>
                                </div>
                            </div>
                            <!--  CHANGE: added live password requirements checklist (same logic as create-account.php) -->
                            <div id="sec-password-requirements" class="s-pass-requirements hidden">
                                <div id="sec-req-length" class="req-item">• At least 8 characters</div>
                                <div id="sec-req-upper" class="req-item">• At least one uppercase letter</div>
                                <div id="sec-req-lower" class="req-item">• At least one lowercase letter</div>
                                <div id="sec-req-number" class="req-item">• At least one number</div>
                                <div id="sec-req-special" class="req-item">• At least one special character</div>
                            </div>
                            <div style="margin-top:16px">
                                <button type="submit" class="s-btn s-btn-primary"><i class="fas fa-key"></i> Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- EMAIL CONFIGURATION -->
            <section class="settings-tab-panel" id="panel-email">
                <div class="s-card">
                    <div class="s-card-title">Email Configuration</div>
                    <div class="s-card-sub">SMTP account used for verification codes and application status emails.</div>

                    <form id="email-form" autocomplete="off">
                        <div class="s-form-grid s-form-grid-3">
                            <div class="s-field">
                                <label>SMTP Host</label>
                                <input type="text" id="em-host" maxlength="255" required placeholder="smtp.gmail.com">
                            </div>
                            <div class="s-field">
                                <label>Port</label>
                                <input type="number" id="em-port" min="1" max="65535" required>
                            </div>
                            <div class="s-field">
                                <label>Encryption</label>
                                <select id="em-enc">
                                    <option value="tls">STARTTLS (587)</option>
                                    <option value="ssl">SSL/SMTPS (465)</option>
                                    <option value="none">None (not recommended)</option>
                                </select>
                            </div>
                        </div>

                        <div class="s-form-grid">
                            <div class="s-field">
                                <label>SMTP Username</label>
                                <input type="text" id="em-user" maxlength="255" required autocomplete="off">
                            </div>
                            <div class="s-field">
                                <label>SMTP Password / App Password
                                    <a href="#" id="em-guide-link" class="ml-2 text-[11px] font-semibold text-blue-700 hover:underline dark:text-blue-400">
                                        <i class="fas fa-circle-question"></i> How do I get an App Password?
                                    </a>
                                </label>
                                <div class="s-pass-wrap">
                                    <input type="password" id="em-pass" autocomplete="new-password">
                                    <button type="button" class="s-pass-toggle" id="toggleEmPass" tabindex="-1">
                                        <i class="fas fa-eye" id="eyeEmPass"></i>
                                    </button>
                                </div>
                                <div class="s-hint" id="em-pass-hint">Stored encrypted (AES-256-GCM) and never displayed.</div>
                            </div>
                        </div>

                        <div class="s-form-grid">
                            <div class="s-field">
                                <label>From Email</label>
                                <input type="email" id="em-from" maxlength="255" required>
                                <div class="s-hint">Use the same address as the SMTP username for Gmail.</div>
                            </div>
                            <div class="s-field">
                                <label>From Name</label>
                                <input type="text" id="em-name" maxlength="150" required>
                            </div>
                        </div>

                        <div class="s-hint" id="em-meta" style="margin-bottom:14px"></div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <button type="submit" class="s-btn s-btn-primary"><i class="fas fa-save"></i> Save Email Settings</button>
                            <button type="button" class="s-btn s-btn-outline" id="em-test-btn"><i class="fas fa-paper-plane"></i> Send Test to Me</button>
                        </div>
                        <div class="s-hint" style="margin-top:8px">The test uses the <strong>saved</strong> settings, so save first, then test.</div>
                    </form>
                </div>
            </section>

            <!-- GENERIC LOOKUP TABLE PANELS -->
            <?php
            $lookup_panels = [
                'job_categories'         => [
                    'label' => 'Job Categories',
                    'desc'  => 'Top-level groupings (e.g., "Engineering and technical") used to organize Job Subcategories below.',
                    'used_in' => 'Used in: Job Subcategories (parent grouping only — not shown directly to clients or in job orders).'
                ],
                'job_subcategories'      => [
                    'label' => 'Job Subcategories',
                    'desc'  => 'Specific positions (e.g., "Welder", "Caregiver") grouped under a category.',
                    'used_in' => 'Used in: the Position field when creating an RSF Job Order, and as client Preferred Position choices.'
                ],
                'degree_programs'        => [
                    'label' => 'Degree Programs',
                    'desc'  => 'Degree programs available for clients to select.',
                    'used_in' => 'Used in: a client\'s Profile → Educational Attainment (Degree/Course field).'
                ],
                'professional_licenses'  => [
                    'label' => 'Professional Licenses',
                    'desc'  => 'PRC/TESDA/AMOSUP licenses and certifications clients can hold.',
                    'used_in' => 'Used in: a client\'s Profile → Licenses & Certifications section.'
                ],
                'salary_ranges'          => [
                    'label' => 'Salary Ranges',
                    'desc'  => 'Predefined salary bands.',
                    'used_in' => 'Used in: a client\'s Job Preferences → Preferred Salary Range.'
                ],
                'skills'                 => [
                    'label' => 'Skills',
                    'desc'  => 'Skills used on both sides of job matching.',
                    'used_in' => 'Used in: a client\'s Profile → Skills, and as Required/Preferred Skills on RSF Job Orders — this is what job matching compares against.'
                ],
            ];
            foreach ($lookup_panels as $key => $meta):
            ?>
                <section class="settings-tab-panel" id="panel-<?= $key ?>">
                    <div class="s-card">
                        <div class="s-card-title"><?= htmlspecialchars($meta['label']) ?></div>
                        <div class="s-card-sub"><?= htmlspecialchars($meta['desc']) ?></div>
                        <div class="s-hint s-used-in-box" style="padding:8px 12px;border-radius:8px;margin:-8px 0 16px;font-weight:600">
                            <i class="fas fa-circle-info" style="margin-right:5px"></i><?= htmlspecialchars($meta['used_in']) ?>
                        </div>
                        <div class="s-toolbar">
                            <input type="text" class="s-search" placeholder="Search..." data-lookup="<?= $key ?>">
                            <button class="s-btn s-btn-primary" data-add="<?= $key ?>"><i class="fas fa-plus"></i> Add New</button>
                        </div>
                        <div class="s-table-wrap">
                            <table class="s-table" id="table-<?= $key ?>">
                                <thead></thead>
                                <tbody>
                                    <tr class="s-skeleton-row">
                                        <td colspan="6"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
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
            <a href="user.php" class="dk" data-tip="Users">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Users</span>
            </a>
            <div class="dk-div"></div>
            <a href="settings.php" class="dk active" data-tip="Settings">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>
            <div class="dk" data-tip="Logout" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <script>
        const CSRF = <?= json_encode($csrf_token) ?>;
        const SETTINGS_API = 'settings-api.php';
        const LOOKUPS_API = 'lookups-api.php';

        /* ── Live date + time (matches dashboard.php) ────────────────── */
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

        /* ── Dock flyout (same as dashboard.php / applications.php) ──── */
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

        /* ── Tab switching (sidebar nav + subbar breadcrumb pills, kept in sync) ── */
        const navItems = document.querySelectorAll('.settings-nav-item');
        const crumbPills = document.querySelectorAll('.settings-crumb-pill');
        const panels = document.querySelectorAll('.settings-tab-panel');
        const loadedTabs = new Set();

        const SECTION_SUBTITLES = {
            profile: 'Manage your personal account information.',
            email: 'SMTP account used to send verification codes and status notifications.',
            security: 'Change your password and review recent login activity.',
            job_categories: 'Groupings for Job Subcategories — organizational only.',
            job_subcategories: 'Positions used in RSF Job Orders and client Preferred Positions.',
            degree_programs: 'Degree options shown in a client\'s Educational Attainment.',
            professional_licenses: 'Licenses shown in a client\'s Licenses & Certifications.',
            salary_ranges: 'Salary bands used in client Preferred Salary Range.',
            skills: 'Skills used in client profiles and Job Order required/preferred skills for matching.',
        };

        function switchTab(tab) {
            navItems.forEach(i => i.classList.toggle('active', i.dataset.tab === tab));
            crumbPills.forEach(i => i.classList.toggle('active', i.dataset.tab === tab));
            panels.forEach(p => p.classList.remove('active'));
            document.getElementById('panel-' + tab).classList.add('active');
            const label = document.getElementById('subbar-section-label');
            if (label && SECTION_SUBTITLES[tab]) label.textContent = SECTION_SUBTITLES[tab];
            loadTab(tab);
        }

        navItems.forEach(item => item.addEventListener('click', () => switchTab(item.dataset.tab)));
        crumbPills.forEach(item => item.addEventListener('click', () => switchTab(item.dataset.tab)));

        function loadTab(tab) {
            if (tab === 'profile') return loadProfile();
            if (tab === 'security') return loadSecurity();
            if (tab === 'email') return loadEmailSettings();
            if (LOOKUP_TABLES[tab]) return loadLookupTable(tab);
        }

        /* ── Helpers ───────────────────────────────────────────────── */
        function post(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ...body,
                    csrf_token: CSRF
                })
            }).then(r => r.json());
        }

        function toast(icon, title) {
            Swal.fire({
                icon,
                title,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2500
            });
        }

        /* ── PROFILE ───────────────────────────────────────────────── */
        function loadProfile() {
            if (loadedTabs.has('profile')) return;
            fetch(SETTINGS_API + '?action=get_profile').then(r => r.json()).then(d => {
                if (!d.success) return toast('error', d.message || 'Failed to load profile');
                document.getElementById('pf-full-name').value = d.data.full_name || '';
                document.getElementById('pf-phone').value = d.data.phone || '';
                if (window.validateProfilePhone) window.validateProfilePhone();
                document.getElementById('pf-email').value = d.data.email || '';
                document.getElementById('pf-usertype').value = (d.data.usertype || '').toUpperCase();
                document.getElementById('pf-created').value = d.data.created_at || '—';
                document.getElementById('pf-updated').value = d.data.updated_at || '—';
                loadedTabs.add('profile');
            });
        }

        document.getElementById('profile-form').addEventListener('submit', e => {
            e.preventDefault();
            const payload = {
                action: 'update_profile',
                full_name: document.getElementById('pf-full-name').value.trim(),
                phone: document.getElementById('pf-phone').value.trim(),
                email: document.getElementById('pf-email').value.trim()
            };
            if (payload.phone !== '' && !/^09\d{9}$/.test(payload.phone)) {
                return toast('error', 'Phone must be 11 digits and start with 09 (e.g., 09123456789)');
            }
            post(SETTINGS_API, payload).then(d => {
                if (d.success) {
                    toast('success', d.email_changed ? 'Profile updated. Please log in again.' : 'Profile updated');
                    if (d.email_changed) setTimeout(() => window.location.href = '../login.php', 1800);
                    else {
                        document.getElementById('pf-updated').value = d.updated_at;
                        const name = payload.full_name;
                        const menuName = document.getElementById('avatarMenuName');
                        if (menuName) menuName.textContent = name;
                        const btn = document.getElementById('avatarMenuBtn');
                        if (btn && name) {
                            const parts = name.trim().split(/\s+/);
                            btn.textContent = ((parts[0] || '').charAt(0) + (parts[parts.length - 1] || '').charAt(0)).toUpperCase();
                        }
                    }
                } else {
                    toast('error', d.message || 'Update failed');
                }
            });
        });

        /* ── SECURITY ──────────────────────────────────────────────── */
        function loadSecurity() {
            loadActivityLog();
        }

        /* ── ACTIVITY & AUDIT LOG ──────────────────────────────────── */
        const AL = {
            page: 1,
            pages: 1,
            source: 'all',
            optionsLoaded: false
        };

        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        const AL_ROLE_BADGE = {
            admin: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300',
            evaluator: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
            client: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300'
        };
        const AL_NEUTRAL = 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200';

        function alFillSelect(id, values) {
            const sel = document.getElementById(id);
            const current = sel.value;
            sel.innerHTML = '<option value="">All</option>' +
                values.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
            sel.value = current;
        }

        function alQuery() {
            const p = new URLSearchParams({
                action: 'activity_log',
                page: AL.page,
                source: AL.source
            });
            const map = {
                from: 'al-from',
                to: 'al-to',
                role: 'al-role',
                module: 'al-module',
                act: 'al-action',
                user: 'al-user',
                app_id: 'al-app',
                q: 'al-q'
            };
            for (const [k, id] of Object.entries(map)) {
                const v = document.getElementById(id).value.trim();
                if (v) p.set(k, v);
            }
            if (document.getElementById('al-mine').checked) p.set('mine', '1');
            return p.toString();
        }

        function alRow(r) {
            const isLogin = r.source === 'login';
            const actionBadge = isLogin ?
                `<span class="s-badge ${r.success == 1 ? 'ok' : 'fail'}">${esc(r.action)}</span>` :
                `<span class="inline-block rounded-full px-2 py-0.5 text-[10.5px] font-bold ${AL_NEUTRAL}">${esc(r.action)}</span>`;
            const roleBadge = r.usertype ?
                `<span class="inline-block rounded-full px-2 py-0.5 text-[10.5px] font-bold ${AL_ROLE_BADGE[r.usertype] || AL_NEUTRAL}">${esc(r.usertype)}</span>` :
                '—';
            const detail = esc(r.description) || '—';
            return `<tr>
        <td style="white-space:nowrap">${esc(r.occurred_at)}</td>
        <td>${esc(r.user_email) || '—'}</td>
        <td>${roleBadge}</td>
        <td>${esc(r.module)}</td>
        <td>${actionBadge}</td>
        <td class="max-w-[320px] break-words" title="${detail}">${detail}</td>
        <td>${r.application_id ? '#' + parseInt(r.application_id, 10) : '—'}</td>
        <td style="white-space:nowrap">${esc(r.ip_address) || '—'}</td>
    </tr>`;
        }

        function loadActivityLog() {
            const body = document.getElementById('al-body');
            fetch(SETTINGS_API + '?' + alQuery())
                .then(r => r.json())
                .then(d => {
                    if (!d.success) {
                        body.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:16px">${esc(d.message || 'Failed to load activity')}</td></tr>`;
                        return;
                    }
                    AL.pages = d.pages;
                    AL.page = d.page;
                    document.getElementById('al-total').textContent = d.total.toLocaleString() + (d.total === 1 ? ' record' : ' records');
                    document.getElementById('al-page-label').textContent = `Page ${d.page} of ${d.pages}`;
                    document.getElementById('al-prev').disabled = d.page <= 1;
                    document.getElementById('al-next').disabled = d.page >= d.pages;
                    document.getElementById('al-notice').classList.toggle('hidden', d.system_available);

                    if (!AL.optionsLoaded) {
                        alFillSelect('al-module', d.options.modules);
                        alFillSelect('al-action', d.options.actions);
                        AL.optionsLoaded = true;
                    }

                    body.innerHTML = d.data.length ?
                        d.data.map(alRow).join('') :
                        '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:16px">No activity found for the selected filters</td></tr>';
                })
                .catch(() => {
                    body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:16px">Request failed</td></tr>';
                });
        }

        (function initActivityLog() {
            const reload = () => {
                AL.page = 1;
                loadActivityLog();
            };

            document.querySelectorAll('#al-source [data-source]').forEach(btn => {
                btn.addEventListener('click', () => {
                    AL.source = btn.dataset.source;
                    document.querySelectorAll('#al-source [data-source]').forEach(b =>
                        b.dataset.active = String(b === btn));
                    reload();
                });
            });

            document.getElementById('al-filter-toggle').addEventListener('click', () =>
                document.getElementById('al-filters').classList.toggle('hidden'));

            ['al-from', 'al-to', 'al-role', 'al-module', 'al-action', 'al-mine'].forEach(id =>
                document.getElementById(id).addEventListener('change', reload));

            let timer;
            ['al-q', 'al-user', 'al-app'].forEach(id =>
                document.getElementById(id).addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(reload, 350);
                }));

            document.getElementById('al-reset').addEventListener('click', () => {
                ['al-from', 'al-to', 'al-role', 'al-module', 'al-action', 'al-user', 'al-app', 'al-q']
                .forEach(id => document.getElementById(id).value = '');
                document.getElementById('al-mine').checked = false;
                reload();
            });

            document.getElementById('al-prev').addEventListener('click', () => {
                if (AL.page > 1) {
                    AL.page--;
                    loadActivityLog();
                }
            });
            document.getElementById('al-next').addEventListener('click', () => {
                if (AL.page < AL.pages) {
                    AL.page++;
                    loadActivityLog();
                }
            });
        })();

        document.getElementById('password-form').addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            const newPass = f.new_password.value,
                confirmPass = f.confirm_password.value;
            if (newPass !== confirmPass) return toast('error', 'New passwords do not match');
            post(SETTINGS_API, {
                action: 'change_password',
                current_password: f.current_password.value,
                new_password: newPass
            }).then(d => {
                if (d.success) {
                    toast('success', 'Password updated');
                    f.reset();
                    //  CHANGE: reset the live checklist/match UI state after a successful update
                    document.getElementById('sec-password-requirements').classList.add('hidden');
                    document.getElementById('sec-password-match').textContent = '';
                } else toast('error', d.message || 'Failed to update password');
            });
        });

        //  CHANGE: Password visibility toggle + live requirements checklist for the
        // Security tab's Change Password form (same logic/behavior as create-account.php)
        (function() {
            function makeToggle(btnId, iconId, inputId) {
                const btn = document.getElementById(btnId);
                if (!btn) return;
                btn.addEventListener('click', function() {
                    const inp = document.getElementById(inputId);
                    const icon = document.getElementById(iconId);
                    if (inp.type === 'password') {
                        inp.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        inp.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                });
            }
            makeToggle('toggleCurrentPass', 'eyeCurrentPass', 'sec-current-pass');
            makeToggle('toggleNewPass', 'eyeNewPass', 'sec-new-pass');
            makeToggle('toggleConfirmPass', 'eyeConfirmPass', 'sec-confirm-pass');

            const pwdInput = document.getElementById('sec-new-pass');
            const confirmInput = document.getElementById('sec-confirm-pass');
            const requirementsBox = document.getElementById('sec-password-requirements');
            const matchMsg = document.getElementById('sec-password-match');

            if (!pwdInput || !confirmInput) return;

            const reqElements = {
                length: document.getElementById('sec-req-length'),
                upper: document.getElementById('sec-req-upper'),
                lower: document.getElementById('sec-req-lower'),
                number: document.getElementById('sec-req-number'),
                special: document.getElementById('sec-req-special')
            };

            function updateRequirements(value) {
                const checks = {
                    length: value.length >= 8,
                    upper: /[A-Z]/.test(value),
                    lower: /[a-z]/.test(value),
                    number: /[0-9]/.test(value),
                    special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(value)
                };
                for (const [key, ok] of Object.entries(checks)) {
                    reqElements[key].classList.toggle('met', ok);
                }
                requirementsBox.classList.remove('hidden');
            }

            function checkMatch() {
                if (!confirmInput.value) {
                    matchMsg.textContent = '';
                    return;
                }
                matchMsg.innerHTML = pwdInput.value === confirmInput.value ?
                    '<span style="color:#16a34a">✓ Passwords match</span>' :
                    '<span style="color:#dc2626">✗ Passwords do not match</span>';
            }

            pwdInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));
            confirmInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));

            [pwdInput, confirmInput].forEach(input => {
                input.addEventListener('blur', () => {
                    setTimeout(() => {
                        if (document.activeElement !== pwdInput && document.activeElement !== confirmInput) {
                            requirementsBox.classList.add('hidden');
                        }
                    }, 100);
                });
            });

            pwdInput.addEventListener('input', () => {
                updateRequirements(pwdInput.value);
                checkMatch();
            });
            confirmInput.addEventListener('input', checkMatch);
        })();

        /* ── LOOKUP TABLES (generic CRUD) ─────────────────────────── */
        const LOOKUP_TABLES = {
            job_categories: {
                cols: [{
                    key: 'category_name',
                    label: 'Category Name',
                    type: 'text',
                    required: true
                }],
                extraCols: [{
                    label: 'Subcategories',
                    render: r => r.dependent_count ?? 0
                }]
            },
            job_subcategories: {
                cols: [{
                        key: 'category_id',
                        label: 'Parent Category',
                        type: 'select',
                        source: 'job_categories',
                        optionValue: 'id',
                        optionLabel: 'category_name',
                        required: true
                    },
                    {
                        key: 'subcategory_name',
                        label: 'Subcategory Name',
                        type: 'text',
                        required: true
                    }
                ],
                extraCols: [{
                    label: 'Category',
                    render: r => r.category_name ?? '—'
                }]
            },
            degree_programs: {
                cols: [{
                        key: 'degree_name',
                        label: 'Degree Name',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'degree_category',
                        label: 'Category',
                        type: 'text',
                        required: false
                    }
                ]
            },
            professional_licenses: {
                cols: [{
                        key: 'license_name',
                        label: 'License Name',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'issuing_body',
                        label: 'Issuing Body',
                        type: 'text',
                        required: false
                    }
                ]
            },
            salary_ranges: {
                cols: [{
                        key: 'range_label',
                        label: 'Range Label',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'min_salary',
                        label: 'Min Salary',
                        type: 'number',
                        required: true
                    },
                    {
                        key: 'max_salary',
                        label: 'Max Salary',
                        type: 'number',
                        required: true
                    },
                    {
                        key: 'currency',
                        label: 'Currency',
                        type: 'text',
                        required: false
                    },
                    {
                        key: 'sort_order',
                        label: 'Sort Order',
                        type: 'number',
                        required: false
                    }
                ]
            },
            skills: {
                cols: [{
                        key: 'skill_name',
                        label: 'Skill Name',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'skill_category',
                        label: 'Category',
                        type: 'text',
                        required: false
                    }
                ]
            }
        };

        /* ── EMAIL CONFIGURATION ───────────────────────────────────── */
        let emailConfigured = false;

        function loadEmailSettings() {
            fetch(SETTINGS_API + '?action=get_email_settings').then(r => r.json()).then(d => {
                if (!d.success) return toast('error', d.message || 'Failed to load email settings');
                const s = d.data;
                emailConfigured = !!s.configured;

                document.getElementById('em-host').value = s.smtp_host;
                document.getElementById('em-port').value = s.smtp_port;
                document.getElementById('em-enc').value = s.smtp_encryption;
                document.getElementById('em-user').value = s.smtp_username;
                document.getElementById('em-from').value = s.from_email;
                document.getElementById('em-name').value = s.from_name;
                document.getElementById('em-pass').value = '';
                document.getElementById('em-pass').placeholder = s.has_password ? '•••••••• (leave blank to keep current)' : 'Enter password';
                document.getElementById('em-test-btn').disabled = !emailConfigured;
                document.getElementById('em-meta').textContent = s.updated_at ?
                    `Last updated ${s.updated_at} by ${s.updated_by || 'N/A'}` :
                    'Not configured yet. Enter your SMTP details and save.';
            });
        }

        // Suggest the usual port when encryption changes
        document.getElementById('em-enc').addEventListener('change', e => {
            const ports = {
                tls: 587,
                ssl: 465
            };
            if (ports[e.target.value]) document.getElementById('em-port').value = ports[e.target.value];
        });

        // Password eye toggle
        document.getElementById('toggleEmPass').addEventListener('click', () => {
            const inp = document.getElementById('em-pass');
            const icon = document.getElementById('eyeEmPass');
            const show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        });

        // App Password tutorial modal (Tailwind-styled)
        function openAppPasswordGuide() {
            const link = 'font-semibold text-blue-700 underline dark:text-blue-400';
            const code = 'rounded bg-slate-100 px-1.5 py-px text-xs dark:bg-slate-900 dark:text-slate-200';

            Swal.fire({
                title: 'How to get a Gmail App Password',
                customClass: {
                    popup: 'w-[560px] max-w-[94vw] !p-5 dark:!bg-slate-800',
                    title: 'dark:!text-slate-200',
                    htmlContainer: '!m-0 !p-0'
                },
                html: `
            <div class="mt-3 text-left text-[13px] text-slate-700 dark:text-slate-300">
                <p>Gmail does not accept your normal password for SMTP. You need a 16-character <strong>App Password</strong> instead.</p>
                <ol class="mt-3 list-decimal space-y-2 pl-5 leading-relaxed">
                    <li>Sign in to the Google account you will send emails from.</li>
                    <li>Turn on <strong>2-Step Verification</strong> (required):
                        <a class="${link}" href="https://myaccount.google.com/signinoptions/two-step-verification" target="_blank" rel="noopener noreferrer">Open 2-Step Verification</a>
                    </li>
                    <li>Open the App Passwords page:
                        <a class="${link}" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">myaccount.google.com/apppasswords</a>
                    </li>
                    <li>Enter an app name such as <code class="${code}">DMW System</code> and click <strong>Create</strong>.</li>
                    <li>Copy the 16-character password Google shows. You will not be able to see it again.</li>
                    <li>Paste it into <strong>SMTP Password / App Password</strong> on this page. Spaces are fine.</li>
                    <li>Use <code class="${code}">smtp.gmail.com</code> with <strong>STARTTLS (587)</strong> or <strong>SSL (465)</strong>. Set <strong>SMTP Username</strong> and <strong>From Email</strong> to the same Gmail address.</li>
                    <li>Click <strong>Save Email Settings</strong>, then <strong>Send Test to Me</strong>.</li>
                </ol>
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/35 dark:bg-amber-500/10 dark:text-amber-400">
                    <i class="fas fa-triangle-exclamation"></i>
                    If the App Passwords page is missing, 2-Step Verification is off, or your account is a school/work account where the administrator has disabled it.
                    Google's official guide: <a class="${link}" href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener noreferrer">support.google.com/accounts/answer/185833</a>
                </div>
            </div>`,
                showCancelButton: true,
                confirmButtonText: 'Open App Passwords page',
                cancelButtonText: 'Close',
                confirmButtonColor: '#1d4ed8'
            }).then(res => {
                if (res.isConfirmed) window.open('https://myaccount.google.com/apppasswords', '_blank', 'noopener');
            });
        }

        document.getElementById('em-guide-link').addEventListener('click', e => {
            e.preventDefault();
            openAppPasswordGuide();
        });

        document.getElementById('email-form').addEventListener('submit', e => {
            e.preventDefault();
            const pass = document.getElementById('em-pass').value;
            if (!emailConfigured && pass === '') return toast('error', 'SMTP password is required for the first setup');

            post(SETTINGS_API, {
                action: 'save_email_settings',
                smtp_host: document.getElementById('em-host').value.trim(),
                smtp_port: document.getElementById('em-port').value,
                smtp_encryption: document.getElementById('em-enc').value,
                smtp_username: document.getElementById('em-user').value.trim(),
                smtp_password: pass,
                from_email: document.getElementById('em-from').value.trim(),
                from_name: document.getElementById('em-name').value.trim()
            }).then(d => {
                if (d.success) {
                    toast('success', 'Email settings saved');
                    loadEmailSettings();
                } else {
                    toast('error', d.message || 'Failed to save email settings');
                }
            });
        });

        document.getElementById('em-test-btn').addEventListener('click', () => {
            Swal.fire({
                title: 'Sending test email...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            post(SETTINGS_API, {
                action: 'send_test_email'
            }).then(d => {
                if (d.success) Swal.fire('Sent', 'Test email sent to ' + d.sent_to, 'success');
                else Swal.fire('Test failed', d.message || 'Could not send test email', 'error');
            }).catch(() => Swal.fire('Error', 'Request failed', 'error'));
        });

        const lookupCache = {}; // table -> rows, used for dropdown sources

        function loadLookupTable(table, search = '') {
            const cfg = LOOKUP_TABLES[table];
            const thead = document.querySelector(`#table-${table} thead`);
            const tbody = document.querySelector(`#table-${table} tbody`);

            // build header once
            if (!thead.innerHTML) {
                let h = '<tr>';
                cfg.cols.forEach(c => h += `<th>${c.label}</th>`);
                (cfg.extraCols || []).forEach(c => h += `<th>${c.label}</th>`);
                h += '<th style="width:90px">Actions</th></tr>';
                thead.innerHTML = h;
            }

            fetch(`${LOOKUPS_API}?action=list&table=${table}&q=${encodeURIComponent(search)}`)
                .then(r => r.json())
                .then(d => {
                    if (!d.success) {
                        tbody.innerHTML = `<tr><td colspan="10">${d.message}</td></tr>`;
                        return;
                    }
                    lookupCache[table] = d.data;
                    tbody.innerHTML = '';
                    if (!d.data.length) {
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:16px">No records found</td></tr>`;
                        return;
                    }
                    d.data.forEach(row => {
                        let rowHtml = '<tr>';
                        cfg.cols.forEach(c => {
                            let val = row[c.key] ?? '';
                            if (c.type === 'select' && c.key === 'category_id') val = row.category_name ?? val;
                            rowHtml += `<td>${val}</td>`;
                        });
                        (cfg.extraCols || []).forEach(c => rowHtml += `<td>${c.render(row)}</td>`);
                        rowHtml += `<td>
                            <button class="s-icon-btn" data-edit="${table}" data-id="${row.id}"><i class="fas fa-pen"></i></button>
                            <button class="s-icon-btn danger" data-del="${table}" data-id="${row.id}"><i class="fas fa-trash"></i></button>
                        </td></tr>`;
                        tbody.insertAdjacentHTML('beforeend', rowHtml);
                    });
                });
        }

        // search debounce per table
        document.querySelectorAll('.s-search').forEach(input => {
            let timer;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => loadLookupTable(input.dataset.lookup, input.value.trim()), 350);
            });
        });

        // Add button
        document.querySelectorAll('[data-add]').forEach(btn => {
            btn.addEventListener('click', () => openLookupModal(btn.dataset.add, null));
        });

        // Delegated edit/delete
        document.getElementById('page-root') || document.body.addEventListener('click', e => {
            const editBtn = e.target.closest('[data-edit]');
            const delBtn = e.target.closest('[data-del]');
            if (editBtn) {
                const table = editBtn.dataset.edit,
                    id = editBtn.dataset.id;
                const row = (lookupCache[table] || []).find(r => String(r.id) === String(id));
                openLookupModal(table, row);
            }
            if (delBtn) {
                confirmDeleteLookup(delBtn.dataset.del, delBtn.dataset.id);
            }
        });

        async function openLookupModal(table, row) {
            const cfg = LOOKUP_TABLES[table];
            const isEdit = !!row;

            // preload select sources if needed
            for (const c of cfg.cols) {
                if (c.type === 'select' && !lookupCache[c.source]) {
                    const r = await fetch(`${LOOKUPS_API}?action=list&table=${c.source}`).then(x => x.json());
                    lookupCache[c.source] = r.data || [];
                }
            }

            let html = '<div style="text-align:left">';
            cfg.cols.forEach(c => {
                const val = row ? (row[c.key] ?? '') : '';
                if (c.type === 'select') {
                    let opts = (lookupCache[c.source] || []).map(o =>
                        `<option value="${o[c.optionValue]}" ${String(o[c.optionValue]) === String(val) ? 'selected' : ''}>${o[c.optionLabel]}</option>`
                    ).join('');
                    html += `<label style="font-size:12px;font-weight:600;display:block;margin:10px 0 4px">${c.label}</label>
                        <select id="modal-${c.key}" class="swal2-select" style="width:100%;display:block">${opts}</select>`;
                } else {
                    html += `<label style="font-size:12px;font-weight:600;display:block;margin:10px 0 4px">${c.label}</label>
                        <input id="modal-${c.key}" type="${c.type}" class="swal2-input" style="width:100%;margin:0" value="${val}">`;
                }
            });
            html += '</div>';

            const res = await Swal.fire({
                title: isEdit ? 'Edit Record' : 'Add New Record',
                html,
                showCancelButton: true,
                confirmButtonText: isEdit ? 'Save Changes' : 'Add',
                preConfirm: () => {
                    const payload = {};
                    for (const c of cfg.cols) {
                        const el = document.getElementById(`modal-${c.key}`);
                        const v = el.value.trim();
                        if (c.required && !v) {
                            Swal.showValidationMessage(`${c.label} is required`);
                            return false;
                        }
                        payload[c.key] = v;
                    }
                    return payload;
                }
            });

            if (!res.isConfirmed) return;

            const action = isEdit ? 'update' : 'create';
            const body = {
                action,
                table,
                data: res.value
            };
            if (isEdit) body.id = row.id;

            post(LOOKUPS_API, body).then(d => {
                if (d.success) {
                    toast('success', isEdit ? 'Record updated' : 'Record added');
                    loadLookupTable(table);
                } else {
                    Swal.fire('Error', d.message || 'Operation failed', 'error');
                }
            });
        }

        function confirmDeleteLookup(table, id) {
            // Step 1: check dependents
            fetch(`${LOOKUPS_API}?action=check_delete&table=${table}&id=${id}`)
                .then(r => r.json())
                .then(check => {
                    if (!check.success) return toast('error', check.message || 'Check failed');

                    if (check.blocked) {
                        Swal.fire('Cannot Delete', check.message, 'error');
                        return;
                    }

                    const warnText = check.warning ? check.warning : 'This action cannot be undone.';
                    Swal.fire({
                        title: 'Delete this record?',
                        text: warnText,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: 'Delete'
                    }).then(res => {
                        if (!res.isConfirmed) return;
                        post(LOOKUPS_API, {
                            action: 'delete',
                            table,
                            id
                        }).then(d => {
                            if (d.success) {
                                toast('success', 'Record deleted');
                                loadLookupTable(table);
                            } else Swal.fire('Error', d.message || 'Delete failed', 'error');
                        });
                    });
                });
        }

        /* Init */
        loadProfile();
        if (location.hash === '#security') switchTab('security');
    </script>
    <script>
        // Pin subbar directly under the topbar, and reserve space so content
        // isn't hidden behind the two fixed bars. Recalculates on load/resize
        // since topbar height can differ across breakpoints. Also exposes
        // the same offset as a CSS var so the sticky side-nav aligns with it.
        function pinSubbar() {
            const topbar = document.querySelector('.topbar');
            const subbar = document.querySelector('.subbar');
            const shell = document.querySelector('.settings-shell');
            if (!topbar || !subbar || !shell) return;

            const topbarHeight = topbar.offsetHeight;
            subbar.style.top = topbarHeight + 'px';

            const subbarHeight = subbar.offsetHeight;
            const totalOffset = topbarHeight + subbarHeight + 16;
            shell.style.paddingTop = totalOffset + 'px';
            document.documentElement.style.setProperty('--sticky-top', totalOffset + 'px');
        }

        window.addEventListener('load', pinSubbar);
        window.addEventListener('resize', pinSubbar);
        // Re-check shortly after load in case fonts/icons shift layout height
        setTimeout(pinSubbar, 300);
    </script>
    <script>
        /* ── Profile phone: digits only, 09XXXXXXXXX ───────────────── */
        (function() {
            const phone = document.getElementById('pf-phone');
            const msg = document.getElementById('pf-phone-msg');
            if (!phone) return;
            const hint = 'Format: 09123456789 (11 digits, numbers only)';

            function setMsg(text, color) {
                msg.textContent = text;
                msg.style.color = color || '';
            }

            function validate() {
                const v = phone.value;
                if (v === '') {
                    phone.setCustomValidity('');
                    return setMsg(hint);
                }
                if (v.length >= 2 && !v.startsWith('09')) {
                    phone.setCustomValidity('Number must start with 09.');
                    return setMsg('✗ Number must start with 09.', '#dc2626');
                }
                if (v.length < 11) {
                    phone.setCustomValidity('Phone number must be 11 digits.');
                    return setMsg('✗ ' + v.length + '/11 digits entered.', '#dc2626');
                }
                phone.setCustomValidity('');
                setMsg('✓ Valid phone number', '#16a34a');
            }

            phone.addEventListener('input', () => {
                phone.value = phone.value.replace(/\D/g, '').slice(0, 11);
                validate();
            });

            phone.addEventListener('keydown', e => {
                const allowed = ['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter'];
                if (allowed.includes(e.key) || e.ctrlKey || e.metaKey) return;
                if (!/^\d$/.test(e.key)) e.preventDefault();
            });

            // loadProfile() fills the value programmatically, so re-validate after it runs
            new MutationObserver(validate).observe(phone, {
                attributes: true,
                attributeFilter: ['value']
            });
            document.getElementById('profile-form').addEventListener('reset', () => setTimeout(validate, 0));
            window.validateProfilePhone = validate;
        })();
    </script>
    <script type="module" src="../js/main.js"></script>
    <?php include '../includes/login_toast.php'; ?>
    <script src="../js/theme.js"></script>
    <script src="../js/avatar-menu.js"></script>
</body>

</html>
<?php
// admin/verify-certificate.php
// Lets an admin verify a certificate's Reference No. + Verification Code
// (the values printed on the PDF / encoded in its QR code) against the
// database.
// No new table is required: verify_code is a deterministic hash of
//   app_id + evaluated_at + DMW_EMAIL_VERIFY_SECRET
// (see includes/mailer.php -> dmw_build_status_data()). To verify, this
// page just re-derives the same value from the application/evaluation
// records and compares it to what the admin typed in. If they match, the
// document is provably unaltered and genuinely issued by this system.
//
// The QR code on the certificate currently encodes plain text
// ("Ref:APP-2026-000060 Code:XXXXXXXXXXXXXXXX"), so an admin can scan it
// with any camera app and paste the result straight into the "Paste QR
// text" field below — no scanner integration needed on this page.

session_start();
require_once '../db.php';
require_once '../includes/status_email.php'; // dmw_build_status_data(), DMW_EMAIL_VERIFY_SECRET

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

// Admin name from session or DB (same pattern as dashboard.php)
$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

// Avatar initials
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);

// ── Simple CSRF token (swap for your project's shared csrf helper if you
//    already have one elsewhere in the admin panel, for consistency) ──────
if (empty($_SESSION['verify_csrf'])) {
    $_SESSION['verify_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['verify_csrf'];

$result       = null;   // 'verified' | 'mismatch' | 'not_found' | 'invalid_format' | null
$certData     = null;
$refNoInput   = '';
$codeInput    = '';
$statusNote   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['verify_csrf'], $_POST['csrf_token'] ?? '')) {
        $result = 'invalid_format';
    } else {
        $qrText     = trim($_POST['qr_text'] ?? '');
        $refNoInput = trim($_POST['ref_no'] ?? '');
        $codeInput  = trim($_POST['verify_code'] ?? '');

        // If the admin pasted the raw QR text instead of using the two
        // separate fields, parse "Ref:XXX Code:YYY" out of it.
        if ($qrText !== '' && preg_match('/Ref:\s*(\S+)\s+Code:\s*(\S+)/i', $qrText, $m)) {
            $refNoInput = $m[1];
            $codeInput  = $m[2];
        }

        // Reference number format: APP-YYYY-NNNNNN
        if (!preg_match('/^APP-(\d{4})-(\d{6})$/i', $refNoInput, $refMatch)) {
            $result = 'invalid_format';
        } else {
            $app_id = (int) ltrim($refMatch[2], '0');
            if ($app_id === 0) {
                $result = 'invalid_format';
            } else {
                // Rebuild the same data set the certificate/email used —
                // single source of truth, no duplicated lookup logic.
                $certData = dmw_build_status_data($database, $app_id, 'approved');

                if ($certData === null) {
                    $result = 'not_found';
                } else {
                    $refMatches  = strcasecmp($certData['ref_no'], $refNoInput) === 0;
                    $codeMatches = hash_equals($certData['verify_code'], strtoupper($codeInput));

                    if ($refMatches && $codeMatches) {
                        $result = 'verified';
                        // The certificate's integrity is confirmed, but the
                        // application's *current* status may have moved on
                        // since issuance (e.g. reopened for compliance).
                        // Surface that separately so the admin sees both.
                        if ($certData['status'] !== 'approved') {
                            $statusNote = "Note: this application's current status is now \""
                                . ucwords(str_replace('_', ' ', $certData['status']))
                                . "\" — the certificate reflects its state at the time it was issued.";
                        }
                    } else {
                        $result = 'mismatch';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify Certificate – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="../pictures/dmw_logo.png">

    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* ── Page-specific: verification form + result cards ───────────── */
        .verify-shell {
            max-width: 640px;
            margin: 0 auto;
        }

        .verify-field {
            margin-bottom: 16px;
        }

        .verify-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3, #94a3b8);
            margin-bottom: 6px;
        }

        .verify-input {
            width: 100%;
            border: 1.5px solid var(--border, #e2e8f0);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text1, #0f172a);
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
        }

        .verify-input:focus {
            outline: none;
            border-color: var(--blue, #1d4ed8);
            box-shadow: 0 0 0 3px rgba(29, 78, 216, .12);
        }

        .verify-input.mono {
            font-family: 'DM Mono', ui-monospace, monospace;
        }

        .verify-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 18px 0;
            color: var(--text3, #94a3b8);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .verify-divider::before,
        .verify-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border, #e2e8f0);
        }

        .verify-hint {
            font-size: 11px;
            color: var(--text3, #94a3b8);
            margin-top: 6px;
        }

        .verify-submit {
            width: 100%;
            background: var(--navy, #0b1f4b);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            padding: 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: background .15s, transform .1s;
        }

        .verify-submit:hover {
            background: #132a63;
        }

        .verify-submit:active {
            transform: translateY(1px);
        }

        /* Result panel — reuses .card look with a colored left accent */
        .result-card {
            border-radius: 14px;
            padding: 22px;
            border: 1.5px solid;
        }

        .result-card.rc-ok {
            background: #f0fdf9;
            border-color: #a7f3d0;
        }

        .result-card.rc-bad {
            background: #fef2f2;
            border-color: #fecaca;
        }

        .result-card.rc-warn {
            background: #fffbeb;
            border-color: #fde68a;
        }

        .result-card.rc-neutral {
            background: #f8fafc;
            border-color: var(--border, #e2e8f0);
        }

        .result-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .result-title {
            font-weight: 800;
            font-size: 15px;
        }

        .rc-ok .result-title {
            color: #047857;
        }

        .rc-bad .result-title {
            color: #b91c1c;
        }

        .rc-warn .result-title {
            color: #b45309;
        }

        .rc-neutral .result-title {
            color: var(--text2, #475569);
        }

        .result-note {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            font-size: 11.5px;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 12px 0;
        }

        .cert-table {
            width: 100%;
            font-size: 12.5px;
            margin-top: 14px;
            border-collapse: collapse;
        }

        .cert-table tr {
            border-bottom: 1px solid rgba(5, 150, 105, .12);
        }

        .cert-table tr:last-child {
            border-bottom: none;
        }

        .cert-table td {
            padding: 8px 4px;
            vertical-align: top;
        }

        .cert-table td:first-child {
            width: 42%;
            font-weight: 600;
            color: #047857;
        }

        .cert-table td:last-child {
            color: var(--text1, #0f172a);
        }

        .cert-table td.mono {
            font-family: 'DM Mono', ui-monospace, monospace;
        }
    </style>
</head>

<body>

    <!--  TOP BAR  -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_logo.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!--  SUBBAR  -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Certificate Verification</h2>
                <p>Confirm the authenticity of an Evaluation Completion Certificate presented by an applicant.</p>
            </div>
        </div>
    </div>

    <!--  MAIN  -->
    <div class="main">
        <div class="verify-shell">

            <div class="card fade-in d1">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--blue-lt,#3b82f6)"></span>
                        Verify a Certificate
                    </div>
                </div>

                <form method="POST" style="padding-top:4px">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="verify-field">
                        <label class="verify-label">Reference No.</label>
                        <input type="text" name="ref_no" placeholder="APP-2026-000060"
                            value="<?php echo htmlspecialchars($refNoInput); ?>"
                            class="verify-input mono">
                    </div>

                    <div class="verify-field">
                        <label class="verify-label">Verification Code</label>
                        <input type="text" name="verify_code" placeholder="16-character code from the certificate"
                            value="<?php echo htmlspecialchars($codeInput); ?>"
                            class="verify-input mono" style="text-transform:uppercase">
                    </div>

                    <div class="verify-divider">or</div>

                    <div class="verify-field" style="margin-bottom:20px">
                        <label class="verify-label">Paste QR Text</label>
                        <input type="text" name="qr_text" placeholder="Ref:APP-2026-000060 Code:XXXXXXXXXXXXXXXX"
                            class="verify-input mono">
                        <p class="verify-hint">Scan the QR on the certificate with any camera app, then paste the result here.</p>
                    </div>

                    <button type="submit" class="verify-submit">
                        <i class="fa-solid fa-shield-halved" style="margin-right:6px"></i> Verify Certificate
                    </button>
                </form>
            </div>

            <?php if ($result === 'verified'): ?>
                <div class="result-card rc-ok fade-in d2" style="margin-top:16px">
                    <div class="result-head">
                        <i class="fa-solid fa-circle-check" style="color:#059669;font-size:19px"></i>
                        <span class="result-title">Certificate Verified</span>
                    </div>
                    <p style="font-size:12.5px;color:#065f46">This reference number and code match a genuine, unaltered certificate issued by this system.</p>

                    <?php if ($statusNote): ?>
                        <div class="result-note">
                            <i class="fa-solid fa-triangle-exclamation" style="margin-right:4px"></i>
                            <?php echo htmlspecialchars($statusNote); ?>
                        </div>
                    <?php endif; ?>

                    <table class="cert-table">
                        <tr>
                            <td>Applicant</td>
                            <td><?php echo htmlspecialchars($certData['applicant_name']); ?></td>
                        </tr>
                        <tr>
                            <td>Email</td>
                            <td><?php echo htmlspecialchars($certData['applicant_email']); ?></td>
                        </tr>
                        <tr>
                            <td>Position</td>
                            <td><?php echo htmlspecialchars($certData['position_title']); ?></td>
                        </tr>
                        <tr>
                            <td>Employer</td>
                            <td><?php echo htmlspecialchars($certData['employer_name']); ?></td>
                        </tr>
                        <tr>
                            <td>RSF Number</td>
                            <td class="mono"><?php echo htmlspecialchars($certData['rsf_number']); ?></td>
                        </tr>
                        <tr>
                            <td>Evaluated By</td>
                            <td><?php echo htmlspecialchars($certData['evaluator_name'] ?: ($certData['admin_name'] . ' (Admin)')); ?></td>
                        </tr>
                        <tr>
                            <td>Date of Evaluation</td>
                            <td><?php echo date('F d, Y g:i a', strtotime($certData['evaluated_at'])); ?></td>
                        </tr>
                        <tr>
                            <td>Reference No.</td>
                            <td class="mono"><?php echo htmlspecialchars($certData['ref_no']); ?></td>
                        </tr>
                    </table>
                </div>

            <?php elseif ($result === 'mismatch'): ?>
                <div class="result-card rc-bad fade-in d2" style="margin-top:16px;text-align:center">
                    <i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:26px;margin-bottom:6px"></i>
                    <div class="result-title" style="margin-bottom:4px">Verification Failed</div>
                    <p style="font-size:12.5px;color:#991b1b">The reference number and code do not match. This document may be altered or fraudulent — do not accept it as valid.</p>
                </div>

            <?php elseif ($result === 'not_found'): ?>
                <div class="result-card rc-warn fade-in d2" style="margin-top:16px;text-align:center">
                    <i class="fa-solid fa-circle-question" style="color:#d97706;font-size:26px;margin-bottom:6px"></i>
                    <div class="result-title" style="margin-bottom:4px">No Matching Record</div>
                    <p style="font-size:12.5px;color:#92400e">No approved application matches this reference number.</p>
                </div>

            <?php elseif ($result === 'invalid_format'): ?>
                <div class="result-card rc-neutral fade-in d2" style="margin-top:16px;text-align:center">
                    <i class="fa-solid fa-circle-exclamation" style="color:var(--text3,#94a3b8);font-size:26px;margin-bottom:6px"></i>
                    <div class="result-title" style="margin-bottom:4px">Invalid Input</div>
                    <p style="font-size:12.5px;color:var(--text2,#475569)">Check the reference number format (APP-YYYY-NNNNNN) and try again.</p>
                </div>
            <?php endif; ?>

        </div>
    </div><!-- /main -->

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <?php
                // Dynamic badge count (same query as dashboard.php)
                $badge_stmt = $database->prepare("
                SELECT COUNT(*) as total 
                FROM applications 
                WHERE status IN ('pending', 'for_compliance', 'resubmitted')
            ");
                $badge_stmt->execute();
                $badge_result = $badge_stmt->get_result()->fetch_assoc();
                $active_apps = $badge_result['total'] ?? 0;
                ?>
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
            <a href="applicant-reports.php" class="dk" data-tip="Reports">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>
            <a href="analytics.php" class="dk" data-tip="Analytics">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Analytics</span>
            </a>
            <a href="verify-certificate.php" class="dk active" data-tip="Verify Certificate">
                <i class="fa-solid fa-shield-halved"></i>
                <span class="dk-lbl">Verify</span>
            </a>
            <a href="user.php" class="dk" data-tip="Users">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Users</span>
            </a>
            <div class="dk-div"></div>
            <a href="settings.php" class="dk" data-tip="Settings">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>
            <div class="dk" data-tip="Logout" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <!--  JAVASCRIPT  -->
    <script>
        /* Live date + time (same pattern as dashboard.php) */
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

        /* Dock flyout (same as dashboard.php) */
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
    </script>
    <script type="module" src="../js/main.js"></script>
    <?php include '../includes/login_toast.php'; ?>
</body>

</html>
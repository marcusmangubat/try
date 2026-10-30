<?php
// client/certificate.php
// Lets a logged-in applicant download their "Official Evaluation Completion"
// certificate as a PDF, styled to match the uploaded DMW template. Only
// available once the application's evaluation decision is 'approved'.
//
// The certificate HTML + Dompdf rendering now lives in
// ../includes/certificate_template.php so the exact same PDF can also be
// attached to the approval email (see includes/mailer.php) without
// duplicating the markup.
//
// Requires the Dompdf library. Install it the same way phpmailer/ was added
// to this project (no Composer needed):
//   1. Download: https://github.com/dompdf/dompdf/releases (latest .zip)
//   2. Extract it into your project root as:  /dompdf/
//   3. Also grab its dependencies (php-font-lib, php-svg-lib) — the release
//      zip from GitHub's "Assets" section already bundles these; if you
//      instead cloned the bare repo, follow Dompdf's README for Composer.
// If you'd rather use Composer: `composer require dompdf/dompdf` and
// change the require in certificate_template.php to
// require_once '../vendor/autoload.php';

session_start();
require_once '../db.php';
require_once '../includes/certificate_template.php';
require_once '../includes/status_email.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$app_id       = (int) ($_GET['id'] ?? 0);
$client_email = $_SESSION['user_email'];

if (!$app_id) {
    header('Location: applications.php');
    exit;
}

// Ownership + status check: only the owning client, and only once the
// application's evaluation decision is 'approved', can download this.
$stmt = $database->prepare("
    SELECT a.id
    FROM applications a
    JOIN clients c ON a.client_id = c.cid
    WHERE a.id = ? AND c.email = ? AND a.status = 'approved'
    LIMIT 1
");
$stmt->bind_param("is", $app_id, $client_email);
$stmt->execute();
$owns = $stmt->get_result()->num_rows === 1;
$stmt->close();

if (!$owns) {
    header('Location: applications.php?error=certificate_unavailable');
    exit;
}

$data = dmw_build_status_data($database, $app_id, 'approved');
if ($data === null) {
    header('Location: applications.php?error=certificate_unavailable');
    exit;
}

$dompdf   = dmw_render_certificate_pdf($data);
$filename = "DMW_Certificate_{$data['ref_no']}.pdf";
$dompdf->stream($filename, ['Attachment' => true]); // false = open inline in browser instead of forcing download
exit;

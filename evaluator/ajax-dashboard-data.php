<?php
// evaluator/ajax-dashboard-data.php
// Returns the evaluator dashboard's filtered data as JSON, so the
// dashboard's filter pills can update every section without a page
// reload. Reuses evaluator_dashboard_data() — same logic as index.php.
session_start();
require_once '../db.php';
require_once '../auth.php';
require_once '../includes/app-helpers.php';
require_once '../includes/dashboard-data.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

releaseResubmissionsToEvaluator($database);

$evaluator_email = $_SESSION['user_email'];
$evaluator_id = null;

$stmt_ev = $database->prepare("SELECT eid FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$res = $stmt_ev->get_result();
if ($res->num_rows === 1) {
    $evaluator_id = (int)$res->fetch_assoc()['eid'];
}
$stmt_ev->close();

$filters = [
    'month'    => preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '',
    'province' => trim($_GET['province'] ?? ''),
    'job'      => trim($_GET['job'] ?? ''),
    'country'  => trim($_GET['country'] ?? ''),
];

$data = evaluator_dashboard_data($database, (int)$evaluator_id, $filters);

// Add display-ready fields so the front end doesn't need to reimplement
// PHP's date formatting / avatar-initials logic.
foreach ($data['my_applications'] as &$app) {
    $app['status_text']     = str_replace('_', ' ', ucwords(strtolower($app['status'] ?? 'unknown')));
    $app['updated_display'] = $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—';
    $app['picture_url']     = !empty($app['applicant_picture']) ? '../' . $app['applicant_picture'] : null;
}
unset($app);

foreach ($data['turnaround']['by_decision'] as $dec => $vals) {
    $data['turnaround']['by_decision'][$dec]['display'] = format_turnaround($vals['avg_hours']);
}
$data['turnaround']['overall_display'] = format_turnaround($data['turnaround']['overall_hours']);

echo json_encode($data);

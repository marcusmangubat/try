<?php
// api/poll_notifications.php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

$allowed_types = ['client', 'evaluator', 'admin'];

if (!isset($_SESSION['user_email']) || !in_array($_SESSION['user_type'] ?? '', $allowed_types, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$email    = $_SESSION['user_email'];
$usertype = $_SESSION['user_type'];
$init     = isset($_GET['init']);
$since_id = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

/**
 * Available (unclaimed) application count — same definition used on
 * evaluator/index.php. Only relevant to evaluators/admins, but harmless
 * to compute for everyone since it's a cheap indexed COUNT.
 */
function get_available_count(mysqli $database): int
{
    $res = $database->query("
        SELECT COUNT(*) AS cnt FROM applications
        WHERE handling_party IS NULL
          AND status IN ('pending','assign','assigned','resubmitted')
    ");
    return $res ? (int) $res->fetch_assoc()['cnt'] : 0;
}

if ($init) {
    $stmt = $database->prepare("
        SELECT COALESCE(MAX(id), 0) AS max_id
        FROM notifications
        WHERE user_email = ? AND usertype = ?
    ");
    $stmt->bind_param("ss", $email, $usertype);
    $stmt->execute();
    $max_id = (int) $stmt->get_result()->fetch_assoc()['max_id'];
    $stmt->close();

    echo json_encode([
        'success'         => true,
        'last_id'         => $max_id,
        'notifications'   => [],
        'available_count' => get_available_count($database),
    ]);
    exit;
}

$stmt = $database->prepare("
    SELECT id, application_id, title, message, type, created_at
    FROM notifications
    WHERE user_email = ?
      AND usertype = ?
      AND id > ?
    ORDER BY id ASC
    LIMIT 20
");
$stmt->bind_param("ssi", $email, $usertype, $since_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
$max_id = $since_id;

while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id'             => (int) $row['id'],
        'application_id' => $row['application_id'] !== null ? (int) $row['application_id'] : null,
        'title'          => $row['title'],
        'message'        => $row['message'],
        'type'           => $row['type'],
        'created_at'     => $row['created_at'],
    ];
    $max_id = max($max_id, (int) $row['id']);
}
$stmt->close();

echo json_encode([
    'success'         => true,
    'notifications'   => $notifications,
    'last_id'         => $max_id,
    'available_count' => get_available_count($database),
]);

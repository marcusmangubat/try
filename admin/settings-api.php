<?php

/**
 * settings-api.php – Profile & Security actions for the admin settings page
 * Actions:
 *   GET  ?action=get_profile
 *   GET  ?action=login_activity
 *   POST action=update_profile      { full_name, phone, email }
 *   POST action=change_password     { current_password, new_password }
 */

session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_email = $_SESSION['user_email'];
$method = $_SERVER['REQUEST_METHOD'];

function respond(array $arr)
{
    echo json_encode($arr);
    exit;
}

function verify_csrf(array $input)
{
    if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
        respond(['success' => false, 'message' => 'Invalid session token. Please refresh the page.']);
    }
}

/** Loads the mailer + crypto code only when an email action needs it. */
function email_bootstrap()
{
    if (!is_file(__DIR__ . '/../config/secret.php')) {
        respond(['success' => false, 'message' => 'Encryption key file (config/secret.php) is missing on the server.']);
    }
    require_once __DIR__ . '/../includes/mailer.php';
}

/*  GET actions  */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_profile') {
        $stmt = $database->prepare("
            SELECT a.full_name, a.phone, a.created_at, w.email, w.usertype, w.updated_at
            FROM admins a
            JOIN webuser w ON w.email = a.email
            WHERE a.email = ? LIMIT 1
        ");
        $stmt->bind_param('s', $admin_email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) respond(['success' => false, 'message' => 'Profile not found']);

        respond(['success' => true, 'data' => [
            'full_name'  => $row['full_name'],
            'phone'      => $row['phone'],
            'email'      => $row['email'],
            'usertype'   => $row['usertype'],
            'created_at' => date('M d, Y', strtotime($row['created_at'])),
            'updated_at' => date('M d, Y g:i A', strtotime($row['updated_at'])),
        ]]);
    }

    if ($action === 'login_activity') {
        $stmt = $database->prepare("
            SELECT ip_address, user_agent, success, attempted_at
            FROM login_attempts
            WHERE email = ?
            ORDER BY attempted_at DESC
            LIMIT 15
        ");
        $stmt->bind_param('s', $admin_email);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $r['attempted_at'] = date('M d, Y g:i A', strtotime($r['attempted_at']));
            $rows[] = $r;
        }
        $stmt->close();
        respond(['success' => true, 'data' => $rows]);
    }
    if ($action === 'get_email_settings') {
        $res = $database->query("SELECT smtp_host, smtp_port, smtp_encryption, smtp_username,
                                        smtp_password_enc, from_email, from_name, updated_by, updated_at
                                 FROM email_settings WHERE id = 1 LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;

        if (!$row) {
            respond(['success' => true, 'data' => [
                'configured'      => false,
                'smtp_host'       => 'smtp.gmail.com',
                'smtp_port'       => 587,
                'smtp_encryption' => 'tls',
                'smtp_username'   => '',
                'from_email'      => '',
                'from_name'       => 'DMW AMS',
                'has_password'    => false,
                'updated_by'      => null,
                'updated_at'      => null,
            ]]);
        }

        respond(['success' => true, 'data' => [
            'configured'      => true,
            'smtp_host'       => $row['smtp_host'],
            'smtp_port'       => (int) $row['smtp_port'],
            'smtp_encryption' => $row['smtp_encryption'],
            'smtp_username'   => $row['smtp_username'],
            'from_email'      => $row['from_email'],
            'from_name'       => $row['from_name'],
            'has_password'    => $row['smtp_password_enc'] !== '',   // password itself is never returned
            'updated_by'      => $row['updated_by'],
            'updated_at'      => date('M d, Y g:i A', strtotime($row['updated_at'])),
        ]]);
    }

    if ($action === 'activity_log') {
        $has_sys = (bool) $database->query("SHOW TABLES LIKE 'activity_logs'")->num_rows;

        // Normalised union: sign-ins + system actions share one column shape.
        $parts = ["
            SELECT 'login' AS source, la.attempted_at AS occurred_at,
                   la.email COLLATE utf8mb4_general_ci AS user_email,
                   w.usertype COLLATE utf8mb4_general_ci AS usertype,
                   'Authentication' AS module,
                   IF(la.success = 1, 'Login Success', 'Login Failed') AS action,
                   la.user_agent COLLATE utf8mb4_general_ci AS description,
                   NULL AS application_id,
                   la.ip_address COLLATE utf8mb4_general_ci AS ip_address,
                   la.success AS success
            FROM login_attempts la
            LEFT JOIN webuser w ON w.email = la.email
        "];
        if ($has_sys) {
            $parts[] = "
                SELECT 'system', l.created_at,
                       l.user_email COLLATE utf8mb4_general_ci,
                       l.usertype COLLATE utf8mb4_general_ci,
                       l.module COLLATE utf8mb4_general_ci,
                       l.action COLLATE utf8mb4_general_ci,
                       l.description COLLATE utf8mb4_general_ci,
                       l.application_id,
                       l.ip_address COLLATE utf8mb4_general_ci,
                       NULL
                FROM activity_logs l
            ";
        }
        $union = implode(' UNION ALL ', $parts);

        // ---- Filters (all values bound, never concatenated) ----
        $where = [];
        $types = '';
        $params = [];
        $clip = fn($v) => mb_substr(trim((string) $v), 0, 100);

        $source = $_GET['source'] ?? 'all';
        if (in_array($source, ['login', 'system'], true)) {
            $where[] = 't.source = ?';
            $types .= 's';
            $params[] = $source;
        }
        $from = $_GET['from'] ?? '';
        if ($from !== '' && DateTime::createFromFormat('Y-m-d', $from)) {
            $where[] = 't.occurred_at >= ?';
            $types .= 's';
            $params[] = $from . ' 00:00:00';
        }
        $to = $_GET['to'] ?? '';
        if ($to !== '' && DateTime::createFromFormat('Y-m-d', $to)) {
            $where[] = 't.occurred_at <= ?';
            $types .= 's';
            $params[] = $to . ' 23:59:59';
        }
        $role = $_GET['role'] ?? '';
        if (in_array($role, ['client', 'evaluator', 'admin'], true)) {
            $where[] = 't.usertype = ?';
            $types .= 's';
            $params[] = $role;
        }
        if (($module = $clip($_GET['module'] ?? '')) !== '') {
            $where[] = 't.module = ?';
            $types .= 's';
            $params[] = $module;
        }
        if (($act = $clip($_GET['act'] ?? '')) !== '') {
            $where[] = 't.action = ?';
            $types .= 's';
            $params[] = $act;
        }
        if (($user = $clip($_GET['user'] ?? '')) !== '') {
            $where[] = 't.user_email LIKE ?';
            $types .= 's';
            $params[] = '%' . $user . '%';
        }
        $app_id = $_GET['app_id'] ?? '';
        if ($app_id !== '' && ctype_digit($app_id)) {
            $where[] = 't.application_id = ?';
            $types .= 'i';
            $params[] = (int) $app_id;
        }
        if (($q = $clip($_GET['q'] ?? '')) !== '') {
            $where[] = '(t.description LIKE ? OR t.ip_address LIKE ?)';
            $types .= 'ss';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if (($_GET['mine'] ?? '') === '1') {
            $where[] = 't.user_email = ?';
            $types .= 's';
            $params[] = $admin_email;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // ---- Pagination ----
        $per_page = 20;
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $per_page;

        $stmt = $database->prepare("SELECT COUNT(*) FROM ($union) t $whereSql");
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($total);
        $stmt->fetch();
        $stmt->close();
        $pages = max(1, (int) ceil($total / $per_page));

        $stmt = $database->prepare("
            SELECT t.source, t.occurred_at, t.user_email, t.usertype, t.module, t.action,
                   t.description, t.application_id, t.ip_address, t.success
            FROM ($union) t $whereSql
            ORDER BY t.occurred_at DESC
            LIMIT ? OFFSET ?
        ");
        $t2 = $types . 'ii';
        $p2 = array_merge($params, [$per_page, $offset]);
        $stmt->bind_param($t2, ...$p2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$r) {
            $r['occurred_at'] = date('M d, Y g:i A', strtotime($r['occurred_at']));
        }
        unset($r);

        // Dropdown options (static union text, no user input)
        $modules = array_column($database->query("SELECT DISTINCT module FROM ($union) t WHERE module IS NOT NULL ORDER BY module")->fetch_all(MYSQLI_ASSOC), 'module');
        $actions = array_column($database->query("SELECT DISTINCT action FROM ($union) t WHERE action IS NOT NULL ORDER BY action")->fetch_all(MYSQLI_ASSOC), 'action');

        respond([
            'success'          => true,
            'data'             => $rows,
            'total'            => (int) $total,
            'page'             => $page,
            'pages'            => $pages,
            'system_available' => $has_sys,
            'options'          => ['modules' => $modules, 'actions' => $actions],
        ]);
    }

    respond(['success' => false, 'message' => 'Unknown action']);
}

/* ───────────────────────── POST actions ──────────────────────── */
$input = json_decode(file_get_contents('php://input'), true) ?? [];
verify_csrf($input);
$action = $input['action'] ?? '';

if ($action === 'update_profile') {
    $full_name = trim($input['full_name'] ?? '');
    $phone     = trim($input['phone'] ?? '');
    $new_email = trim($input['email'] ?? '');

    if ($full_name === '' || $new_email === '') {
        respond(['success' => false, 'message' => 'Full name and email are required']);
    }
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Invalid email format']);
    }
    if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
        respond(['success' => false, 'message' => 'Phone must be exactly 11 digits and start with 09 (e.g., 09123456789).']);
    }

    $email_changed = ($new_email !== $admin_email);

    if ($email_changed) {
        // Ensure new email isn't already taken
        $chk = $database->prepare("SELECT email FROM webuser WHERE email = ? LIMIT 1");
        $chk->bind_param('s', $new_email);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            respond(['success' => false, 'message' => 'That email is already in use']);
        }
        $chk->close();

        // webuser.email is a PK referenced by several FKs (admins, notifications,
        // document_comments) without ON UPDATE CASCADE defined in the schema.
        // We update every dependent row inside a single transaction, disabling
        // FK checks for the duration of the transaction only.
        $database->begin_transaction();
        try {
            $database->query("SET FOREIGN_KEY_CHECKS=0");

            $stmt = $database->prepare("UPDATE webuser SET email = ? WHERE email = ?");
            $stmt->bind_param('ss', $new_email, $admin_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("UPDATE admins SET email = ?, full_name = ?, phone = ? WHERE email = ?");
            $stmt->bind_param('ssss', $new_email, $full_name, $phone, $admin_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("UPDATE notifications SET user_email = ? WHERE user_email = ?");
            $stmt->bind_param('ss', $new_email, $admin_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("UPDATE document_comments SET commented_by = ? WHERE commented_by = ?");
            $stmt->bind_param('ss', $new_email, $admin_email);
            $stmt->execute();
            $stmt->close();

            $database->query("SET FOREIGN_KEY_CHECKS=1");
            $database->commit();
        } catch (Exception $e) {
            $database->rollback();
            $database->query("SET FOREIGN_KEY_CHECKS=1");
            respond(['success' => false, 'message' => 'Failed to update email: ' . $e->getMessage()]);
        }

        // Email changed -> force re-login
        session_destroy();
        respond(['success' => true, 'email_changed' => true]);
    } else {
        $stmt = $database->prepare("UPDATE admins SET full_name = ?, phone = ? WHERE email = ?");
        $stmt->bind_param('sss', $full_name, $phone, $admin_email);
        $stmt->execute();
        $stmt->close();

        $stmt = $database->prepare("UPDATE webuser SET updated_at = CURRENT_TIMESTAMP WHERE email = ?");
        $stmt->bind_param('s', $admin_email);
        $stmt->execute();
        $stmt->close();

        $_SESSION['admin_name'] = $full_name;

        respond(['success' => true, 'email_changed' => false, 'updated_at' => date('M d, Y g:i A')]);
    }
}

if ($action === 'change_password') {
    $current = $input['current_password'] ?? '';
    $new     = $input['new_password'] ?? '';

    if (strlen($new) < 8) {
        respond(['success' => false, 'message' => 'New password must be at least 8 characters']);
    }

    $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password'])) {
        respond(['success' => false, 'message' => 'Current password is incorrect']);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $database->prepare("UPDATE webuser SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?");
    $stmt->bind_param('ss', $hash, $admin_email);
    $stmt->execute();
    $stmt->close();

    respond(['success' => true]);
}
if ($action === 'save_email_settings') {
    $host  = trim($input['smtp_host'] ?? '');
    $port  = (int) ($input['smtp_port'] ?? 0);
    $enc   = $input['smtp_encryption'] ?? '';
    $user  = trim($input['smtp_username'] ?? '');
    $from  = trim($input['from_email'] ?? '');
    $name  = trim($input['from_name'] ?? '');
    $pass  = (string) ($input['smtp_password'] ?? '');

    if (!preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/i', $host) || strlen($host) > 255) {
        respond(['success' => false, 'message' => 'Enter a valid SMTP host.']);
    }
    if ($port < 1 || $port > 65535) {
        respond(['success' => false, 'message' => 'Port must be between 1 and 65535.']);
    }
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        respond(['success' => false, 'message' => 'Invalid encryption type.']);
    }
    if ($user === '' || strlen($user) > 255) {
        respond(['success' => false, 'message' => 'SMTP username is required.']);
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Enter a valid "From" email address.']);
    }
    if ($name === '' || mb_strlen($name) > 150) {
        respond(['success' => false, 'message' => '"From" name is required (max 150 characters).']);
    }

    email_bootstrap();

    try {
        $existing = get_email_settings($database);
        if ($pass === '' && !$existing) {
            respond(['success' => false, 'message' => 'SMTP password is required for the first setup.']);
        }

        // Blank password = keep the current encrypted value
        $pass_enc = $pass !== '' ? encrypt_secret($pass) : $existing['smtp_password_enc'];

        $stmt = $database->prepare("
            INSERT INTO email_settings
                (id, smtp_host, smtp_port, smtp_encryption, smtp_username,
                 smtp_password_enc, from_email, from_name, updated_by)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_encryption = VALUES(smtp_encryption),
                smtp_username = VALUES(smtp_username),
                smtp_password_enc = VALUES(smtp_password_enc),
                from_email = VALUES(from_email),
                from_name = VALUES(from_name),
                updated_by = VALUES(updated_by)
        ");
        $stmt->bind_param('sissssss', $host, $port, $enc, $user, $pass_enc, $from, $name, $admin_email);
        $stmt->execute();
        $stmt->close();

        respond(['success' => true]);
    } catch (Throwable $e) {
        error_log('save_email_settings: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Could not save email settings. Check the server log.']);
    }
}

if ($action === 'send_test_email') {
    email_bootstrap();

    try {
        $mail = create_mailer($database);          // uses the SAVED settings
        $mail->addAddress($admin_email);
        $mail->isHTML(true);
        $mail->Subject = 'DMW AMS | SMTP Test';
        $mail->Body    = '<p>This is a test message. Your email configuration is working.</p>';
        $mail->AltBody = 'This is a test message. Your email configuration is working.';
        $mail->send();

        respond(['success' => true, 'sent_to' => $admin_email]);
    } catch (Throwable $e) {
        error_log('send_test_email: ' . $e->getMessage());
        respond(['success' => false, 'message' => 'Test failed: ' . $e->getMessage()]);
    }
}
respond(['success' => false, 'message' => 'Unknown action']);

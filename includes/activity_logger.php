<?php

/**
 * Write one row to activity_logs. Never throws, so logging can't break the main action.
 * $email/$usertype default to the logged-in session user.
 */
function log_activity(
    mysqli $db,
    ?string $email,
    ?string $usertype,
    string $module,
    string $action,
    string $description = '',
    ?int $applicationId = null
): void {
    try {
        $email    = $email    ?? ($_SESSION['user_email'] ?? null);
        $usertype = $usertype ?? ($_SESSION['user_type']  ?? null);
        $ip   = $_SERVER['REMOTE_ADDR'] ?? null; // don't trust X-Forwarded-For
        $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $desc = mb_substr($description, 0, 2000);

        $stmt = $db->prepare(
            "INSERT INTO activity_logs
             (user_email, usertype, module, action, description, application_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssssiss', $email, $usertype, $module, $action, $desc, $applicationId, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('activity_log failed: ' . $e->getMessage());
    }
}

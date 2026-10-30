<?php
// auth.php - Session validation + "Remember Me" auto-login
require_once 'db.php';

/**
 * Force-logout the current user (deactivated account, invalid session, etc.)
 */
function auth_force_logout(string $redirect_message = ''): void
{
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    session_start();
    if ($redirect_message !== '') {
        $_SESSION['login_message'] = $redirect_message;
    }
    header('Location: /login.php');
    exit;
}

if (isset($_SESSION['user_email'])) {

    // ── Active session: re-check is_active on every request ──
    // Ensures a mid-session deactivation (by admin or self) takes effect immediately.
    $stmt = $database->prepare("SELECT is_active FROM webuser WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $_SESSION['user_email']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->num_rows === 1 ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row || (int)$row['is_active'] === 0) {
        auth_force_logout('Your account has been deactivated. Please contact an administrator or request reactivation.');
    }
} else {

    // ── No active session: try remember-token auto-login ──
    if (isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {

        $token = $_COOKIE['remember_token'];

        $stmt = $database->prepare("
            SELECT email, usertype, is_active
            FROM webuser 
            WHERE remember_token = ? 
              AND remember_expires > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ((int)$user['is_active'] === 0) {
                // Deactivated account — clear the token so it can't be reused, don't log them in
                $stmt_clear = $database->prepare("UPDATE webuser SET remember_token = NULL, remember_expires = NULL WHERE email = ?");
                $stmt_clear->bind_param("s", $user['email']);
                $stmt_clear->execute();
                $stmt_clear->close();

                setcookie('remember_token', '', time() - 3600, '/', '', false, true);
            } else {

                // Auto login
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_type']  = $user['usertype'];

                // Get full name for clients
                if ($user['usertype'] === 'client') {
                    $stmt_name = $database->prepare("
                        SELECT CONCAT(first_name, ' ', last_name) AS full_name 
                        FROM clients 
                        WHERE email = ? 
                        LIMIT 1
                    ");
                    $stmt_name->bind_param("s", $user['email']);
                    $stmt_name->execute();
                    $name_result = $stmt_name->get_result();

                    if ($name_result->num_rows === 1) {
                        $_SESSION['full_name'] = $name_result->fetch_assoc()['full_name'];
                    }
                    $stmt_name->close();
                }

                // Extend cookie life (another 30 days)
                setcookie(
                    'remember_token',
                    $token,
                    [
                        'expires'  => time() + (30 * 24 * 60 * 60),
                        'path'     => '/',
                        'secure'   => false,      // Change to true when using HTTPS
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ]
                );
            }
        } else {
            // Invalid or expired token → delete the cookie
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    }
}

<?php
// logout.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

if (isset($_SESSION['user_id'])) {
    // Log logout event before destroying session
    log_activity($pdo, 'LOGOUT', 'تم تسجيل خروج المستخدم: ' . get_username());
}

// Clear all session data
$_SESSION = [];

// Destroy session cookies if any
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Start a new session just to show the flash message
session_start();
$_SESSION['flash_success'] = "تم تسجيل الخروج بنجاح.";
header("Location: login.php");
exit;

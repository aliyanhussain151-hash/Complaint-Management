<?php
session_start();
include '../config/db_connect.php';

// Log the logout action
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $logout_time = date('Y-m-d H:i:s');
    
    // Create user activity log table query (if you want to track logins/logouts)
    $log_query = "INSERT INTO user_activity_logs (user_id, action, timestamp, ip_address) 
                  VALUES ($user_id, 'LOGOUT', '$logout_time', '" . $_SERVER['REMOTE_ADDR'] . "')";
    
    // Execute log query (optional - comment out if table doesn't exist)
    // mysqli_query($conn, $log_query);
    
    // Close database connection
    if (isset($conn)) {
        mysqli_close($conn);
    }
}

// Destroy all session data
session_destroy();

// Clear session array
$_SESSION = [];

// Delete session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Clear any remember-me cookies
setcookie('user_email', '', time() - 3600, '/');
setcookie('user_id', '', time() - 3600, '/');

// Redirect to login page with success message
header('Location: login.php?logout=success');
exit();
?>
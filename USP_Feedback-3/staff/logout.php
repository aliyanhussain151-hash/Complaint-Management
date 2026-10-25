<?php
session_start();
include '../config/db_connect.php';

// Log the logout action
if (isset($_SESSION['user_id'])) {
    $staff_id = $_SESSION['user_id'];
    $logout_time = date('Y-m-d H:i:s');
    
    // Log staff logout (optional)
    $log_query = "INSERT INTO staff_logs (staff_id, action, timestamp) 
                  VALUES ($staff_id, 'LOGOUT', '$logout_time')";
    
    // mysqli_query($conn, $log_query);
    
    if (isset($conn)) {
        mysqli_close($conn);
    }
}

// Destroy session
session_destroy();
$_SESSION = [];

// Delete session cookie
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

// Clear cookies
setcookie('staff_email', '', time() - 3600, '/');

// Redirect to staff login
header('Location: staff_login.php?logout=success');
exit();
?>
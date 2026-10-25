<?php
session_start();
include '../config/db_connect.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        $response['message'] = 'All fields are required';
    } elseif (strlen($name) < 3) {
        $response['message'] = 'Name must be at least 3 characters';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email format';
    } elseif ($password !== $confirm_password) {
        $response['message'] = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $response['message'] = 'Password must be at least 6 characters';
    } else {
        // Check if email exists
        $email_escaped = mysqli_real_escape_string($conn, $email);
        $check_query = "SELECT id FROM users WHERE email = '$email_escaped'";
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $response['message'] = 'Email already exists';
        } else {
            // Add staff member
            $name_escaped = mysqli_real_escape_string($conn, $name);
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_query = "INSERT INTO users (name, email, password, role, status) 
                           VALUES ('$name_escaped', '$email_escaped', '$hashed_password', 'staff', 'active')";
            
            if (mysqli_query($conn, $insert_query)) {
                $staff_id = mysqli_insert_id($conn);
                
                // Log the action
                $log_query = "INSERT INTO admin_logs (admin_id, action, description, timestamp) 
                            VALUES ({$_SESSION['user_id']}, 'ADD_STAFF', 'Added staff member: $name_escaped', NOW())";
                mysqli_query($conn, $log_query);
                
                $response['success'] = true;
                $response['message'] = 'Staff member added successfully!';
            } else {
                $response['message'] = 'Error adding staff: ' . mysqli_error($conn);
            }
        }
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
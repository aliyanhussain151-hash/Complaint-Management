<?php
/**
 * Marks the logged-in student's notifications as read (POST only, Feature 2).
 * Redirects back to the dashboard; never touches other users' rows.
 */
session_start();
include '../config/db_connect.php';
include_once '../includes/common_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mark_notifications_read($conn, (int) $_SESSION['user_id']);
}

header('Location: index.php');
exit();

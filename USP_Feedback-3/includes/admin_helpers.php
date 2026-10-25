<?php
/**
 * Shared helpers for the admin "edit" features.
 * Include AFTER session_start().
 *
 * The CSRF helpers used to live here; they now live in
 * includes/common_helpers.php because the user portal needs them too.
 * This file pulls them in, so every existing `include admin_helpers.php`
 * keeps working unchanged.
 */
require_once __DIR__ . '/common_helpers.php';

/** Writes a row to admin_logs using a prepared statement. */
function admin_log(mysqli $conn, int $admin_id, string $action, string $description): void
{
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, description) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iss', $admin_id, $action, $description);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Validates name / email / optional password for an account edit.
 * Returns an error string, or '' when everything is fine.
 */
function validate_account_fields(mysqli $conn, int $target_id, string $name, string $email, string $password): string
{
    if (strlen($name) < 3) {
        return 'Name must be at least 3 characters';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid email format';
    }
    if ($password !== '' && strlen($password) < 6) {
        return 'Password must be at least 6 characters (or leave it blank to keep the current one)';
    }
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->bind_param('si', $email, $target_id);
    $stmt->execute();
    $stmt->store_result();
    $taken = $stmt->num_rows > 0;
    $stmt->close();
    return $taken ? 'That email is already used by another account' : '';
}

/** True when the reply exists and belongs to the given complaint. */
function reply_exists(mysqli $conn, int $reply_id, int $complaint_id): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM replies WHERE reply_id = ? AND complaint_id = ?");
    $stmt->bind_param('ii', $reply_id, $complaint_id);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

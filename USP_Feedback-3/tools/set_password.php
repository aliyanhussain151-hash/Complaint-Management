<?php
/**
 * Command-line helper to set (or reset) the password of any account.
 *
 *   php tools/set_password.php admin@usp.com "NewStrongPassword"
 *
 * Why: the sample admin@usp.com / staff@usp.com rows in the SQL dump contain a
 * placeholder hash, so nobody can log in with them until a real password is set.
 * Runs from the terminal only - it refuses to run through the web server.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/db_connect.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/set_password.php <email> <new-password>\n");
    exit(1);
}

[$script, $email, $password] = $argv;

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ?, status = 'active', updated_at = NOW() WHERE email = ?");
$stmt->bind_param('ss', $hash, $email);
$stmt->execute();

if ($stmt->affected_rows < 1) {
    fwrite(STDERR, "No account found with email: $email\n");
    exit(1);
}

echo "Password updated for $email (account set to active).\n";

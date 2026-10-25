<?php
// Database connection configuration.
//
// Works out of the box on the two most common setups:
//   - XAMPP / WAMP on Windows .... root with NO password
//   - Homebrew MySQL / MariaDB ... root / root
//
// You can override any value with an environment variable
// (DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME) or just edit the
// defaults below.

define('DB_SERVER', getenv('DB_SERVER') ?: 'localhost');
define('DB_USER',   getenv('DB_USER')   ?: 'root');
define('DB_NAME',   getenv('DB_NAME')   ?: 'USP');

// Passwords to try, in order. A value in DB_PASSWORD (env) wins; the
// rest are common defaults so the app runs without any editing.
$db_passwords = [];
if (getenv('DB_PASSWORD') !== false) {
    $db_passwords[] = getenv('DB_PASSWORD');
}
foreach (['', 'root', 'password'] as $guess) {
    if (!in_array($guess, $db_passwords, true)) {
        $db_passwords[] = $guess;
    }
}

$conn = null;
$last_error = '';
foreach ($db_passwords as $pw) {
    try {
        $try = @new mysqli(DB_SERVER, DB_USER, $pw, DB_NAME);
        if ($try && !$try->connect_error) {
            $conn = $try;
            break;
        }
        $last_error = $try ? $try->connect_error : 'unknown error';
    } catch (Throwable $e) {
        $last_error = $e->getMessage();
    }
}

if (!$conn) {
    die(
        "Database connection failed: " . htmlspecialchars($last_error) .
        "<br>Please make sure MySQL is running and that 'config/db_connect.php' " .
        "has the correct username/password."
    );
}

$conn->set_charset('utf8');
?>

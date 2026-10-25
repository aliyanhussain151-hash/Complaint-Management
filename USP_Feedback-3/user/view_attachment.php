<?php
/**
 * Authenticated download endpoint for complaint evidence files (Feature 1).
 *
 * Access rules (enforced server-side on every request):
 *  - requires a logged-in session,
 *  - complaint owner,
 *  - admin,
 *  - staff assigned to that complaint.
 * Everyone else gets 403 (or 404 when the attachment id does not exist).
 *
 * Optional "&download=1" forces Content-Disposition: attachment (save
 * instead of inline render). The permission checks above are identical.
 *
 * Files live OUTSIDE the web root (see includes/common_helpers.php,
 * evidence_storage_dir()), so nothing can ever serve them directly.
 */
session_start();
include_once '../includes/common_helpers.php';
include '../config/db_connect.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authenticated.');
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$att_id = (int) ($_GET['id'] ?? 0);
$download = (($_GET['download'] ?? '') === '1');

if ($att_id <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT a.*, c.user_id AS complaint_owner, c.assigned_staff
     FROM complaint_attachments a
     JOIN complaints c ON c.complaint_id = a.complaint_id
     WHERE a.attachment_id = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $att_id);
mysqli_stmt_execute($stmt);
$att = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$att) {
    http_response_code(404);
    exit('Attachment not found.');
}

// --- Ownership / role check ---
$is_owner = ((int) $att['complaint_owner'] === $user_id);
$is_admin = ($role === 'admin');
$is_assigned_staff = ($role === 'staff' && (int) $att['assigned_staff'] === $user_id);

if (!$is_owner && !$is_admin && !$is_assigned_staff) {
    http_response_code(403);
    exit('You do not have permission to view this file.');
}

// --- Resolve path and verify it is inside the evidence storage dir ---
$storage = evidence_storage_dir();
$full_path = $storage . DIRECTORY_SEPARATOR . $att['stored_name'];

$real_storage = realpath($storage);
$real_file = realpath($full_path);

if ($real_storage === false || $real_file === false
    || strpos($real_file, $real_storage . DIRECTORY_SEPARATOR) !== 0
    || !is_file($real_file)) {
    http_response_code(404);
    exit('Attachment file is missing from storage.');
}

// --- Stream the file with safe headers ---
header('Content-Type: ' . $att['mime_type']); // declared type validated at upload
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($real_file));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($att['original_name']) . '"');
header('Content-Transfer-Encoding: binary');

// images/PDFs render inline; never execute as HTML/JS
readfile($real_file);
exit();

<?php
/**
 * Helpers shared by more than one portal (user / staff / admin).
 *
 * Include AFTER session_start(). Safe to include several times from
 * different files in the same request (require_once).
 *
 * Contents:
 *   - CSRF token helpers (moved out of admin_helpers.php so the user portal
 *     can use them too; admin_helpers.php still exposes the same names)
 *   - Complaint evidence: limits, validation, storage, retrieval rules
 *   - Complaint status history (timeline)
 *   - In-app notifications
 */

/* ==================================================================== */
/* CSRF                                                                  */
/* ==================================================================== */

/** Returns the CSRF token for this session (creates it on first use). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden <input> to drop inside any POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** True when the submitted token matches the session token. */
function csrf_valid(): bool
{
    return !empty($_POST['csrf_token'])
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

/* ==================================================================== */
/* Evidence configuration                                                */
/* ==================================================================== */

if (!defined('EVIDENCE_MAX_FILE_BYTES')) {
    define('EVIDENCE_MAX_FILE_BYTES', 5 * 1024 * 1024);      // 5 MB per file
    define('EVIDENCE_MAX_FILES', 5);                          // files per complaint
    define('EVIDENCE_MAX_TOTAL_BYTES', 20 * 1024 * 1024);     // 20 MB per complaint
    define('EVIDENCE_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);
    // image/jpg is a common (non-standard) alias browsers still send.
    define('EVIDENCE_ALLOWED_MIME_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf']);
    define('EVIDENCE_DANGEROUS_EXTENSIONS', [
        'php', 'php2', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
        'pl', 'py', 'cgi', 'sh', 'bash', 'bat', 'cmd', 'com', 'exe', 'dll', 'msi', 'scr',
        'js', 'jsp', 'asp', 'aspx', 'cer', 'asa', 'asax', 'html', 'htm', 'shtml', 'svg',
        'xml', 'xsl', 'xslt', 'swf', 'jar', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh',
        'htaccess', 'htpasswd', 'ini', 'conf', 'config', 'sql', 'bak', 'log', 'tar', 'gz', 'zip',
    ]);
}

/**
 * Directory where evidence files live.
 *
 * Deliberately ONE LEVEL ABOVE the project folder, so it is outside the
 * web document root when the site is served with `php -S` from the project
 * folder (the documented way to run this app). Override with the
 * EVIDENCE_STORAGE_DIR environment variable.
 */
function evidence_storage_dir(): string
{
    $configured = getenv('EVIDENCE_STORAGE_DIR');
    if ($configured !== false && trim($configured) !== '') {
        return rtrim($configured, "/\\");
    }
    // __DIR__ = <project>/includes  ->  <project>  ->  <project>/../storage/...
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'storage' . DIRECTORY_SEPARATOR . 'complaint_evidence';
}

/**
 * Creates the storage directory (and its deny-all .htaccess) on first use.
 * Returns the path, or null when it cannot be created.
 */
function evidence_ensure_storage_dir(): ?string
{
    $dir = evidence_storage_dir();
    if (is_dir($dir)) {
        return $dir;
    }
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('[evidence] could not create storage directory: ' . $dir);
        return null;
    }
    // Defence in depth for Apache deployments: never serve this directory.
    $htaccess = dirname($dir) . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess,
            "# Evidence storage - never served directly.\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, '');
    }
    return $dir;
}

/** True when a storage path sits under the given directory. */
function evidence_path_within(string $path, string $root): bool
{
    $p = realpath($path);
    $r = realpath($root);
    if ($p === false || $r === false) {
        return false;
    }
    return $p === $r || strpos($p, $r . DIRECTORY_SEPARATOR) === 0;
}

/* ==================================================================== */
/* Evidence validation                                                   */
/* ==================================================================== */

/** Lower-case extension of a filename, or '' when it has none. */
function evidence_extension_of(string $filename): string
{
    $pos = strrpos($filename, '.');
    if ($pos === false || $pos === strlen($filename) - 1) {
        return '';
    }
    return strtolower(substr($filename, $pos + 1));
}

/**
 * Rejects path traversal, null bytes, control characters, bare paths and
 * double extensions such as "shell.php.jpg" or "report.pdf.exe".
 *
 * Returns a human-readable error, or null when the name is acceptable.
 */
function evidence_filename_is_safe(string $filename): ?string
{
    if ($filename === '') {
        return 'A file name is missing.';
    }
    if (strlen($filename) > 255) {
        return 'File name is too long (maximum 255 characters).';
    }
    if (strpos($filename, "\0") !== false) {
        return 'File name contains an illegal null byte.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $filename)) {
        return 'File name contains illegal control characters.';
    }
    if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false || strpos($filename, ':') !== false) {
        return 'File name must not contain a path.';
    }
    if (trim($filename) === '.' || trim($filename) === '..' || strpos($filename, '..') !== false) {
        return 'File name must not contain "..".';
    }

    $parts = explode('.', strtolower($filename));
    if (count($parts) > 1) {
        // Every segment except the final extension must not look executable,
        // which is what blocks double extensions like "photo.php.jpg".
        foreach (array_slice($parts, 0, -1) as $seg) {
            if ($seg === '' || in_array($seg, EVIDENCE_DANGEROUS_EXTENSIONS, true)) {
                return 'File has a disallowed extension combination.';
            }
        }
    }
    if (!in_array(evidence_extension_of($filename), EVIDENCE_ALLOWED_EXTENSIONS, true)) {
        return 'Only JPG, JPEG, PNG and PDF files are allowed.';
    }
    return null;
}

/** True when the file starts with the signature expected for its extension. */
function evidence_signature_matches(string $path, string $ext): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $head = (string) fread($fh, 1024);
    fclose($fh);
    if ($head === '') {
        return false;
    }
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return substr($head, 0, 3) === "\xFF\xD8\xFF";
        case 'png':
            return substr($head, 0, 8) === "\x89PNG\r\n\x1a\n";
        case 'pdf':
            // The PDF header is valid anywhere in the first 1024 bytes.
            return strpos(substr($head, 0, 1024), '%PDF-') !== false;
    }
    return false;
}

/**
 * Server-side validation of one entry of $_FILES. Independent of anything
 * the browser claimed.
 *
 * @return array{ok:bool,error:string,size:int,ext:string,mime:string}
 */
function evidence_validate(array $file): array
{
    $fail = static function (string $msg): array {
        return ['ok' => false, 'error' => $msg, 'size' => 0, 'ext' => '', 'mime' => ''];
    };

    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return $fail('No file was selected.');
    }
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form allows.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded - please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (missing temp folder).',
        UPLOAD_ERR_CANT_WRITE => 'Server error: the file could not be saved.',
        UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload.',
    ];
    if ($err !== UPLOAD_ERR_OK) {
        return $fail($messages[$err] ?? 'Upload failed (error code ' . $err . ').');
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return $fail('Invalid upload.');
    }

    $size = isset($file['size']) ? (int) $file['size'] : (int) filesize($tmp);
    if ($size === 0 || @filesize($tmp) === 0) {
        return $fail('The file is empty.');
    }
    if ($size > EVIDENCE_MAX_FILE_BYTES) {
        return $fail(sprintf('"%s" is larger than the %d MB limit.',
            $file['name'] ?? 'file', (int) (EVIDENCE_MAX_FILE_BYTES / 1048576)));
    }

    $name = (string) ($file['name'] ?? '');
    $nameError = evidence_filename_is_safe($name);
    if ($nameError !== null) {
        return $fail($nameError);
    }

    $ext = evidence_extension_of($name);

    // Declared MIME type (what the client claimed).
    $declared = strtolower(trim((string) ($file['type'] ?? '')));
    if ($declared !== '' && !in_array($declared, EVIDENCE_ALLOWED_MIME_TYPES, true)) {
        return $fail('File type "' . htmlspecialchars($declared, ENT_QUOTES) . '" is not allowed.');
    }

    // Actual content type (fileinfo reads the magic bytes of the file).
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $detected = $finfo ? strtolower((string) finfo_file($finfo, $tmp)) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if ($detected === '' || !in_array($detected, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        return $fail('The file content is not a JPG, PNG or PDF image.');
    }

    // Cross-check extension <-> detected content <-> declared content.
    $expected = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    if (($expected[$ext] ?? '') !== $detected) {
        return $fail('The file extension does not match the actual file content.');
    }
    if ($declared !== '' && $declared !== 'image/jpg' && $declared !== $detected) {
        return $fail('The declared file type does not match the actual file content.');
    }
    if (!evidence_signature_matches($tmp, $ext)) {
        return $fail('The file signature is not valid for a .' . $ext . ' file.');
    }

    return ['ok' => true, 'error' => '', 'size' => $size, 'ext' => $ext, 'mime' => $detected];
}

/** Strips anything unsafe from a client filename; metadata only, never a path. */
function evidence_sanitize_original_name(string $filename): string
{
    $filename = str_replace(["\0", '\\', '/'], '', $filename);
    $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? '';
    $filename = preg_replace('/[^A-Za-z0-9 ._\-()\[\]]+/', '_', $filename) ?? '';
    $filename = trim($filename);
    $filename = trim($filename, '. ');
    if ($filename === '' || $filename === '..') {
        $filename = 'evidence';
    }
    if (strlen($filename) > 255) {
        $ext = evidence_extension_of($filename);
        $keep = 255 - strlen($ext) - 1;
        $filename = substr($filename, 0, $keep) . ($ext !== '' ? '.' . $ext : '');
    }
    return $filename;
}

/** Random, server-generated file name. Never derived from user input. */
function evidence_random_name(string $ext): string
{
    $canonical = $ext === 'jpeg' ? 'jpg' : $ext;
    if (!in_array($canonical, ['jpg', 'png', 'pdf'], true)) {
        $canonical = 'bin';
    }
    return bin2hex(random_bytes(16)) . '.' . $canonical;
}

/**
 * Moves a validated upload into storage under its random name.
 * @return array{ok:bool,stored_name:string,error:string}
 */
function evidence_store_file(array $file, string $storedName): array
{
    $dir = evidence_ensure_storage_dir();
    if ($dir === null) {
        return ['ok' => false, 'stored_name' => '', 'error' => 'Evidence storage is not available.'];
    }
    $target = $dir . DIRECTORY_SEPARATOR . $storedName;
    if (file_exists($target)) {
        return ['ok' => false, 'stored_name' => '', 'error' => 'Server error: duplicate file name.'];
    }
    $moved = is_uploaded_file($file['tmp_name'])
        ? @move_uploaded_file($file['tmp_name'], $target)
        : @copy($file['tmp_name'], $target);
    if (!$moved) {
        error_log('[evidence] failed to store ' . $storedName);
        return ['ok' => false, 'stored_name' => '', 'error' => 'Could not save the file on the server.'];
    }
    @chmod($target, 0644);
    return ['ok' => true, 'stored_name' => $storedName, 'error' => ''];
}

/** Deletes a stored file; used for rollback. Never throws. */
function evidence_delete_file(string $storedName): void
{
    if ($storedName === '' || strpos($storedName, '/') !== false || strpos($storedName, '\\') !== false
        || strpos($storedName, '..') !== false) {
        return;
    }
    $path = evidence_storage_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Validates and stores additional evidence on an EXISTING complaint
 * (used by user/complaint_status.php).
 *
 * Everything happens in one transaction: files are validated first, then
 * moved and registered; any failure rolls back the rows AND deletes the
 * files already moved, so no orphan files or rows can survive.
 *
 * Enforces the same per-complaint limits as submission (5 files, 20 MB).
 *
 * @param array|null $files one entry of $_FILES (name/extra_files[]), or null
 * @return array{ok:bool,error:string,files:array<int,array{stored:string,orig:string}>}
 */
function evidence_store_files(mysqli $conn, int $userId, int $complaintId, ?array $files): array
{
    $fail = static function (string $msg): array {
        return ['ok' => false, 'error' => $msg, 'files' => []];
    };

    if (!$files || !isset($files['name']) || !is_array($files['name']) || count($files['name']) === 0) {
        return $fail('No file was selected.');
    }
    if (count($files['name']) > EVIDENCE_MAX_FILES) {
        return $fail(sprintf('You can attach at most %d files per complaint.', EVIDENCE_MAX_FILES));
    }

    // Rebuild a $_FILES-like entry per file, then validate every one BEFORE
    // touching the database or storage.
    $validated = [];
    $total = 0;
    $errors = [];
    foreach ($files['name'] as $i => $name) {
        $entry = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        $result = evidence_validate($entry);
        if (!$result['ok']) {
            $errors[] = '"' . evidence_sanitize_original_name((string) $name) . '": ' . $result['error'];
            continue;
        }
        $total += $result['size'];
        if ($total > EVIDENCE_MAX_TOTAL_BYTES) {
            return $fail(sprintf('Evidence exceeds the %d MB limit per complaint.',
                (int) (EVIDENCE_MAX_TOTAL_BYTES / 1048576)));
        }
        $validated[] = ['file' => $entry, 'meta' => $result];
    }
    if (!empty($errors)) {
        return $fail(implode(' ', $errors));
    }

    // Existing usage for this complaint (file count limit).
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM complaint_attachments WHERE complaint_id = ?");
    if (!$stmt) {
        return $fail('Could not check the current file count: ' . $conn->error);
    }
    $stmt->bind_param('i', $complaintId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $existing = (int) ($row['n'] ?? 0);
    if ($existing + count($validated) > EVIDENCE_MAX_FILES) {
        return $fail(sprintf('A complaint can hold at most %d files; you already have %d.',
            EVIDENCE_MAX_FILES, $existing));
    }

    $stored = [];
    $written = [];
    try {
        $conn->begin_transaction();

        foreach ($validated as $item) {
            $storedName = evidence_random_name($item['meta']['ext']);
            $store = evidence_store_file($item['file'], $storedName);
            if (!$store['ok']) {
                throw new RuntimeException($store['error']);
            }
            $stored[] = $storedName;

            $orig = evidence_sanitize_original_name($item['file']['name']);
            $size = $item['meta']['size'];
            $mime = $item['meta']['mime'];
            $stmt = $conn->prepare(
                "INSERT INTO complaint_attachments
                    (complaint_id, stored_name, original_name, mime_type, file_size, uploaded_by, uploaded_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                throw new RuntimeException('Could not register the attachment: ' . $conn->error);
            }
            $stmt->bind_param('isssii', $complaintId, $storedName, $orig, $mime, $size, $userId);
            if (!$stmt->execute()) {
                $e = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Could not register the attachment: ' . $e);
            }
            $stmt->close();
            $written[] = ['stored' => $storedName, 'orig' => $orig];
        }

        $conn->commit();
        return ['ok' => true, 'error' => '', 'files' => $written];
    } catch (Throwable $e) {
        @$conn->rollback();
        foreach ($stored as $name) {
            evidence_delete_file($name);
        }
        error_log('[evidence_store_files] rolled back: ' . $e->getMessage());
        return $fail('Could not save the evidence files (no changes were kept). Please try again.');
    }
}

/**
 * True when the session may view a complaint's evidence.
 * Owner, any admin, or the staff member assigned to that complaint.
 */
function can_view_evidence(mysqli $conn, int $complaintId, int $userId, string $role): bool
{
    if ($role === 'admin') {
        return true;
    }
    if (!in_array($role, ['user', 'staff'], true)) {
        return false;
    }
    return (bool) evidence_db_call(static function () use ($conn, $complaintId, $userId, $role): bool {
        $stmt = $conn->prepare("SELECT user_id, assigned_staff FROM complaints WHERE complaint_id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $complaintId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        if ($role === 'user') {
            return (int) $row['user_id'] === $userId;
        }
        return $row['assigned_staff'] !== null && (int) $row['assigned_staff'] === $userId;
    }, false, 'evidence');
}

/* ==================================================================== */
/* Complaint status history (timeline)                                   */
/* ==================================================================== */

/**
 * Appends one timeline event. Returns false (and logs) on failure so the
 * caller can roll back the change this event describes.
 *
 * Event vocabulary actually used by this system:
 *   submitted | assigned | reassigned | unassigned | status_changed | closed
 */
function record_status_event(
    mysqli $conn,
    int $complaintId,
    string $eventType,
    ?string $status,
    ?string $message,
    ?int $actorId,
    ?string $actorRole
): bool {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO complaint_status_history
                (complaint_id, event_type, status, message, actor_id, actor_role, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            error_log('[timeline] prepare failed: ' . $conn->error);
            return false;
        }
        $status = $status === null ? null : substr($status, 0, 20);
        $message = $message === null ? null : mb_substr($message, 0, 500);
        $actorRole = $actorRole === null ? null : substr($actorRole, 0, 20);
        $stmt->bind_param('isssis', $complaintId, $eventType, $status, $message, $actorId, $actorRole);
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('[timeline] insert failed: ' . $stmt->error);
        }
        $stmt->close();
        return (bool) $ok;
    } catch (Throwable $e) {
        error_log('[timeline] ' . $e->getMessage());
        return false;
    }
}

/**
 * Reads a complaint's timeline in chronological order.
 * Only ever called after ownership/permission has been verified.
 */
function get_complaint_timeline(mysqli $conn, int $complaintId): array
{
    try {
        $stmt = $conn->prepare(
            "SELECT h.history_id, h.event_type, h.status, h.message, h.actor_id, h.actor_role,
                    h.created_at, u.name AS actor_name
             FROM complaint_status_history h
             LEFT JOIN users u ON u.id = h.actor_id
             WHERE h.complaint_id = ?
             ORDER BY h.created_at ASC, h.history_id ASC"
        );
        if (!$stmt) {
            error_log('[timeline] prepare failed: ' . $conn->error);
            return [];
        }
        $stmt->bind_param('i', $complaintId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        error_log('[timeline] ' . $e->getMessage());
        return [];
    }
}

/* ==================================================================== */
/* Notifications (in-app)                                                */
/* ==================================================================== */

/**
 * Stores an in-app notification. Uses INSERT IGNORE against
 * uq_notification_dedupe so retries and double-clicks cannot produce a
 * second copy. Returns false (and logs) when the insert genuinely fails.
 */
function notify_user(mysqli $conn, int $userId, ?int $complaintId, string $type, string $title, string $message): bool
{
    try {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO notifications (user_id, complaint_id, type, title, message, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())"
        );
        if (!$stmt) {
            error_log('[notify] prepare failed: ' . $conn->error);
            return false;
        }
        $complaintId = $complaintId === null ? null : (int) $complaintId;
        $stmt->bind_param('iisss', $userId, $complaintId, $type, $title, $message);
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('[notify] insert failed: ' . $stmt->error);
        }
        $stmt->close();
        return (bool) $ok;
    } catch (Throwable $e) {
        error_log('[notify] ' . $e->getMessage());
        return false;
    }
}

/** The closure message shown to the complainant. */
function closure_notification_message(int $complaintId, ?string $solution): string
{
    $solution = trim((string) $solution);
    if ($solution === '') {
        $solution = 'no resolution details were recorded';
    }
    return "Your complaint #$complaintId has been closed. Resolution: $solution";
}

/** Runs $fn, converting any driver exception into $fallback plus a log line. */
function evidence_db_call(callable $fn, $fallback, string $label)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[' . $label . '] ' . $e->getMessage());
        return $fallback;
    }
}

/**
 * Latest notifications for one user, newest first.
 * Only called after the session's user id has been established.
 */
function get_user_notifications(mysqli $conn, int $userId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    return evidence_db_call(static function () use ($conn, $userId, $limit): array {
        $stmt = $conn->prepare(
            "SELECT n.notification_id, n.complaint_id, n.type, n.title, n.message, n.is_read, n.created_at
             FROM notifications n
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC, n.notification_id DESC
             LIMIT $limit"
        );
        if (!$stmt) {
            error_log('[notify] prepare failed: ' . $conn->error);
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }, [], 'notify');
}

/** Unread notification count for a user (drives the badge). */
function count_unread_notifications(mysqli $conn, int $userId): int
{
    return (int) evidence_db_call(static function () use ($conn, $userId): int {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0);
    }, 0, 'notify');
}

/** Marks notifications read. Returns the number of rows updated. */
function mark_notifications_read(mysqli $conn, int $userId, ?int $notificationId = null): int
{
    return (int) evidence_db_call(static function () use ($conn, $userId, $notificationId): int {
        if ($notificationId === null) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND notification_id = ?");
        }
        if (!$stmt) {
            return 0;
        }
        if ($notificationId === null) {
            $stmt->bind_param('i', $userId);
        } else {
            $stmt->bind_param('ii', $userId, $notificationId);
        }
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return max(0, $n);
    }, 0, 'notify');
}

<?php
/**
 * Smoke / regression test runner for the USP Complaint Management System.
 *
 * Three layers, run in order:
 *   1. Syntax     - php -l on every PHP file we created or modified.
 *   2. Migration  - tools/migrate.php must be idempotent against the live DB.
 *   3. HTTP       - real requests against the running app, asserting status
 *                   codes for happy paths, access control and evidence rules.
 *
 * Prerequisites:
 *   - MySQL running and the `usp` database migrated (tools/migrate.php).
 *   - The app served at BASE_URL, e.g.:  php -S localhost:8000 -t USP_Feedback-3
 *   - Test accounts exist:
 *       user@usp.com/user1234, admin@usp.com/admin123, staff@usp.com/staff123
 *
 * Usage (from the USP_Feedback-3 folder that contains index.php):
 *   php tools\run_tests.php
 *   php tools\run_tests.php --base-url=http://localhost:8000
 *
 * Exit code 0 = all passed, 1 = at least one failure. Never modifies
 * complaint data: it only reads pages, and cleans up anything it creates.
 */

declare(strict_types=1);

$PROJECT_ROOT = dirname(__DIR__);
$PHP = PHP_BINARY;

/* ------------------------------------------------------------------ */
/* Config                                                              */
/* ------------------------------------------------------------------ */

$opts = getopt('', ['base-url::', 'skip-http', 'skip-migration', 'skip-syntax']);
$BASE = isset($opts['base-url']) && $opts['base-url'] !== false
    ? rtrim((string) $opts['base-url'], '/')
    : 'http://localhost:8000';
$SKIP_HTTP     = array_key_exists('skip-http', $opts);
$SKIP_MIGRATE  = array_key_exists('skip-migration', $opts);
$SKIP_SYNTAX   = array_key_exists('skip-syntax', $opts);

$pass = 0;
$fail = 0;
$failures = [];

function out(string $s): void { echo $s . PHP_EOL; }

function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        out("  PASS  $label");
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? " - $detail" : '');
        out("  FAIL  $label" . ($detail !== '' ? "  ($detail)" : ''));
    }
}

/* Thin curl wrapper so assertions read cleanly.
 *
 * NOTE: on Windows PHP's escapeshellarg() drops '%' from the string, which
 * breaks curl's -w '%{http_code}'. We double-quote format strings by hand. */
function q(string $s): string
{
    return '"' . str_replace('"', '\\"', $s) . '"';
}

function http_req(string $method, string $url, array $opts = []): array
{
    global $CURL;
    $args = ['-s', '-X', $method, $url, '-o', 'NUL', '-w', '%{http_code}'];
    foreach ($opts as $k => $v) {
        if ($k === 'cookie')   { $args[] = '-b'; $args[] = $v; continue; }
        if ($k === 'data')     { $args[] = '--data-urlencode'; $args[] = $v; continue; }
        if ($k === 'headers')  { foreach ((array) $v as $h) { $args[] = '-H'; $args[] = $h; } continue; }
        if ($k === 'form')     { foreach ((array) $v as $f) { $args[] = '-F'; $args[] = $f; } continue; }
    }
    $parts = [];
    foreach ($args as $a) {
        $parts[] = ($a === '%{http_code}') ? q($a) : escapeshellarg($a);
    }
    $code = trim((string) shell_exec(escapeshellarg($CURL) . ' ' . implode(' ', $parts)) ?? '');
    return ['code' => (int) $code];
}

function http_status(string $method, string $url, array $opts = []): int
{
    return http_req($method, $url, $opts)['code'];
}

/* Like http_req(), but also returns the response body so content
 * (e.g. the evidence section) can be asserted, not just status codes. */
function http_body(string $method, string $url, array $opts = []): array
{
    global $CURL;
    $tmp = tempnam(sys_get_temp_dir(), 'usp_body');
    $args = ['-s', '-X', $method, $url, '-o', $tmp, '-w', '%{http_code}'];
    foreach ($opts as $k => $v) {
        if ($k === 'cookie') { $args[] = '-b'; $args[] = $v; continue; }
    }
    $parts = [];
    foreach ($args as $a) {
        $parts[] = ($a === '%{http_code}') ? q($a) : escapeshellarg($a);
    }
    $code = trim((string) shell_exec(escapeshellarg($CURL) . ' ' . implode(' ', $parts)) ?? '');
    $body = ($tmp && is_file($tmp)) ? (string) file_get_contents($tmp) : '';
    if ($tmp) { @unlink($tmp); }
    return ['code' => (int) $code, 'body' => $body];
}

/* Response headers of a HEAD request, lower-cased and joined. */
function http_headers(string $url, string $cookie = ''): string
{
    global $CURL;
    $args = ['-s', '-I'];
    if ($cookie !== '') { $args[] = '-b'; $args[] = $cookie; }
    $args[] = $url;
    $parts = [];
    foreach ($args as $a) { $parts[] = escapeshellarg($a); }
    $raw = shell_exec(escapeshellarg($CURL) . ' ' . implode(' ', $parts)) ?? '';
    return strtolower((string) $raw);
}

/* ------------------------------------------------------------------ */
/* Evidence fixtures (Feature 1 access-control tests).                */
/* They are created before the assertions and removed again on        */
/* shutdown, so the suite still leaves complaint data untouched.       */
/* ------------------------------------------------------------------ */

function db_connect_for_tests(): ?mysqli
{
    $server = getenv('DB_SERVER') ?: 'localhost';
    $user   = getenv('DB_USER')   ?: 'root';
    $name   = getenv('DB_NAME')   ?: 'USP';
    $guesses = [];
    if (getenv('DB_PASSWORD') !== false) { $guesses[] = getenv('DB_PASSWORD'); }
    foreach (['', 'root', 'password'] as $pw) {
        if (!in_array($pw, $guesses, true)) { $guesses[] = $pw; }
    }
    foreach ($guesses as $pw) {
        try {
            $c = @new mysqli($server, $user, $pw, $name);
            if ($c && !$c->connect_error) { return $c; }
        } catch (Throwable $e) {
            // try the next guess
        }
    }
    return null;
}

function db_scalar(mysqli $conn, string $sql): ?string
{
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_row() : null;
    return $row ? (string) $row[0] : null;
}

function evidence_storage_dir_for_tests(): string
{
    $configured = getenv('EVIDENCE_STORAGE_DIR');
    if ($configured !== false && $configured !== '') { return $configured; }
    return dirname($GLOBALS['PROJECT_ROOT']) . DIRECTORY_SEPARATOR . 'storage'
        . DIRECTORY_SEPARATOR . 'complaint_evidence';
}

function evidence_fixtures_create(mysqli $conn): array
{
    global $PROJECT_ROOT;
    $fx = [
        'complaint'           => 0,  // NOT assigned to the test staff account
        'foreign_attachment'  => 0,  // its attachment (file intentionally absent)
        'assigned_complaint'  => 0,  // assigned to the test staff, has evidence
        'assigned_attachment' => 0,  // existing attachment with a real file
        'extra_attachment'    => 0,  // 2nd real file -> multi-attachment check
        'extra_file'          => '',
        'clean_complaint'     => 0,  // assigned, but no evidence at all
    ];

    $staffId  = db_scalar($conn, "SELECT id FROM users WHERE email = 'staff@usp.com' LIMIT 1");
    $ownerId  = db_scalar($conn, "SELECT id FROM users WHERE email = 'user@usp.com'  LIMIT 1");
    $adminId  = db_scalar($conn, "SELECT id FROM users WHERE email = 'admin@usp.com' LIMIT 1");
    if (!$staffId || !$ownerId || !$adminId) {
        return $fx; // test accounts missing; negative tests will be skipped
    }

    // 1) Complaint owned by the ADMIN user, assigned to nobody, with an
    //    attachment whose file intentionally does not exist. Drives the
    //    "staff/user must be denied" and "broken file reference" tests.
    if ($conn->query("INSERT INTO complaints (user_id, subject, category, message, status, assigned_staff)
                      VALUES ($adminId, 'ZZ fixture (run_tests)', 'Other',
                              'Temporary fixture row - removed by run_tests.php on exit.', 'Pending', NULL)")) {
        $fx['complaint'] = (int) $conn->insert_id;
        if ($conn->query("INSERT INTO complaint_attachments
                            (complaint_id, stored_name, original_name, mime_type, file_size, uploaded_by)
                          VALUES ({$fx['complaint']}, 'zz_fixture_missing_file.png',
                                  'fixture.png', 'image/png', 123, $adminId)")) {
            $fx['foreign_attachment'] = (int) $conn->insert_id;
        }
    }

    // 2) Existing attachment on a complaint assigned to the test staff
    //    (positive path: staff/admin/owner all open the same real file).
    $assignedAtt = db_scalar($conn,
        "SELECT a.attachment_id FROM complaint_attachments a
         JOIN complaints c ON c.complaint_id = a.complaint_id
         WHERE c.assigned_staff = $staffId ORDER BY a.attachment_id ASC LIMIT 1");
    if ($assignedAtt !== null) {
        $fx['assigned_attachment'] = (int) $assignedAtt;
        $fx['assigned_complaint'] = (int) db_scalar($conn,
            "SELECT complaint_id FROM complaint_attachments WHERE attachment_id = " . (int) $assignedAtt);
    }

    // 3) A second REAL file on that same complaint, so the multi-file list
    //    is exercised end to end. Bytes are copied from an existing file.
    if ($fx['assigned_complaint'] > 0) {
        $storage = evidence_storage_dir_for_tests();
        $srcName = db_scalar($conn,
            "SELECT stored_name FROM complaint_attachments WHERE attachment_id = {$fx['assigned_attachment']}");
        $src = $storage . DIRECTORY_SEPARATOR . $srcName;
        $stored = md5('usp_run_tests_multi_' . uniqid((string) mt_rand(), true)) . '.png';
        $dst = $storage . DIRECTORY_SEPARATOR . $stored;
        if ($srcName !== null && is_file($src) && @copy($src, $dst)) {
            $size = (int) filesize($dst);
            if ($conn->query("INSERT INTO complaint_attachments
                                (complaint_id, stored_name, original_name, mime_type, file_size, uploaded_by)
                              VALUES ({$fx['assigned_complaint']}, '$stored',
                                      'second evidence.png', 'image/png', $size, $ownerId)")) {
                $fx['extra_attachment'] = (int) $conn->insert_id;
                $fx['extra_file'] = $dst;
            } else {
                @unlink($dst);
            }
        }
    }

    // 4) A complaint assigned to the test staff with NO evidence (empty state).
    $cleanId = db_scalar($conn,
        "SELECT complaint_id FROM complaints
         WHERE assigned_staff = $staffId
         AND NOT EXISTS (SELECT 1 FROM complaint_attachments a WHERE a.complaint_id = complaints.complaint_id)
         ORDER BY complaint_id ASC LIMIT 1");
    if ($cleanId !== null) {
        $fx['clean_complaint'] = (int) $cleanId;
    }

    return $fx;
}

function evidence_fixtures_cleanup(array $fx): void
{
    $conn = db_connect_for_tests();
    if (!$conn) { return; }
    if (!empty($fx['extra_attachment'])) {
        $conn->query('DELETE FROM complaint_attachments WHERE attachment_id = ' . (int) $fx['extra_attachment']);
    }
    if (!empty($fx['extra_file']) && is_file($fx['extra_file'])) {
        @unlink($fx['extra_file']);
    }
    if (!empty($fx['foreign_attachment'])) {
        $conn->query('DELETE FROM complaint_attachments WHERE attachment_id = ' . (int) $fx['foreign_attachment']);
    }
    if (!empty($fx['complaint'])) {
        // cascades any remaining attachment rows of this complaint
        $conn->query('DELETE FROM complaints WHERE complaint_id = ' . (int) $fx['complaint']);
    }
    $conn->close();
}

/* ------------------------------------------------------------------ */
/* 1. Syntax                                                           */
/* ------------------------------------------------------------------ */

out(PHP_EOL . '== 1. PHP syntax ==');

$files = [
    'includes/common_helpers.php',
    'includes/admin_helpers.php',
    'user/submit_complaint.php',
    'user/complaint_status.php',
    'user/view_attachment.php',
    'user/mark_notifications_read.php',
    'user/index.php',
    'admin/manage_complaints.php',
    'admin/assign_staff.php',
    'admin/get_complaint_detail.php',
    'staff/resolve_complaint.php',
    'staff/staff_dashboard.php',
    'tools/migrate.php',
];

if ($SKIP_SYNTAX) {
    out('  (skipped)');
} else {
    foreach ($files as $rel) {
        $path = $PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            check("syntax $rel", false, 'file missing');
            continue;
        }
        $out = [];
        $rc = 0;
        exec(escapeshellarg($PHP) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        check("syntax $rel", $rc === 0, implode(' ', $out));
    }
}

/* ------------------------------------------------------------------ */
/* 2. Migration idempotency                                            */
/* ------------------------------------------------------------------ */

out(PHP_EOL . '== 2. Migration idempotency ==');

if ($SKIP_MIGRATE) {
    out('  (skipped)');
} else {
    $migrator = $PROJECT_ROOT . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'migrate.php';

    // Run twice: the second run must be a no-op. Proves the migration is
    // safe to re-run and will not double-insert or error on existing schema.
    foreach ([1, 2] as $attempt) {
        $out = [];
        $rc = 0;
        exec(escapeshellarg($PHP) . ' ' . escapeshellarg($migrator) . ' 2>&1', $out, $rc);
        $joined = implode(' ', $out);
        check("migration run #$attempt exits 0", $rc === 0, $joined);
        if ($attempt === 2) {
            $noop = stripos($joined, 'already up to date') !== false
                 || stripos($joined, 'nothing to do') !== false
                 || stripos($joined, 'no changes') !== false;
            check('second run reports no changes', $noop, substr($joined, 0, 300));
        }
    }
}

/* ------------------------------------------------------------------ */
/* 3. HTTP behaviour                                                   */
/* ------------------------------------------------------------------ */

out(PHP_EOL . '== 3. HTTP behaviour (' . $BASE . ') ==');

$CURL = getenv('CURL_BIN') ?: 'curl';
if (PHP_OS_FAMILY === 'Windows') {
    $systemCurl = getenv('SystemRoot') . '\\system32\\curl.exe';
    if (is_file($systemCurl)) { $CURL = $systemCurl; }
}

$COOKIE_DIR = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usp_test_cookies';
@mkdir($COOKIE_DIR, 0700, true);
$U = $COOKIE_DIR . DIRECTORY_SEPARATOR . 'user.txt';
$A = $COOKIE_DIR . DIRECTORY_SEPARATOR . 'admin.txt';
$S = $COOKIE_DIR . DIRECTORY_SEPARATOR . 'staff.txt';
@unlink($U); @unlink($A); @unlink($S);

function login(string $url, string $email, string $pass_, string $jar): int
{
    global $CURL;
    $args = ['-s', '-X', 'POST', $url,
        '--data-urlencode', "email=$email",
        '--data-urlencode', "password=$pass_",
        '-c', $jar, '-o', 'NUL', '-w', '%{http_code}'];
    $parts = [];
    foreach ($args as $a) {
        $parts[] = ($a === '%{http_code}') ? q($a) : escapeshellarg($a);
    }
    $code = trim((string) shell_exec(escapeshellarg($CURL) . ' ' . implode(' ', $parts)) ?? '');
    return (int) $code;
}

if ($SKIP_HTTP) {
    out('  (skipped)');
} else {
    /* --- reachability --- */
    check('server reachable', http_status('GET', "$BASE/index.php") > 0, 'is the server running?');

    /* --- authentication --- */
    check('user login',  login("$BASE/user/login.php",  'user@usp.com',  'user1234', $U) === 302);
    check('admin login', login("$BASE/admin/admin_login.php", 'admin@usp.com', 'admin123', $A) === 302);
    check('staff login', login("$BASE/staff/staff_login.php", 'staff@usp.com', 'staff123', $S) === 302);

    /* --- user portal happy paths --- */
    check('user dashboard 200',    http_status('GET', "$BASE/user/index.php", ['cookie' => $U]) === 200);
    check('complaint list 200',    http_status('GET', "$BASE/user/complaint_status.php", ['cookie' => $U]) === 200);
    check('complaint detail 200',  http_status('GET', "$BASE/user/complaint_status.php?id=1", ['cookie' => $U]) === 200);
    check('unknown detail 404',    http_status('GET', "$BASE/user/complaint_status.php?id=999999", ['cookie' => $U]) === 404);
    check('submit form 200',       http_status('GET', "$BASE/user/submit_complaint.php", ['cookie' => $U]) === 200);

    /* --- admin portal happy paths --- */
    check('admin dashboard 200',     http_status('GET', "$BASE/admin/admin_dashboard_professional.php", ['cookie' => $A]) === 200);
    check('manage complaints 200',   http_status('GET', "$BASE/admin/manage_complaints.php", ['cookie' => $A]) === 200);
    check('assign staff 200',        http_status('GET', "$BASE/admin/assign_staff.php", ['cookie' => $A]) === 200);
    check('complaint detail ajax 200', http_status('GET', "$BASE/admin/get_complaint_detail.php?id=1", ['cookie' => $A]) === 200);

    /* --- staff portal happy paths --- */
    check('staff dashboard 200', http_status('GET', "$BASE/staff/staff_dashboard.php", ['cookie' => $S]) === 200);

    /* --- access control: anonymous users are bounced to login --- */
    check('anon user page redirects',   http_status('GET', "$BASE/user/index.php") === 302);
    check('anon admin page redirects',  http_status('GET', "$BASE/admin/manage_complaints.php") === 302);
    check('anon staff page redirects',  http_status('GET', "$BASE/staff/staff_dashboard.php") === 302);

    /* --- access control: role separation --- */
    check('staff blocked from admin',   in_array(http_status('GET', "$BASE/admin/manage_complaints.php", ['cookie' => $S]), [302, 403], true));
    check('user blocked from admin',    in_array(http_status('GET', "$BASE/admin/manage_complaints.php", ['cookie' => $U]), [302, 403], true));
    check('user blocked from staff',    in_array(http_status('GET', "$BASE/staff/staff_dashboard.php", ['cookie' => $U]), [302, 403], true));
    check('staff blocked from admin ajax', in_array(http_status('GET', "$BASE/admin/get_complaint_detail.php?id=1", ['cookie' => $S]), [302, 401, 403], true));

    /* --- evidence access control --- */
    $attachmentId = getenv('TEST_ATTACHMENT_ID') ?: '1';
    $codeOwner  = http_status('GET', "$BASE/user/view_attachment.php?id=$attachmentId", ['cookie' => $U]);
    $codeAnon   = http_status('GET', "$BASE/user/view_attachment.php?id=$attachmentId");
    $codeMissing = http_status('GET', "$BASE/user/view_attachment.php?id=999999", ['cookie' => $U]);

    check('attachment owner may view (200 or 404 if fixture absent)', in_array($codeOwner, [200, 404], true), "got $codeOwner");
    check('attachment anonymous is 403 or 404', in_array($codeAnon, [403, 404], true), "got $codeAnon");
    check('missing attachment is 404', $codeMissing === 404, "got $codeMissing");

    if ($codeOwner === 200) {
        $hdr = [];
        $raw = shell_exec(escapeshellarg($CURL) . ' ' . implode(' ', array_map('escapeshellarg',
            ['-s', '-I', '-b', $U, "$BASE/user/view_attachment.php?id=$attachmentId"]))) ?? '';
        foreach (explode("\r\n", (string) $raw) as $line) {
            $hdr[] = strtolower($line);
        }
        $joinedHdr = implode("\n", $hdr);
        check('attachment sends nosniff header', strpos($joinedHdr, 'x-content-type-options: nosniff') !== false);
        check('attachment sends content-disposition', strpos($joinedHdr, 'content-disposition:') !== false);
    }

    /* --- staff evidence visibility (Feature 1) ---------------------- *
     * Fixtures are created here and removed again on shutdown.        */
    $db = db_connect_for_tests();
    check('test database reachable for fixtures', $db !== null);
    $fx = $db ? evidence_fixtures_create($db) : null;
    if ($fx !== null) {
        register_shutdown_function('evidence_fixtures_cleanup', $fx);
        $db->close();
    }

    if ($fx !== null && $fx['assigned_complaint'] > 0 && $fx['assigned_attachment'] > 0) {
        // Staff dashboard now carries an Evidence column.
        $dash = http_body('GET', "$BASE/staff/staff_dashboard.php", ['cookie' => $S]);
        check('staff dashboard 200', $dash['code'] === 200, 'got ' . $dash['code']);
        check('staff dashboard shows evidence action',
            strpos($dash['body'], 'View Evidence (') !== false, 'no evidence action in dashboard');

        // Staff complaint details page lists the attached evidence.
        $detail = http_body('GET', "$BASE/staff/resolve_complaint.php?id={$fx['assigned_complaint']}", ['cookie' => $S]);
        check('staff complaint page 200', $detail['code'] === 200, 'got ' . $detail['code']);
        check('staff complaint page shows evidence section',
            strpos($detail['body'], 'Evidence Files (') !== false, 'section missing');
        check('staff complaint page offers View Evidence',
            strpos($detail['body'], 'View Evidence') !== false, 'no View Evidence action');
        check('staff complaint page shows file size and type',
            strpos($detail['body'], 'KB, ') !== false, 'no file metadata');
        check('staff complaint page lists every attachment (multi-file)',
            substr_count($detail['body'], 'view_attachment.php?id=') >= 2, 'expected >= 2 links');
        check('staff complaint page offers Download',
            strpos($detail['body'], 'download=1') !== false, 'no download link');

        // Complaint without evidence shows the empty state.
        if ($fx['clean_complaint'] > 0) {
            $clean = http_body('GET', "$BASE/staff/resolve_complaint.php?id={$fx['clean_complaint']}", ['cookie' => $S]);
            check('staff empty-state message for complaint without evidence',
                strpos($clean['body'], 'No evidence attached.') !== false, 'empty state missing');
        } else {
            check('staff empty-state message for complaint without evidence', false, 'no complaint without evidence found');
        }

        // Staff complaint not assigned to this staff member -> hidden.
        if ($fx['complaint'] > 0) {
            $foreign = http_body('GET', "$BASE/staff/resolve_complaint.php?id={$fx['complaint']}", ['cookie' => $S]);
            check('staff page hides unassigned complaint',
                strpos($foreign['body'], 'Complaint not found') !== false, 'unassigned complaint was shown');
        }
    }

    if ($fx !== null && $fx['foreign_attachment'] > 0) {
        // Endpoint-level enforcement (the UI can never bypass these).
        $c1 = http_status('GET', "$BASE/user/view_attachment.php?id={$fx['assigned_attachment']}", ['cookie' => $S]);
        check('assigned staff may open evidence (200)', $c1 === 200, "got $c1");

        $c2 = http_status('GET', "$BASE/user/view_attachment.php?id={$fx['foreign_attachment']}", ['cookie' => $S]);
        check('staff denied evidence outside assignment (403)', $c2 === 403, "got $c2");

        $c3 = http_status('GET', "$BASE/user/view_attachment.php?id={$fx['foreign_attachment']}", ['cookie' => $U]);
        check('user denied another user\'s evidence (403)', $c3 === 403, "got $c3");

        $c4 = http_status('GET', "$BASE/user/view_attachment.php?id={$fx['foreign_attachment']}");
        check('anonymous denied evidence (403)', $c4 === 403, "got $c4");

        $c5 = http_status('GET', "$BASE/user/view_attachment.php?id={$fx['foreign_attachment']}", ['cookie' => $A]);
        check('broken/missing file reference is 404, not an error page', $c5 === 404, "got $c5");

        $c6 = http_status('GET', "$BASE/user/view_attachment.php?id={$fx['assigned_attachment']}", ['cookie' => $A]);
        check('admin may still open evidence (200)', $c6 === 200, "got $c6");

        // Download option: same endpoint, Content-Disposition: attachment.
        $dlHdr = http_headers("$BASE/user/view_attachment.php?id={$fx['assigned_attachment']}&download=1", $S);
        check('download sends attachment disposition',
            strpos($dlHdr, 'content-disposition: attachment') !== false, substr($dlHdr, 0, 200));

        $inHdr = http_headers("$BASE/user/view_attachment.php?id={$fx['assigned_attachment']}", $S);
        check('view keeps inline disposition',
            strpos($inHdr, 'content-disposition: inline') !== false, substr($inHdr, 0, 200));
    }
}

/* ------------------------------------------------------------------ */
/* Summary                                                             */
/* ------------------------------------------------------------------ */

out(PHP_EOL . str_repeat('-', 60));
out("PASSED: $pass    FAILED: $fail");

if ($fail > 0) {
    out(PHP_EOL . 'Failures:');
    foreach ($failures as $f) {
        out('  - ' . $f);
    }
    out(PHP_EOL . 'RESULT: FAIL');
    exit(1);
}

out('RESULT: PASS');
exit(0);

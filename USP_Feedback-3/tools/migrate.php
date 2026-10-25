<?php
/**
 * Command-line migration runner.
 *
 *   php tools/migrate.php                 apply to the configured database (USP)
 *   php tools/migrate.php --dry-run       show what would change, change nothing
 *   php tools/migrate.php --database=USP_dryrun
 *
 * Safe to run repeatedly: every step is guarded, so a second run reports
 * "nothing to do" instead of failing or duplicating rows.
 * Refuses to run through the web server.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// This tool always checks return values explicitly; turn mysqli's exception
// mode off so a failed statement prints a clear message instead of a fatal.
mysqli_report(MYSQLI_REPORT_OFF);

$dryRun  = in_array('--dry-run', $argv, true);
$target  = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--database=') === 0) {
        $target = substr($arg, strlen('--database='));
    }
}
if ($target !== null && !preg_match('/^[A-Za-z0-9_]+$/', $target)) {
    fwrite(STDERR, "Invalid --database value.\n");
    exit(1);
}

/* ------------------------------------------------------------------ */
/* Connect using the same credentials as config/db_connect.php         */
/* ------------------------------------------------------------------ */
$dbName = $target ?: (getenv('DB_NAME') ?: 'USP');
$server = getenv('DB_SERVER') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';

$passwords = [];
if (getenv('DB_PASSWORD') !== false) {
    $passwords[] = getenv('DB_PASSWORD');
}
foreach (['', 'root', 'password'] as $guess) {
    if (!in_array($guess, $passwords, true)) {
        $passwords[] = $guess;
    }
}

$conn = null;
$lastError = '';
$needsCreate = false;
foreach ($passwords as $pw) {
    foreach ([true, false] as $useDb) {
        try {
            $try = @new mysqli($server, $dbUser, $pw, $useDb ? $dbName : '');
            if ($try && !$try->connect_error) {
                $conn = $try;
                if (!$useDb) {
                    $needsCreate = true;
                }
                break 2;
            }
            $lastError = $try ? $try->connect_error : 'unknown error';
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }
}
if (!$conn) {
    fwrite(STDERR, "Database connection failed: $lastError\n");
    exit(1);
}
$conn->set_charset('utf8');

if ($needsCreate) {
    if ($dryRun) {
        echo "[dry-run] database `$dbName` does not exist and would be created\n";
        exit(0);
    }
    $dbNameEsc = str_replace('`', '``', $dbName);
    if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$dbNameEsc` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
        fwrite(STDERR, "Could not create database `$dbName`: " . $conn->error . "\n");
        exit(1);
    }
    $conn->select_db($dbName);
    echo "Created database `$dbName`.\n";
}

echo "Target database: `$dbName`" . ($dryRun ? "  (dry run - no changes will be written)" : '') . "\n";

$changes = 0;

/* ------------------------------------------------------------------ */
/* 1. DDL from database/migration_001_*.sql (all CREATE ... IF NOT EXISTS) */
/* ------------------------------------------------------------------ */
$ddlFile = __DIR__ . '/../database/migration_001_evidence_notifications_timeline.sql';
if (!is_file($ddlFile)) {
    fwrite(STDERR, "Missing migration file: $ddlFile\n");
    exit(1);
}
$ddl = file_get_contents($ddlFile);
$ddl = preg_replace('/^--.*$/m', '', $ddl);            // strip comment lines
$statements = array_filter(array_map('trim', explode(';', $ddl)));

$tables = [];
foreach ($statements as $sql) {
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
        $tables[$m[1]] = $sql;
    }
}

$created = 0;
foreach ($tables as $name => $sql) {
    $r = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($dbName) . "'
                         AND TABLE_NAME = '" . $conn->real_escape_string($name) . "'");
    $exists = $r && (int)$r->fetch_row()[0] > 0;
    if ($exists) {
        echo "Step 1/3  table $name already exists - skipped\n";
        continue;
    }
    if ($dryRun) {
        echo "Step 1/3  table $name would be created\n";
        $created++;
        continue;
    }
    if (!$conn->query($sql)) {
        fwrite(STDERR, "FAILED creating $name: " . $conn->error . "\n");
        exit(1);
    }
    echo "Step 1/3  table $name created\n";
    $created++;
}
$changes += $created;

/* ------------------------------------------------------------------ */
/* 2. Columns the messaging code already expects on `replies`          */
/* ------------------------------------------------------------------ */
$wantColumns = [
    'is_read'    => "ALTER TABLE `replies` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0",
    'receiver_id'=> "ALTER TABLE `replies` ADD COLUMN `receiver_id` INT(11) DEFAULT NULL",
];
$existing = [];
$res = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($dbName) . "'
                       AND TABLE_NAME = 'replies'");
while ($res && ($row = $res->fetch_row())) {
    $existing[] = $row[0];
}
foreach ($wantColumns as $col => $sql) {
    if (in_array($col, $existing, true)) {
        echo "Step 2/3  replies.$col already present - skipped\n";
        continue;
    }
    if ($dryRun) {
        echo "Step 2/3  replies.$col would be added\n";
        $changes++;
        continue;
    }
    if (!$conn->query($sql)) {
        fwrite(STDERR, "FAILED adding replies.$col: " . $conn->error . "\n");
        exit(1);
    }
    echo "Step 2/3  replies.$col added\n";
    $changes++;
}

// Optional FK, only when receiver_id was just created and no FK exists yet.
if (!$dryRun && !in_array('receiver_id', $existing, true)) {
    $fk = $conn->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($dbName) . "'
                          AND TABLE_NAME = 'replies'
                          AND COLUMN_NAME = 'receiver_id'
                          AND REFERENCED_TABLE_NAME IS NOT NULL");
    if ($fk && (int)$fk->fetch_row()[0] === 0) {
        $conn->query("ALTER TABLE `replies` ADD CONSTRAINT `fk_replies_receiver`
                      FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
    }
}

/* ------------------------------------------------------------------ */
/* 3. Honest backfill of complaint_status_history                       */
/*    Only events we can actually establish are written.                */
/* ------------------------------------------------------------------ */
$backfillSubmitted = "
    INSERT INTO complaint_status_history
        (complaint_id, event_type, status, message, actor_id, actor_role, created_at)
    SELECT c.complaint_id AS complaint_id,
           'submitted' AS event_type,
           CASE WHEN c.status = 'Pending' THEN 'Pending' ELSE NULL END AS status,
           'Complaint submitted' AS message,
           NULL AS actor_id,
           NULL AS actor_role,
           c.created_at AS created_at
    FROM complaints c
    WHERE c.created_at IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM complaint_status_history h
                      WHERE h.complaint_id = c.complaint_id AND h.event_type = 'submitted')";

$backfillClosed = "
    INSERT INTO complaint_status_history
        (complaint_id, event_type, status, message, actor_id, actor_role, created_at)
    SELECT c.complaint_id AS complaint_id,
           'closed' AS event_type,
           'Resolved' AS status,
           CONCAT('Complaint closed.', COALESCE(CONCAT(' Resolution: ', LEFT(c.solution, 350)), '')) AS message,
           NULL AS actor_id,
           NULL AS actor_role,
           c.resolved_at AS created_at
    FROM complaints c
    WHERE c.status = 'Resolved'
      AND c.resolved_at IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM complaint_status_history h
                      WHERE h.complaint_id = c.complaint_id AND h.event_type = 'closed')";

// Counts rows an INSERT ... SELECT would write, without writing them.
$countPending = function (string $sql) use ($conn, $dbName): int {
    $r = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($dbName) . "'
                         AND TABLE_NAME = 'complaint_status_history'");
    if (!$r || (int)$r->fetch_row()[0] === 0) {
        return -1;                     // table not there yet (dry run)
    }
    $pos = stripos($sql, 'SELECT');
    if ($pos === false) {
        return -1;
    }
    $r = $conn->query("SELECT COUNT(*) FROM (" . substr($sql, $pos) . ") q");
    return $r ? (int)$r->fetch_row()[0] : -1;
};

$pendingSubmitted = $countPending($backfillSubmitted);
$pendingClosed    = $countPending($backfillClosed);

if ($dryRun) {
    $s = $pendingSubmitted < 0 ? 'all' : (string) $pendingSubmitted;
    $c = $pendingClosed < 0 ? 'all' : (string) $pendingClosed;
    echo "Step 3/3  backfill would add $s 'submitted' and $c 'closed' history row(s)\n";
} else {
    $n1 = $conn->query($backfillSubmitted) ? $conn->affected_rows : -1;
    $n2 = $conn->query($backfillClosed) ? $conn->affected_rows : -1;
    if ($n1 < 0 || $n2 < 0) {
        fwrite(STDERR, "Backfill failed: " . $conn->error . "\n");
        exit(1);
    }
    $changes += $n1 + $n2;
    echo "Step 3/3  backfilled $n1 'submitted' and $n2 'closed' history row(s)\n";

    // Complaints marked Resolved without resolved_at: we cannot establish
    // when, so we deliberately do not invent an event. Report them.
    $r = $conn->query("SELECT COUNT(*) FROM complaints c
                       WHERE c.status = 'Resolved' AND c.resolved_at IS NULL
                         AND NOT EXISTS (SELECT 1 FROM complaint_status_history h
                                         WHERE h.complaint_id = c.complaint_id AND h.event_type = 'closed')");
    if ($r) {
        $n = (int)$r->fetch_row()[0];
        if ($n > 0) {
            echo "        NOTE: $n Resolved complaint(s) have no resolved_at, so no closure event " .
                 "was fabricated; their timeline shows only what is known.\n";
        }
    }
}

/* ------------------------------------------------------------------ */
if ($changes === 0) {
    echo "Nothing to do - already up to date.\n";
} else {
    echo ($dryRun ? "Dry run complete: " : "Migration complete: ") . "$changes pending change(s).\n";
}

$conn->close();
exit(0);

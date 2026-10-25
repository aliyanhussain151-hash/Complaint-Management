<?php
session_start();
include '../config/db_connect.php';
include '../includes/common_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$success = '';
$error = '';
$errors = [];               // one message per rejected file
$submitted_id = null;

// The enhanced form submits through XMLHttpRequest so it can show a real
// upload progress bar; without JavaScript the same handler answers with HTML.
$is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

/** Emits the response in the format the caller can handle, then stops. */
function submit_response(bool $ajax, string $success, string $error, array $extra = []): void
{
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => $error === '',
            'message'=> $error === '' ? $success : $error,
            'error'  => $error,
        ] + $extra);
        exit;
    }
}

/**
 * Flattens $_FILES['evidence'] (an array-of-arrays from input name="evidence[]")
 * into one entry per file. Slots the browser sent as "nothing chosen" are dropped.
 */
function evidence_entries_from_request(): array
{
    $f = $_FILES['evidence'] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) {
        return [];
    }
    $entries = [];
    foreach ($f['name'] as $i => $name) {
        $error = (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE && ($name === '' || $name === null)) {
            continue;
        }
        $entries[] = [
            'name'     => (string) $name,
            'type'     => (string) ($f['type'][$i] ?? ''),
            'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''),
            'error'    => $error,
            'size'     => (int) ($f['size'][$i] ?? 0),
        ];
    }
    return $entries;
}

/** Cheap per-session throttle: no time-based limiter exists in this app. */
function evidence_rate_limit_ok(bool $consume): bool
{
    $window = 300;                 // 5 minutes
    $max    = 60;                  // files per window (12 full complaints)
    $now    = time();
    $state  = $_SESSION['evidence_rate'] ?? ['start' => $now, 'count' => 0];
    if ($now - (int) $state['start'] >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    if ((int) $state['count'] >= $max) {
        $_SESSION['evidence_rate'] = $state;
        return false;
    }
    if ($consume) {
        $state['count']++;
        $_SESSION['evidence_rate'] = $state;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $subject  = mysqli_real_escape_string($conn, $_POST['subject'] ?? '');
    $category = mysqli_real_escape_string($conn, $_POST['category'] ?? '');
    $message  = mysqli_real_escape_string($conn, $_POST['message'] ?? '');

    $entries = evidence_entries_from_request();

    if (!csrf_valid()) {
        $error = 'Security token expired. Please reload the page and try again.';
    } elseif (empty($subject) || empty($category) || empty($message)) {
        $error = 'All fields are required!';
    } elseif (count($entries) > EVIDENCE_MAX_FILES) {
        $error = sprintf('You can attach at most %d files to one complaint.', EVIDENCE_MAX_FILES);
    } elseif ($entries && !evidence_rate_limit_ok(false)) {
        $error = 'Too many uploads in a short time. Please wait a few minutes and try again.';
    } else {
        /* ---------- validate every file BEFORE touching the database ---------- */
        $validated = [];
        $total = 0;
        foreach ($entries as $entry) {
            $result = evidence_validate($entry);
            if (!$result['ok']) {
                $label = evidence_sanitize_original_name($entry['name']);
                $errors[] = '"' . $label . '": ' . $result['error'];
                continue;
            }
            $total += $result['size'];
            if ($total > EVIDENCE_MAX_TOTAL_BYTES) {
                $errors[] = sprintf('Evidence exceeds the %d MB limit per complaint.',
                    (int) (EVIDENCE_MAX_TOTAL_BYTES / 1048576));
                $validated = [];
                break;
            }
            $validated[] = ['file' => $entry, 'meta' => $result];
        }

        if (!empty($errors)) {
            $error = 'No complaint was created - ' . count($errors) . ' file(s) were rejected.';
        } elseif ($validated && !evidence_rate_limit_ok(true)) {
            $error = 'Too many uploads in a short time. Please wait a few minutes and try again.';
        } else {
            /* ---------- one transaction: complaint + attachment rows ---------- */
            $stored = [];          // file names we already moved (for rollback)
            $ok = false;
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('Could not start transaction: ' . $conn->error);
                }

                $query = "INSERT INTO complaints (user_id, subject, category, message, status)
                          VALUES ($user_id, '$subject', '$category', '$message', 'Pending')";
                if (!mysqli_query($conn, $query)) {
                    throw new RuntimeException('Error: ' . mysqli_error($conn));
                }
                $cid = (int) mysqli_insert_id($conn);

                foreach ($validated as $item) {
                    $stored_name = evidence_random_name($item['meta']['ext']);
                    $store = evidence_store_file($item['file'], $stored_name);
                    if (!$store['ok']) {
                        throw new RuntimeException($store['error']);
                    }
                    $stored[] = $stored_name;

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
                    $stmt->bind_param('isssii', $cid, $stored_name, $orig, $mime, $size, $user_id);
                    if (!$stmt->execute()) {
                        $e = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Could not register the attachment: ' . $e);
                    }
                    $stmt->close();
                }

                if (!record_status_event($conn, $cid, 'submitted', 'Pending',
                    'Complaint submitted', $user_id, 'user')) {
                    throw new RuntimeException('Could not record the submission in the timeline.');
                }

                if (!$conn->commit()) {
                    throw new RuntimeException('Commit failed: ' . $conn->error);
                }
                $submitted_id = $cid;
                $ok = true;
                $success = 'Complaint submitted successfully! ID: #' . $cid;
            } catch (Throwable $e) {
                @$conn->rollback();
                // Remove every file we already wrote so nothing is orphaned.
                foreach ($stored as $name) {
                    evidence_delete_file($name);
                }
                error_log('[submit_complaint] rolled back: ' . $e->getMessage());
                $success = '';
                $error = $e->getMessage();
                $submitted_id = null;
            }
        }
    }
    submit_response($is_ajax, $success, $error, [
        'complaint_id' => $submitted_id,
        'file_errors'  => $errors,
    ]);
}

$unread_notifications = count_unread_notifications($conn, $user_id);
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT email FROM users WHERE id = $user_id"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit Complaint</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>

/* SIDEBAR */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    background: #667eea;
    padding: 20px;
    color: white;
}

.sidebar a {
    color: white;
    display: block;
    padding: 10px;
    text-decoration: none;
}

.sidebar a:hover {
    background: rgba(255,255,255,0.2);
}

/* TOP BAR */
.topbar {
    margin-left: 250px;
    background: white;
    padding: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
}

/* MAIN */
.main-content {
    margin-left: 250px;
    padding: 20px;
}

/* EVIDENCE UPLOAD */
.evidence-drop {
    border: 2px dashed #b6bfd6;
    border-radius: 10px;
    background: #f8f9fc;
    padding: 1.25rem;
    text-align: center;
    transition: border-color .2s, background .2s;
}
.evidence-drop.is-dragover {
    border-color: #667eea;
    background: #eef0fb;
}
.evidence-drop:focus-within {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,.25);
}
.evidence-drop i { font-size: 1.8rem; color: #667eea; }
.evidence-file-row {
    display: flex;
    align-items: center;
    gap: .75rem;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: .5rem .75rem;
    margin-bottom: .5rem;
}
.evidence-file-row .file-icon { color: #667eea; font-size: 1.1rem; }
.evidence-file-row .file-meta { color: #6b7280; font-size: .8rem; }
.evidence-file-row .file-name {
    font-weight: 600; font-size: .9rem; color: #2d3748;
    overflow-wrap: anywhere;
}
.evidence-file-row.is-rejected { border-color: #f5576c; background: #fff5f6; }
#uploadProgressWrap[hidden] { display: none; }

</style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <h4>USER PANEL</h4>
    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
    <a href="submit_complaint.php"><i class="fas fa-plus"></i> New Complaint</a>
    <a href="complaint_status.php"><i class="fas fa-list"></i> Complaints</a>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<!-- TOP BAR -->
<div class="topbar">
    <div><b>Submit Complaint</b></div>
    <div><i class="fas fa-user"></i> <?php echo $_SESSION['user_name']; ?></div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">

<div class="card shadow">
    <div class="card-header bg-primary text-white">
        Submit New Complaint
    </div>

    <div class="card-body p-4">

        <div id="formAlerts" aria-live="polite">
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $fe): ?>
                <div class="alert alert-danger mb-1"><?php echo htmlspecialchars($fe); ?></div>
            <?php endforeach; ?>
        </div>

        <form method="POST" id="complaintForm" enctype="multipart/form-data" novalidate>

            <?php echo csrf_field(); ?>

            <div class="mb-3">
                <label>Name</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" readonly>
            </div>

            <div class="mb-3">
                <label>Email</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
            </div>

            <div class="mb-3">
                <label for="subject">Subject *</label>
                <input type="text" id="subject" name="subject" class="form-control" required>
            </div>

            <div class="mb-3">
                <label for="category">Category *</label>
                <select id="category" name="category" class="form-select" required>
                    <option value="">Select</option>
                    <option>Technical</option>
                    <option>Billing</option>
                    <option>Service</option>
                    <option>Feedback</option>
                    <option>Other</option>
                </select>
            </div>

            <div class="mb-3">
                <label for="message">Message *</label>
                <textarea id="message" name="message" class="form-control" rows="5" required></textarea>
            </div>

            <!-- ---------------- Evidence ---------------- -->
            <div class="mb-3">
                <label class="form-label fw-bold" for="evidenceInput">
                    Evidence
                    <small class="text-muted fw-normal">(optional)</small>
                </label>

                <div class="evidence-drop" id="dropZone">
                    <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
                    <p class="mb-2 mt-2" id="dropZoneText">
                        Drag and drop files here, or choose them below.
                    </p>
                    <label for="evidenceInput" class="btn btn-outline-primary btn-sm mb-0">
                        <i class="fas fa-folder-open" aria-hidden="true"></i> Choose files
                    </label>
                    <input type="file"
                           id="evidenceInput"
                           name="evidence[]"
                           multiple
                           class="visually-hidden"
                           accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                           aria-describedby="evidenceHelp">
                </div>

                <div id="evidenceHelp" class="form-text">
                    JPG, JPEG, PNG or PDF. Up to <?php echo EVIDENCE_MAX_FILES; ?> files,
                    <?php echo (int) (EVIDENCE_MAX_FILE_BYTES / 1048576); ?> MB each and
                    <?php echo (int) (EVIDENCE_MAX_TOTAL_BYTES / 1048576); ?> MB in total.
                </div>

                <ul id="fileList" class="list-unstyled mt-2" aria-label="Selected evidence files"></ul>

                <div id="fileListError" class="text-danger small" role="alert"></div>

                <div id="uploadProgressWrap" class="mt-2" hidden>
                    <label for="uploadProgress" class="form-label small mb-1">Uploading&hellip;</label>
                    <div class="progress" style="height: 10px;">
                        <div id="uploadProgress"
                             class="progress-bar"
                             role="progressbar"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             aria-valuenow="0"
                             aria-labelledby="uploadProgress"
                             style="width: 0%"></div>
                    </div>
                </div>
                <div id="uploadStatus" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>
            </div>

            <button class="btn btn-primary" id="submitBtn" type="submit">
                <span class="btn-label"><i class="fas fa-paper-plane" aria-hidden="true"></i> Submit</span>
            </button>

        </form>

    </div>
</div>

</div>

<script>
(function () {
    'use strict';

    var MAX_FILES     = <?php echo EVIDENCE_MAX_FILES; ?>;
    var MAX_FILE_BYTES= <?php echo EVIDENCE_MAX_FILE_BYTES; ?>;
    var ALLOWED_EXT   = <?php echo json_encode(EVIDENCE_ALLOWED_EXTENSIONS); ?>;
    var BLOCKED_EXT   = <?php echo json_encode(EVIDENCE_DANGEROUS_EXTENSIONS); ?>;
    var BYTES_PER_MB  = 1048576;

    var input     = document.getElementById('evidenceInput');
    var dropZone  = document.getElementById('dropZone');
    var list      = document.getElementById('fileList');
    var listError = document.getElementById('fileListError');
    var form      = document.getElementById('complaintForm');
    var submitBtn = document.getElementById('submitBtn');
    var alerts    = document.getElementById('formAlerts');
    var wrap      = document.getElementById('uploadProgressWrap');
    var bar       = document.getElementById('uploadProgress');
    var status    = document.getElementById('uploadStatus');

    var selected = [];          // File objects kept outside the input
    var busy     = false;

    function extOf(name) {
        var i = name.lastIndexOf('.');
        return i === -1 ? '' : name.substring(i + 1).toLowerCase();
    }

    function humanSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function say(text) { status.textContent = text; }

    /** Client-side check. The server repeats every one of these independently. */
    function clientCheck(file) {
        if (file.size === 0)                       return 'the file is empty';
        if (file.size > MAX_FILE_BYTES)            return 'larger than ' + (MAX_FILE_BYTES / BYTES_PER_MB) + ' MB';
        if (file.name.indexOf('\0') !== -1)        return 'invalid file name';
        var parts = file.name.toLowerCase().split('.');
        if (parts.length > 1) {
            for (var i = 0; i < parts.length - 1; i++) {
                if (BLOCKED_EXT.indexOf(parts[i]) !== -1) return 'disallowed extension combination';
            }
        }
        if (ALLOWED_EXT.indexOf(extOf(file.name)) === -1) return 'only JPG, JPEG, PNG and PDF are allowed';
        return null;
    }

    function render() {
        list.innerHTML = '';
        selected.forEach(function (file, index) {
            var problem = clientCheck(file);

            var li = document.createElement('li');
            li.className = 'evidence-file-row' + (problem ? ' is-rejected' : '');

            var icon = document.createElement('i');
            icon.className = 'fas ' + (problem ? 'fa-exclamation-triangle text-danger'
                                                : 'fa-file file-icon') + ' aria-hidden="true';
            icon.setAttribute('aria-hidden', 'true');

            var body = document.createElement('div');
            body.className = 'flex-grow-1';

            var name = document.createElement('div');
            name.className = 'file-name';
            name.textContent = file.name;

            var meta = document.createElement('div');
            meta.className = 'file-meta';
            meta.textContent = humanSize(file.size) + '  \u00b7  ' +
                (file.type || 'unknown type') + '  \u00b7  ' +
                (problem ? 'rejected: ' + problem : 'ready');

            body.appendChild(name);
            body.appendChild(meta);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i> Remove';
            remove.setAttribute('aria-label', 'Remove ' + file.name);
            remove.addEventListener('click', function () {
                selected.splice(index, 1);
                render();
                say(file.name + ' removed. ' + selected.length + ' file(s) selected.');
            });

            li.appendChild(icon);
            li.appendChild(body);
            li.appendChild(remove);
            list.appendChild(li);
        });
        listError.textContent = selected.length > MAX_FILES
            ? 'Too many files: remove ' + (selected.length - MAX_FILES) + ' to stay at ' + MAX_FILES + '.'
            : '';
    }

    function addFiles(fileListLike) {
        for (var i = 0; i < fileListLike.length; i++) {
            if (selected.length >= MAX_FILES) {
                listError.textContent = 'You can attach at most ' + MAX_FILES + ' files.';
                break;
            }
            selected.push(fileListLike[i]);
        }
        render();
        say(selected.length + ' file(s) selected.');
    }

    input.addEventListener('change', function () {
        addFiles(input.files);
        input.value = '';        // so picking the same file again still fires change
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
        dropZone.addEventListener(ev, function (e) {
            e.preventDefault();
            dropZone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropZone.addEventListener(ev, function (e) {
            e.preventDefault();
            dropZone.classList.remove('is-dragover');
        });
    });
    dropZone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
    });

    function showAlert(type, text) {
        alerts.innerHTML = '';
        var div = document.createElement('div');
        div.className = 'alert alert-' + type;
        div.textContent = text;
        alerts.appendChild(div);
        div.scrollIntoView({ block: 'nearest' });
    }

    function setBusy(on) {
        busy = on;
        submitBtn.disabled = on;
        submitBtn.querySelector('.btn-label').innerHTML = on
            ? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Submitting\u2026'
            : '<i class="fas fa-paper-plane" aria-hidden="true"></i> Submit';
    }

    form.addEventListener('submit', function (e) {
        if (busy) { e.preventDefault(); return; }

        var blocking = [];
        selected.forEach(function (f) {
            var p = clientCheck(f);
            if (p) blocking.push(f.name + ': ' + p);
        });
        if (blocking.length) {
            e.preventDefault();
            showAlert('danger', 'Please remove the rejected file(s) before submitting: ' + blocking.join('; '));
            return;
        }

        var required = ['subject', 'category', 'message'];
        for (var i = 0; i < required.length; i++) {
            var el = document.getElementById(required[i]);
            if (!el.value.trim()) {
                if (!e.preventDefault) return;
            }
        }

        if (typeof XMLHttpRequest === 'undefined') return;   // let the browser POST natively

        e.preventDefault();
        setBusy(true);
        wrap.hidden = false;
        bar.style.width = '0%';
        bar.setAttribute('aria-valuenow', '0');
        say('Uploading 0 percent.');

        var data = new FormData(form);
        data.delete('evidence[]');
        selected.forEach(function (f) { data.append('evidence[]', f, f.name); });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) return;
            var pct = Math.round((ev.loaded / ev.total) * 100);
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', String(pct));
            bar.textContent = pct + '%';
            say('Uploading ' + pct + ' percent.');
        });

        xhr.addEventListener('load', function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (err) { data = null; }

            if (xhr.status === 200 && data && data.ok) {
                bar.style.width = '100%';
                bar.setAttribute('aria-valuenow', '100');
                showAlert('success', data.message);
                say(data.message);
                form.reset();
                selected = [];
                render();
                wrap.hidden = true;
            } else {
                var msg = (data && (data.error || data.message)) ||
                          'Upload failed (HTTP ' + xhr.status + ').';
                showAlert('danger', msg);
                say(msg);
                if (data && data.file_errors && data.file_errors.length) {
                    data.file_errors.forEach(function (fe) {
                        var d = document.createElement('div');
                        d.className = 'alert alert-danger mb-1';
                        d.textContent = fe;
                        alerts.appendChild(d);
                    });
                }
                wrap.hidden = true;
            }
            setBusy(false);
        });

        xhr.addEventListener('error', function () {
            showAlert('danger', 'Network error - the complaint was not submitted.');
            say('Network error - the complaint was not submitted.');
            wrap.hidden = true;
            setBusy(false);
        });

        xhr.send(data);
    });
})();
</script>

</body>
</html>

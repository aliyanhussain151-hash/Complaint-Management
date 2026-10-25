<?php
session_start();
include_once '../includes/common_helpers.php';
include '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Optional detail view: complaint_status.php?id=N
$view_id = 0;
if (isset($_GET['id'])) {
    $view_id = (int) $_GET['id'];
}

$detail = null;
$timeline = [];
$attachments = [];
$add_error = '';
$add_success = '';

if ($view_id > 0) {
    // Ownership is verified on the server for every request.
    $stmt = mysqli_prepare($conn, "SELECT * FROM complaints WHERE complaint_id = ? AND user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ii", $view_id, $user_id);
    mysqli_stmt_execute($stmt);
    $detail = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$detail) {
        // Do not reveal whether the complaint exists for someone else.
        http_response_code(404);
        $not_found = true;
    }

    if ($detail) {
        // --- Handle "add more evidence" on an existing complaint ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evidence'])) {
            if (!csrf_valid()) {
                $add_error = 'Your session token is invalid. Please reload the page and try again.';
            } else {
                $result = evidence_store_files($conn, $user_id, (int) $detail['complaint_id'], $_FILES['extra_files'] ?? null);
                if ($result['ok']) {
                    $add_success = 'Evidence added (' . count($result['files']) . ' file(s)).';
                    // refresh lists below
                    $detail = $detail; // unchanged
                } else {
                    $add_error = $result['error'];
                }
            }
        }

        // Timeline events (Feature 3) - via shared helper (joins actor name)
        $timeline = get_complaint_timeline($conn, $view_id);

        // Evidence files (Feature 1)
        $stmt = mysqli_prepare($conn, "SELECT * FROM complaint_attachments WHERE complaint_id = ? AND uploaded_by = ? ORDER BY attachment_id ASC");
        mysqli_stmt_bind_param($stmt, "ii", $view_id, $user_id);
        mysqli_stmt_execute($stmt);
        $attachments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    }
} else {
    $query = "SELECT * FROM complaints WHERE user_id = $user_id ORDER BY created_at DESC";
    $result = mysqli_query($conn, $query);
    $complaints = mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Status -> badge class, same palette the app already uses
function status_badge_class(string $status): string
{
    return match ($status) {
        'Pending' => 'bg-warning text-dark',
        'In Progress' => 'bg-info text-dark',
        'Resolved' => 'bg-success',
        default => 'bg-secondary',
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $detail ? 'Complaint #' . (int) $detail['complaint_id'] : 'My Complaints'; ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>

/* SIDEBAR */
.sidebar{
    position:fixed;
    left:0;
    top:0;
    width:250px;
    height:100vh;
    background:#667eea;
    color:white;
    padding:20px;
}

.sidebar a{
    display:block;
    color:white;
    padding:10px;
    text-decoration:none;
}

.sidebar a:hover{
    background:rgba(255,255,255,0.2);
}

/* TOPBAR */
.topbar{
    margin-left:250px;
    background:white;
    padding:15px;
    box-shadow:0 2px 10px rgba(0,0,0,0.1);
    display:flex;
    justify-content:space-between;
}

/* MAIN */
.main-content{
    margin-left:250px;
    padding:20px;
}

/* TIMELINE (Feature 3) - semantic list styled as a vertical timeline */
.timeline{
    list-style:none;
    margin:0;
    padding:0 0 0 24px;
    border-left:3px solid #667eea;
}
.timeline li{
    position:relative;
    padding:0 0 18px 18px;
}
.timeline li::before{
    content:"";
    position:absolute;
    left:-31px;
    top:4px;
    width:13px;
    height:13px;
    border-radius:50%;
    background:#667eea;
    border:2px solid #fff;
    box-shadow:0 0 0 2px #667eea;
}
.timeline .tl-date{
    font-size:.78rem;
    color:#6c757d;
}

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

<!-- TOPBAR -->
<div class="topbar">
    <div><b><?php echo $detail ? 'Complaint Details' : 'My Complaints'; ?></b></div>
    <div><i class="fas fa-user"></i> <?php echo $_SESSION['user_name']; ?></div>
</div>

<!-- MAIN -->
<div class="main-content">

<?php if (isset($not_found)): ?>
    <div class="alert alert-danger">
        Complaint not found. <a href="complaint_status.php">Back to my complaints</a>
    </div>

<?php elseif ($detail): ?>
    <!-- ============ DETAIL VIEW: complaint + timeline + evidence ============ -->
    <a href="complaint_status.php" class="btn btn-outline-secondary btn-sm mb-3">
        <i class="fas fa-arrow-left"></i> Back to list
    </a>

    <div class="card shadow mb-3">
        <div class="card-header bg-primary text-white d-flex justify-content-between">
            <span>Complaint #<?php echo (int) $detail['complaint_id']; ?> &mdash; <?php echo htmlspecialchars($detail['subject']); ?></span>
            <span class="badge <?php echo status_badge_class($detail['status']); ?>"><?php echo htmlspecialchars($detail['status']); ?></span>
        </div>
        <div class="card-body">
            <p><b>Category:</b> <?php echo htmlspecialchars($detail['category']); ?></p>
            <p><b>Submitted:</b> <?php echo htmlspecialchars($detail['created_at']); ?></p>
            <?php if (!empty($detail['assigned_staff'])):
                $sn = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM users WHERE id = " . (int) $detail['assigned_staff']));
            ?>
                <p><b>Assigned to:</b> <?php echo htmlspecialchars($sn['name'] ?? ('Staff #' . (int) $detail['assigned_staff'])); ?></p>
            <?php endif; ?>

            <p><b>Message:</b><br><?php echo nl2br(htmlspecialchars($detail['message'])); ?></p>

            <?php if (!empty($detail['solution'])): ?>
                <div class="alert alert-success">
                    <b><?php echo $detail['status'] === 'Resolved' ? 'Resolution' : 'Solution (proposed)'; ?>:</b>
                    <?php echo nl2br(htmlspecialchars($detail['solution'])); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status history timeline (Feature 3) -->
    <div class="card shadow mb-3">
        <div class="card-header bg-primary text-white"><i class="fas fa-history"></i> Status History</div>
        <div class="card-body">
            <?php if (count($timeline) > 0): ?>
                <ol class="timeline">
                    <?php foreach ($timeline as $event): ?>
                        <li>
                            <b><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $event['event_type']))); ?></b>
                            <?php if ($event['status'] !== null && $event['status'] !== ''): ?>
                                <span class="badge <?php echo status_badge_class($event['status']); ?>"><?php echo htmlspecialchars($event['status']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($event['actor_name'])): ?>
                                <div class="small">By: <?php echo htmlspecialchars($event['actor_name']); ?> (<?php echo htmlspecialchars($event['actor_role'] ?? 'system'); ?>)</div>
                            <?php endif; ?>
                            <?php if (!empty($event['message'])): ?>
                                <div class="small fst-italic"><?php echo htmlspecialchars($event['message']); ?></div>
                            <?php endif; ?>
                            <div class="tl-date"><?php echo htmlspecialchars($event['created_at']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="text-muted mb-0">No status changes recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Evidence files (Feature 1) -->
    <div class="card shadow mb-3">
        <div class="card-header bg-primary text-white"><i class="fas fa-paperclip"></i> Evidence Files</div>
        <div class="card-body">

            <?php if ($add_error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($add_error); ?></div>
            <?php endif; ?>
            <?php if ($add_success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($add_success); ?></div>
            <?php endif; ?>

            <?php if (count($attachments) > 0): ?>
                <ul class="list-group mb-3">
                    <?php foreach ($attachments as $att): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-file me-2"></i>
                                <?php echo htmlspecialchars($att['original_name']); ?>
                                <span class="text-muted small">(<?php echo round($att['file_size'] / 1024, 1); ?> KB, <?php echo htmlspecialchars($att['mime_type']); ?>)</span>
                            </span>
                            <a class="btn btn-sm btn-outline-primary" href="view_attachment.php?id=<?php echo (int) $att['attachment_id']; ?>" target="_blank" rel="noopener">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">No evidence files attached to this complaint.</p>
            <?php endif; ?>

            <?php if ($detail['status'] !== 'Resolved' && count($attachments) < 5): ?>
                <form method="post" enctype="multipart/form-data" id="addEvidenceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="add_evidence" value="1">
                    <label for="extra_files" class="form-label">Add more evidence</label>
                    <input class="form-control" type="file" id="extra_files" name="extra_files[]"
                           accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" multiple required>
                    <div class="form-text">JPEG or PNG images, or PDF documents. Max 5 MB per file, up to 5 files per complaint.</div>
                    <button type="submit" class="btn btn-primary mt-2"><i class="fas fa-upload"></i> Upload evidence</button>
                </form>
            <?php else: ?>
                <p class="text-muted mb-0">
                    <?php if ($detail['status'] === 'Resolved'): ?>
                        This complaint is Resolved; no further evidence can be added.
                    <?php else: ?>
                        File limit reached (5 files per complaint).
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ============ LIST VIEW ============ -->
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            Complaint Status
        </div>

        <div class="card-body">

        <?php if(count($complaints) > 0): ?>
        <div class="table-responsive">
        <table class="table table-hover">

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach($complaints as $complaint): ?>

            <tr>
                <td>#<?php echo $complaint['complaint_id']; ?></td>
                <td><?php echo htmlspecialchars($complaint['subject']); ?></td>
                <td><?php echo htmlspecialchars($complaint['category']); ?></td>

                <td>
                    <span class="badge <?php echo status_badge_class($complaint['status']); ?>">
                        <?php echo htmlspecialchars($complaint['status']); ?>
                    </span>
                </td>

                <td>
                    <?php echo date('Y-m-d', strtotime($complaint['created_at'])); ?>
                </td>

                <td>
                    <?php
                    // Unread replies on THIS complaint only (fixed: previously queried
                    // all replies by everyone, ignoring complaint_id).
                    $unread = 0;
                    $cq = "SELECT COUNT(*) AS total FROM replies
                           WHERE is_read = 0 AND complaint_id = " . (int) $complaint['complaint_id'] .
                           " AND replied_by != " . (int) $user_id;
                    $cr = mysqli_query($conn, $cq);
                    if ($cr) {
                        $crow = mysqli_fetch_assoc($cr);
                        $unread = (int) ($crow['total'] ?? 0);
                    }
                    ?>

                    <a href="complaint_status.php?id=<?php echo (int) $complaint['complaint_id']; ?>" class="btn btn-sm btn-outline-secondary" title="Details & history">
                        <i class="fas fa-info-circle"></i> Details
                    </a>

                    <a href="reply_to_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-primary btn-sm">
                        Messages
                        <?php if ($unread > 0): ?>
                            <span class="badge bg-danger">
                                <?php echo $unread; ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-success">0</span>
                        <?php endif; ?>
                    </a>
                </td>
            </tr>

            <tr>
                <td colspan="6">
                    <?php echo nl2br(htmlspecialchars($complaint['message'])); ?>

                    <?php if(!empty($complaint['solution'])): ?>
                        <br><b>Solution:</b>
                        <span class="text-success">
                            <?php echo nl2br(htmlspecialchars($complaint['solution'])); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>
        </div>

        <?php else: ?>
            <div class="alert alert-info">No complaints found</div>
        <?php endif; ?>

        </div>
    </div>
<?php endif; ?>

</div>

</body>
</html>

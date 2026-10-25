<?php
session_start();
include '../config/db_connect.php';
include_once '../includes/common_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'staff') {
    header('Location: staff_login.php');
    exit();
}

$staff_id = (int) $_SESSION['user_id'];

$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$complaint_id) {
    die("Complaint ID missing");
}

/* FETCH */
$query = "
    SELECT c.*, u.name as user_name, u.email as user_email
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    WHERE c.complaint_id = $complaint_id
    AND c.assigned_staff = $staff_id
";

$result = mysqli_query($conn, $query);
$complaint = mysqli_fetch_assoc($result);

if (!$complaint) {
    die("<div class='alert alert-danger m-4'>Complaint not found</div>");
}

/* mark read */
mysqli_query($conn, "
    UPDATE replies
    SET is_read = 1
    WHERE complaint_id = $complaint_id
    AND receiver_id = $staff_id
");

/* actions */
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['update'])) {

        $new_status = $_POST['status'] ?? '';
        $solution = mysqli_real_escape_string($conn, $_POST['solution']);

        if (!in_array($new_status, ['Pending', 'In Progress', 'Resolved'], true)) {
            $error = "Invalid status";
        } else {
            // Feature 2/3: when staff resolve a complaint it counts as closure,
            // so the student must be notified - in the SAME transaction as the
            // status change. A failure rolls everything back.
            $was_resolved = ($complaint['status'] === 'Resolved');
            $closing = ($new_status === 'Resolved' && !$was_resolved);

            if ($closing) {
                $conn->begin_transaction();
            }

            $ok = mysqli_query($conn, "
                UPDATE complaints 
                SET status='$new_status',
                    solution='$solution'
                    " . ($new_status == 'Resolved' ? ", resolved_at=IF(status='Resolved' AND resolved_at IS NOT NULL, resolved_at, NOW())" : "") . "
                WHERE complaint_id=$complaint_id
                AND assigned_staff=$staff_id
            ");

            if ($ok) {
                $ok2 = record_status_event($conn, $complaint_id, $closing ? 'closed' : 'status_changed',
                    $new_status, $closing ? 'Resolution: ' . $solution : null, $staff_id, 'staff');

                if ($closing && $ok2) {
                    $title = "Complaint #$complaint_id closed";
                    $body = closure_notification_message($complaint_id, $solution);
                    if (!notify_user($conn, (int) $complaint['user_id'], $complaint_id, 'closed', $title, $body)) {
                        $ok2 = false; // roll back: closed status without notification is the original bug
                    }
                }

                if ($closing) {
                    if ($ok2) {
                        $conn->commit();
                    } else {
                        $conn->rollback();
                        $error = "The complaint could not be closed (no changes were made). Please try again.";
                    }
                }
                if ($error === '') {
                    $message = $closing
                        ? "Complaint closed and the student has been notified."
                        : "Updated successfully!";
                }
            } else {
                if ($closing) {
                    $conn->rollback();
                }
                $error = mysqli_error($conn);
            }
        }
    }

    if (isset($_POST['add_reply'])) {

        $reply = trim($_POST['reply']);

        if ($reply == '') {
            $error = "Reply cannot be empty";
        } else {

            $reply_esc = mysqli_real_escape_string($conn, $reply);
            $receiver_id = $complaint['user_id'];

            mysqli_query($conn, "
                INSERT INTO replies
                (complaint_id, replied_by, receiver_id, message, is_read, reply_date)
                VALUES
                ($complaint_id, $staff_id, $receiver_id, '$reply_esc', 0, NOW())
            ")
            ? $message = "Reply sent!"
            : $error = mysqli_error($conn);
        }
    }

    $result = mysqli_query($conn, $query);
    $complaint = mysqli_fetch_assoc($result);
}

$replies = mysqli_fetch_all(mysqli_query($conn, "
    SELECT r.*, u.name as staff_name
    FROM replies r
    JOIN users u ON r.replied_by = u.id
    WHERE r.complaint_id = $complaint_id
    ORDER BY r.reply_date ASC
"), MYSQLI_ASSOC);

/* Evidence files (Feature 1). The fetch above already proved this complaint
   is assigned to this staff member, and can_view_evidence() re-checks the
   assignment server-side before any attachment metadata is read - so this
   page can never list files belonging to complaints outside this staff
   member's permissions. Downloading always goes through the authenticated
   endpoint ../user/view_attachment.php, which checks again per request. */
$attachments = [];
if (can_view_evidence($conn, $complaint_id, $staff_id, 'staff')) {
    $att_stmt = mysqli_prepare($conn,
        "SELECT attachment_id, original_name, file_size, mime_type
         FROM complaint_attachments
         WHERE complaint_id = ?
         ORDER BY attachment_id ASC"
    );
    if ($att_stmt) {
        mysqli_stmt_bind_param($att_stmt, 'i', $complaint_id);
        mysqli_stmt_execute($att_stmt);
        $attachments = mysqli_fetch_all(mysqli_stmt_get_result($att_stmt), MYSQLI_ASSOC) ?: [];
        mysqli_stmt_close($att_stmt);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resolve Complaint</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

<style>
body{
    margin:0;
    font-family:Segoe UI;
    background: linear-gradient(135deg,#1e3c72,#2a5298);
}

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

.sidebar a{
    display:block;
    color:white;
    padding:12px 18px;
    text-decoration:none;
    transition:0.3s;
}

.sidebar a:hover{
    background:rgba(255,255,255,0.15);
}

/* MAIN */
.main{
    margin-left:260px;
    padding:25px;
    color:white;
}

/* BOX */
.box{
    background:white;
    color:black;
    padding:15px;
    border-radius:12px;
    margin-bottom:15px;
    box-shadow:0 8px 20px rgba(0,0,0,0.2);
}

/* CHAT */
.chat{
    max-height:300px;
    overflow-y:auto;
}

.msg{
    padding:10px;
    margin-bottom:8px;
    border-radius:8px;
}

.other{background:#f1f1f1;}
.me{background:#d1e7ff; text-align:right;}
</style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <h4>STAFF PANEL</h4>
 <a href="staff_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
    <a href="logout.php" >
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

<!-- MAIN -->
<div class="main">

<h3>Resolve Complaint #<?php echo $complaint_id; ?></h3>

<?php if($message): ?>
<div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<!-- INFO -->
<div class="box">
    <h5><?php echo $complaint['subject']; ?></h5>
    <p><?php echo $complaint['message']; ?></p>
    <b>User:</b> <?php echo $complaint['user_name']; ?>
</div>

<!-- EVIDENCE (Feature 1 - same data and endpoint as Admin/User dashboards) -->
<div class="box">
    <h6 class="mb-3"><i class="fas fa-paperclip"></i> Evidence Files (<?php echo count($attachments); ?>)</h6>

    <?php if (count($attachments) > 0): ?>
        <ul class="list-group mb-0">
            <?php foreach ($attachments as $att): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <i class="fas fa-file me-2"></i><?php echo htmlspecialchars($att['original_name']); ?>
                    <small class="text-muted">(<?php echo round($att['file_size'] / 1024, 1); ?> KB, <?php echo htmlspecialchars($att['mime_type']); ?>)</small>
                </span>
                <span class="d-inline-flex gap-2">
                    <a class="btn btn-sm btn-outline-primary"
                       href="../user/view_attachment.php?id=<?php echo (int) $att['attachment_id']; ?>"
                       target="_blank" rel="noopener">
                        <i class="fas fa-eye"></i> View Evidence
                    </a>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="../user/view_attachment.php?id=<?php echo (int) $att['attachment_id']; ?>&amp;download=1">
                        <i class="fas fa-download"></i> Download
                    </a>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted mb-0">No evidence attached.</p>
    <?php endif; ?>
</div>

<!-- STATUS -->
<div class="box">
<form method="POST">

    <label>Status</label>
    <select name="status" class="form-control mb-2">
        <option <?php if($complaint['status']=='Pending') echo 'selected'; ?>>Pending</option>
        <option <?php if($complaint['status']=='In Progress') echo 'selected'; ?>>In Progress</option>
        <option <?php if($complaint['status']=='Resolved') echo 'selected'; ?>>Resolved</option>
    </select>

    <label>Solution</label>
    <textarea name="solution" class="form-control mb-2"><?php echo $complaint['solution']; ?></textarea>

    <button name="update" class="btn btn-success">Update</button>
</form>
</div>

<!-- CHAT -->
<div class="box chat">

<?php foreach($replies as $r): ?>
    <div class="msg other">
        <b><?php echo $r['staff_name']; ?></b><br>
        <?php echo $r['message']; ?><br>
        <small><?php echo $r['reply_date']; ?></small>
    </div>
<?php endforeach; ?>

</div>

<!-- REPLY -->
<div class="box">
<form method="POST">
    <textarea name="reply" class="form-control mb-2" placeholder="Write reply..."></textarea>
    <button name="add_reply" class="btn btn-primary">Send Reply</button>
</form>
</div>

</div>

</body>
</html>
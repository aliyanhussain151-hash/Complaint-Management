<?php
session_start();
include '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['user', 'staff'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['complaint_id']) ? intval($_POST['complaint_id']) : 0);
$error = '';
$success = '';

if (!$complaint_id) {
    $error = "Complaint ID is required.";
}

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message']) && $complaint_id) {
    $message = trim($_POST['message']);
    if (empty($message)) {
        $error = "Reply message cannot be empty.";
    } else {
        $message_db = mysqli_real_escape_string($conn, $message);
        $insert_query = "INSERT INTO replies (complaint_id, message, replied_by, reply_date) VALUES ($complaint_id, '$message_db', $user_id, NOW())";
        if (mysqli_query($conn, $insert_query)) {
            $success = "Reply submitted successfully!";
        } else {
            $error = "Failed to submit reply: " . mysqli_error($conn);
        }
    }
}

// Fetch complaint details for display
$complaint = null;
if ($complaint_id) {
    $complaint_query = "SELECT * FROM complaints WHERE complaint_id = $complaint_id";
    $result = mysqli_query($conn, $complaint_query);
    if ($result && mysqli_num_rows($result) > 0) {
        $complaint = mysqli_fetch_assoc($result);
    }
}

// Fetch all replies for this complaint
$replies = [];
if ($complaint_id) {
    $reply_query = "SELECT r.*, u.name as replied_by_name, u.role as replied_by_role
                    FROM replies r
                    JOIN users u ON r.replied_by = u.id
                    WHERE r.complaint_id = $complaint_id
                    ORDER BY r.reply_date ASC";
    $replies_result = mysqli_query($conn, $reply_query);
    if ($replies_result) {
        $replies = mysqli_fetch_all($replies_result, MYSQLI_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reply to Complaint - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-5">
    <h2 class="mb-4">Reply to Complaint</h2>
    <?php if ($complaint): ?>
        <div class="card mb-3">
            <div class="card-header">
                Complaint #<?php echo $complaint['complaint_id']; ?> - <?php echo htmlspecialchars($complaint['subject']); ?>
            </div>
            <div class="card-body">
                <p><strong>Category:</strong> <?php echo htmlspecialchars($complaint['category']); ?></p>
                <p><strong>Message:</strong> <?php echo nl2br(htmlspecialchars($complaint['message'])); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($complaint['status']); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($complaint): ?>
        <form method="POST" class="mb-4">
            <input type="hidden" name="complaint_id" value="<?php echo $complaint_id; ?>">
            <div class="mb-3">
                <label for="message" class="form-label"><strong>Your Reply</strong></label>
                <textarea class="form-control" id="message" name="message" rows="4" required><?= isset($_POST['message']) ? htmlspecialchars($_POST['message']) : '' ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Post Reply</button>
            <a href="javascript:history.back();" class="btn btn-secondary">Back</a>
        </form>
    <?php endif; ?>

    <h4>Previous Replies</h4>
    <?php if (!empty($replies)): ?>
        <div class="list-group mb-4">
            <?php foreach ($replies as $reply): ?>
                <div class="list-group-item">
                    <div><strong><?php echo htmlspecialchars($reply['replied_by_name']); ?> (<?php echo ucfirst($reply['replied_by_role']); ?>)</strong>
                        <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($reply['reply_date'])); ?></small>
                    </div>
                    <div><?php echo nl2br(htmlspecialchars($reply['message'])); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-muted">No replies yet.</p>
    <?php endif; ?>
</div>
</body>
</html>
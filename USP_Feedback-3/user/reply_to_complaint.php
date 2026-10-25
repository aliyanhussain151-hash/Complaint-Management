<?php
session_start();
include '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['user', 'staff'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$error = '';
$success = '';

/* SEND MESSAGE */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message']) && $complaint_id) {

    $message = trim($_POST['message']);

    if (!empty($message)) {

        $msg = mysqli_real_escape_string($conn, $message);

        mysqli_query($conn, "
            INSERT INTO replies (complaint_id, message, replied_by, reply_date, is_read)
            VALUES ($complaint_id, '$msg', $user_id, NOW(), 0)
        ");

        $success = "Message sent!";
    } else {
        $error = "Message required";
    }
}

/* FETCH COMPLAINT */
$complaint = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT * FROM complaints WHERE complaint_id=$complaint_id
"));

/* MARK AS READ */
mysqli_query($conn, "
    UPDATE replies 
    SET is_read=1 
    WHERE complaint_id=$complaint_id 
    AND replied_by != $user_id
");

/* GET MESSAGES */
$replies = mysqli_fetch_all(mysqli_query($conn, "
    SELECT r.*, u.name, u.role
    FROM replies r
    JOIN users u ON r.replied_by=u.id
    WHERE r.complaint_id=$complaint_id
    ORDER BY r.reply_date ASC
"), MYSQLI_ASSOC);

/* UNREAD COUNT */
$unread = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) as total
    FROM replies
    WHERE is_read=0 AND replied_by != $user_id
"))['total'];
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Messages</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

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

/* CHAT */
.chat-box{
    background:#fff;
    padding:15px;
    border-radius:10px;
    height:400px;
    overflow-y:auto;
    border:1px solid #ddd;
}

.msg{
    padding:10px;
    margin-bottom:10px;
    border-radius:8px;
}

.me{
    background:#d1e7ff;
    text-align:right;
}

.other{
    background:#f1f1f1;
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
    <div>
        <b>Messages</b>
        <?php if($unread>0): ?>
            <span class="badge bg-danger"><?php echo $unread; ?></span>
        <?php endif; ?>
    </div>

    <div>
        <i class="fas fa-user"></i> <?php echo $_SESSION['user_name']; ?>
    </div>
</div>

<!-- MAIN -->
<div class="main-content">

<?php if($unread>0): ?>
<script>
alert("You have <?php echo $unread; ?> new message(s)");
</script>
<?php endif; ?>

<!-- COMPLAINT -->
<?php if($complaint): ?>
<div class="card mb-3">
    <div class="card-header bg-primary text-white">
        #<?php echo $complaint['complaint_id']; ?> - <?php echo $complaint['subject']; ?>
    </div>
    <div class="card-body">
        <?php echo $complaint['message']; ?>
    </div>
</div>
<?php endif; ?>

<!-- CHAT -->
<div class="chat-box mb-3">

<?php foreach($replies as $r): ?>
    <div class="msg <?php echo ($r['replied_by']==$user_id)?'me':'other'; ?>">
        <b><?php echo $r['name']; ?> (<?php echo $r['role']; ?>)</b><br>
        <?php echo $r['message']; ?><br>
        <small><?php echo $r['reply_date']; ?></small>
    </div>
<?php endforeach; ?>

</div>

<!-- FORM -->
<form method="POST">
    <textarea name="message" class="form-control mb-2" placeholder="Type message..." required></textarea>
    <button class="btn btn-success">Send</button>
</form>

</div>

</body>
</html>
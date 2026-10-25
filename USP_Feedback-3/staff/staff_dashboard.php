<?php
session_start();
include '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'staff') {
    header('Location: staff_login.php');
    exit();
}

$staff_id = intval($_SESSION['user_id']);

/* =========================
   UPDATE + REDIRECT (FIXED)
========================= */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_id'])) {

    $id = intval($_POST['mark_id']);

    // STEP 1: UPDATE DATABASE FIRST
    $update = mysqli_query($conn,
        "UPDATE replies SET is_read = 1 WHERE complaint_id = $id"
    );

    // STEP 2: CHECK IF SUCCESS THEN REDIRECT
    if ($update) {
        header("Location: resolve_complaint.php?id=$id");
        exit();
    } else {
        echo "Database update failed";
        exit();
    }
}

/* =========================
   STATS
========================= */
$total_assigned = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as count FROM complaints WHERE assigned_staff = $staff_id"
))['count'] ?? 0;

$pending = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as count FROM complaints WHERE assigned_staff = $staff_id AND status = 'Pending'"
))['count'] ?? 0;

$in_progress = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as count FROM complaints WHERE assigned_staff = $staff_id AND status = 'In Progress'"
))['count'] ?? 0;

$resolved = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as count FROM complaints WHERE assigned_staff = $staff_id AND status = 'Resolved'"
))['count'] ?? 0;

/* =========================
   DATA
========================= */
$complaints = mysqli_fetch_all(mysqli_query($conn, "
    SELECT c.*, u.name as user_name
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    WHERE c.assigned_staff = $staff_id
    ORDER BY c.created_at DESC
"), MYSQLI_ASSOC) ?? [];

/* Evidence counts (Feature 1) - only for complaints assigned to THIS staff
   member, so nothing outside their permissions is ever revealed. */
$evidence_counts = [];
$ev_result = mysqli_query($conn, "
    SELECT a.complaint_id, COUNT(*) AS total
    FROM complaint_attachments a
    JOIN complaints c ON c.complaint_id = a.complaint_id
    WHERE c.assigned_staff = $staff_id
    GROUP BY a.complaint_id
");
if ($ev_result) {
    while ($ev_row = mysqli_fetch_assoc($ev_result)) {
        $evidence_counts[(int) $ev_row['complaint_id']] = (int) $ev_row['total'];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Staff Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

<style>
body{
    margin:0;
    font-family:Segoe UI;
    background: linear-gradient(135deg,#1e3c72,#2a5298);
}

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
}

.sidebar a:hover{
    background:rgba(255,255,255,0.15);
}

.main{
    margin-left:260px;
    padding:25px;
    color:white;
}

.card-box{
    padding:20px;
    border-radius:15px;
    color:white;
    text-align:center;
    box-shadow:0 8px 20px rgba(0,0,0,0.2);
}

.total{background:linear-gradient(135deg,#ff9a9e,#fad0c4);}
.pending{background:linear-gradient(135deg,#f6d365,#fda085);}
.inprogress{background:linear-gradient(135deg,#4facfe,#00f2fe);}
.resolved{background:linear-gradient(135deg,#43e97b,#38f9d7);}

.number{
    font-size:28px;
    font-weight:bold;
}

.table-box{
    background:white;
    padding:15px;
    border-radius:12px;
    color:black;
}

.table thead{
    background:#667eea;
    color:white;
}

.btn-action{
    background:#667eea;
    color:white;
}
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
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<!-- MAIN -->
<div class="main">

<h2>Welcome, <?php echo $_SESSION['user_name']; ?> 👋</h2>

<!-- STATS -->
<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="card-box total">
            <div>Total</div>
            <div class="number"><?php echo $total_assigned; ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-box pending">
            <div>Pending</div>
            <div class="number"><?php echo $pending; ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-box inprogress">
            <div>In Progress</div>
            <div class="number"><?php echo $in_progress; ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-box resolved">
            <div>Resolved</div>
            <div class="number"><?php echo $resolved; ?></div>
        </div>
    </div>

</div>

<!-- TABLE -->
<div class="table-box">

<h5>Assigned Complaints</h5>

<table class="table table-hover">
    <thead>
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Evidence</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach($complaints as $c): ?>

        <?php
        $complaint_id = intval($c['complaint_id']);

        $count_result = mysqli_query($conn,
            "SELECT COUNT(*) AS total
             FROM replies
             WHERE complaint_id = $complaint_id
             AND is_read = 0
             AND replied_by != $staff_id"
        );

        $unread = ($count_result) ? (mysqli_fetch_assoc($count_result)['total'] ?? 0) : 0;
        ?>

        <tr>
            <td>#<?php echo $c['complaint_id']; ?></td>
            <td><?php echo $c['user_name']; ?></td>
            <td><?php echo $c['subject']; ?></td>
            <td><?php echo $c['status']; ?></td>
            <td>
            <?php $ev_total = $evidence_counts[$complaint_id] ?? 0; ?>
            <?php if ($ev_total > 0): ?>
                <a href="resolve_complaint.php?id=<?php echo $complaint_id; ?>"
                   class="btn btn-sm btn-outline-primary"
                   title="View evidence for this complaint">
                    <i class="fas fa-paperclip"></i> View Evidence (<?php echo $ev_total; ?>)
                </a>
            <?php else: ?>
                <span class="text-muted small"><i class="fas fa-paperclip"></i> No evidence</span>
            <?php endif; ?>
            </td>
            <td>

            <form method="POST" style="display:inline;">
                <input type="hidden" name="mark_id" value="<?php echo $c['complaint_id']; ?>">

                <button type="submit" class="btn btn-sm btn-action">
                    Message

                    <?php if($unread > 0): ?>
                        <span class="badge bg-danger">
                            <?php echo $unread; ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-success">0</span>
                    <?php endif; ?>

                </button>
            </form>

            </td>
        </tr>

        <?php endforeach; ?>
    </tbody>

</table>

</div>

</div>

</body>
</html>
<?php
session_start();
include '../config/db_connect.php';
include_once '../includes/common_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Feature 2: fetch this student's in-app notifications and unread count.
$notifications = get_user_notifications($conn, (int) $user_id, 10);
$unread_notifications = count_unread_notifications($conn, (int) $user_id);

// Fetch user complaints
$query = "SELECT * FROM complaints WHERE user_id = $user_id ORDER BY created_at DESC";
$result = mysqli_query($conn, $query);
$complaints = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Count complaints
$pending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM complaints WHERE user_id = $user_id AND status = 'Pending'"))['count'];
$in_progress = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM complaints WHERE user_id = $user_id AND status = 'In Progress'"))['count'];
$resolved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM complaints WHERE user_id = $user_id AND status = 'Resolved'"))['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Dashboard</title>

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

/* MAIN CONTENT */
.main-content {
    margin-left: 250px;
    padding: 20px;
}

/* TOP BAR */
.topbar {
    background: white;
    padding: 15px;
    margin-left: 250px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-box {
    border-radius: 10px;
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

<!-- TOP BAR -->
<div class="topbar">
    <h5>Dashboard</h5>
    <div>
        <i class="fas fa-user"></i>
        <?php echo $_SESSION['user_name']; ?>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">

<h2 class="mb-4">Welcome, <?php echo $_SESSION['user_name']; ?></h2>

<!-- STATS -->
<div class="row mb-4">

    <div class="col-md-3">
        <div class="card bg-warning text-white p-3">
            <h5>Pending</h5>
            <h2><?php echo $pending; ?></h2>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card bg-info text-white p-3">
            <h5>In Progress</h5>
            <h2><?php echo $in_progress; ?></h2>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card bg-success text-white p-3">
            <h5>Resolved</h5>
            <h2><?php echo $resolved; ?></h2>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card bg-primary text-white p-3">
            <h5>Total</h5>
            <h2><?php echo count($complaints); ?></h2>
        </div>
    </div>

</div>

<!-- NOTIFICATIONS (Feature 2) -->
<div class="card mb-3">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="fas fa-bell"></i> Notifications
            <?php if ($unread_notifications > 0): ?>
                <span class="badge bg-danger"><?php echo $unread_notifications; ?> new</span>
            <?php endif; ?>
        </span>
        <?php if ($unread_notifications > 0): ?>
            <form method="post" action="mark_notifications_read.php" class="m-0">
                <button type="submit" class="btn btn-sm btn-light">Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (count($notifications) > 0): ?>
            <ul class="list-group">
                <?php foreach ($notifications as $n): ?>
                    <li class="list-group-item <?php echo $n['is_read'] ? '' : 'list-group-item-info'; ?>">
                        <div class="d-flex justify-content-between">
                            <strong><?php echo htmlspecialchars($n['title']); ?></strong>
                            <small class="text-muted"><?php echo htmlspecialchars($n['created_at']); ?></small>
                        </div>
                        <div><?php echo htmlspecialchars($n['message']); ?></div>
                        <?php if ($n['complaint_id']): ?>
                            <a href="complaint_status.php?id=<?php echo (int) $n['complaint_id']; ?>" class="small">
                                View complaint #<?php echo (int) $n['complaint_id']; ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted mb-0">No notifications yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- BUTTONS -->
<div class="mb-3">
    <a href="submit_complaint.php" class="btn btn-primary">Submit Complaint</a>
    <a href="complaint_status.php" class="btn btn-info">View All</a>
</div>

<!-- TABLE -->
<div class="card">
    <div class="card-header bg-primary text-white">
        Recent Complaints
    </div>

    <div class="card-body table-responsive">

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

            <?php if(count($complaints) > 0): ?>
                <?php foreach(array_slice($complaints, 0, 5) as $c): ?>
                    <tr>
                        <td>#<?php echo $c['complaint_id']; ?></td>
                        <td><?php echo $c['subject']; ?></td>
                        <td><?php echo $c['category']; ?></td>
                        <td>
                            <span class="badge bg-primary">
                                <?php echo $c['status']; ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($c['created_at'])); ?></td>
                        <td>
                            <a href="complaint_status.php?id=<?php echo $c['complaint_id']; ?>" class="btn btn-sm btn-primary">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center">No complaints found</td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>

    </div>
</div>

</div>

</body>
</html>
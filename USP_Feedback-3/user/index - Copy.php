<?php
session_start();
include '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user complaints
$query = "SELECT * FROM complaints WHERE user_id = $user_id ORDER BY created_at DESC";
$result = mysqli_query($conn, $query);
$complaints = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Count complaints by status
$pending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM complaints WHERE user_id = $user_id AND status = 'Pending'"))['count'];
$in_progress = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM complaints WHERE user_id = $user_id AND status = 'In Progress'"))['count'];
$resolved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM complaints WHERE user_id = $user_id AND status = 'Resolved'"))['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container-fluid mt-5">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <div class="col-md-9">
                <h2 class="mb-4">Welcome, <?php echo $_SESSION['user_name']; ?></h2>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Pending</h5>
                                <p class="card-text display-6"><?php echo $pending; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">In Progress</h5>
                                <p class="card-text display-6"><?php echo $in_progress; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Resolved</h5>
                                <p class="card-text display-6"><?php echo $resolved; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total</h5>
                                <p class="card-text display-6"><?php echo count($complaints); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="mb-4">
                    <a href="submit_complaint.php" class="btn btn-primary">Submit New Complaint</a>
                    <a href="complaint_status.php" class="btn btn-info">View All Complaints</a>
                </div>
                
                <!-- Recent Complaints Table -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Recent Complaints</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Complaint ID</th>
                                        <th>Subject</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($complaints) > 0): ?>
                                        <?php foreach (array_slice($complaints, 0, 5) as $complaint): ?>
                                            <tr>
                                                <td>#<?php echo $complaint['complaint_id']; ?></td>
                                                <td><?php echo $complaint['subject']; ?></td>
                                                <td><?php echo $complaint['category']; ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($complaint['status'] == 'Pending' ? 'warning' : ($complaint['status'] == 'In Progress' ? 'info' : 'success')); ?>">
                                                        <?php echo $complaint['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d', strtotime($complaint['created_at'])); ?></td>
                                                <td>
                                                    <a href="complaint_status.php?id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-sm btn-primary">View</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No complaints yet</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
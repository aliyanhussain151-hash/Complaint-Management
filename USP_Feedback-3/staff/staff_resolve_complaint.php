<?php
session_start();
include '../config/db_connect.php';

// Authentication and role check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'staff') {
    header('Location: staff_login.php');
    exit();
}

$staff_id = $_SESSION['user_id'];

// Fetch assigned complaints for this staff
$complaints_query = "
    SELECT c.complaint_id, c.subject, c.category, c.status, c.created_at, c.message,
           u.name AS user_name, u.email AS user_email
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    WHERE c.assigned_staff = $staff_id
    ORDER BY c.created_at DESC
";
$complaints = mysqli_fetch_all(mysqli_query($conn, $complaints_query), MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>My Complaints - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* You can add more style here if needed */
        .main-content { padding: 2rem; }
    </style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="staff_dashboard.php"><i class="fas fa-user-tie"></i> Staff Portal</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="staff_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="resolve_complaint.php">My Tasks</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="staff_profile.php">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                    <!-- Links to other app areas -->
                    <li class="nav-item">
                        <a class="nav-link" href="../index.php">Home</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <!-- Sidebar (optional: add if desired, skip if not using in staff area) -->
    
    <div class="container main-content">
        <h2 class="fw-bold mb-4">My Assigned Complaints</h2>
        <!-- Navigation links to other files in the app -->
        <div class="mb-3">
            <a href="staff_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home"></i> Dashboard</a>
            <a href="resolve_complaint.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-tasks"></i> My Tasks</a>
            <a href="staff_profile.php" class="btn btn-outline-info btn-sm"><i class="fas fa-user"></i> Profile</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <a href="../index.php" class="btn btn-outline-dark btn-sm"><i class="fas fa-angle-double-left"></i> Back to Home</a>
        </div>
        <!-- Complaints List -->
        <?php if (count($complaints) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>User</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Resolve/Reply</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $complaint): ?>
                        <tr>
                            <td>#<?php echo $complaint['complaint_id']; ?></td>
                            <td><?php echo htmlspecialchars($complaint['subject']); ?></td>
                            <td><?php echo htmlspecialchars($complaint['category']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($complaint['user_name']); ?><br>
                                <small><?php echo htmlspecialchars($complaint['user_email']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?php
                                    switch ($complaint['status']) {
                                        case 'Pending': echo 'warning'; break;
                                        case 'In Progress': echo 'info'; break;
                                        case 'Resolved': echo 'success'; break;
                                        default: echo 'secondary';
                                    }
                                ?>"><?php echo htmlspecialchars($complaint['status']); ?></span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></td>
                            <td>
                                <a href="resolve_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Resolve
                                </a>
                                <!-- 
                                    Optionally, you may include links/buttons for adding replies, viewing details, etc.
                                    Example (if implemented): 
                                    <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-outline-info btn-sm">View</a>
                                -->
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No complaints assigned to you yet.</div>
        <?php endif; ?>
        <!-- Links to other staff features -->
        <div class="mt-4">
            <strong>Other Pages:</strong>
            <ul>
                <li><a href="staff_dashboard.php">Staff Dashboard</a></li>
                <li><a href="staff_profile.php">Staff Profile</a></li>
                <li><a href="../logout.php">Logout (global)</a></li>
                <li><a href="../admin/admin_login.php">Go to Admin Login</a></li>
                <li><a href="../user/login.php">Go to User Login</a></li>
            </ul>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
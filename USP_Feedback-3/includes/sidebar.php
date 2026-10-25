<?php
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
?>

<div class="col-md-3 mb-4">
    <div class="list-group sticky-top" style="top: 20px;">
        <?php if ($role == 'user'): ?>
            <a href="index.php" class="list-group-item list-group-item-action active">Dashboard</a>
            <a href="submit_complaint.php" class="list-group-item list-group-item-action">Submit Complaint</a>
            <a href="complaint_status.php" class="list-group-item list-group-item-action">My Complaints</a>
            <a href="profile.php" class="list-group-item list-group-item-action">Profile</a>
        <?php elseif ($role == 'admin'): ?>
            <a href="admin_dashboard.php" class="list-group-item list-group-item-action active">Dashboard</a>
            <a href="manage_complaints.php" class="list-group-item list-group-item-action">All Complaints</a>
            <a href="assign_staff.php" class="list-group-item list-group-item-action">Assign Staff</a>
            <a href="reports.php" class="list-group-item list-group-item-action">Reports</a>
        <?php elseif ($role == 'staff'): ?>
            <a href="staff_dashboard.php" class="list-group-item list-group-item-action active">Dashboard</a>
            <a href="resolve_complaint.php" class="list-group-item list-group-item-action">My Tasks</a>
        <?php endif; ?>
    </div>
</div>
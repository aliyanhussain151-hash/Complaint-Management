<?php
session_start();
include '../config/db_connect.php';
include_once '../includes/common_helpers.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle bulk assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action == 'assign_complaints') {
        $complaint_ids = isset($_POST['complaint_ids']) ? array_map('intval', (array)$_POST['complaint_ids']) : [];
        $staff_id = intval($_POST['staff_id']);
        
        if (empty($complaint_ids)) {
            $error_message = 'Please select at least one complaint';
        } elseif ($staff_id <= 0) {
            $error_message = 'Please select a staff member';
        } else {
            $ids_str = implode(',', $complaint_ids);
            $update_query = "UPDATE complaints SET assigned_staff = $staff_id WHERE complaint_id IN ($ids_str) AND assigned_staff IS NULL";
            
            if (mysqli_query($conn, $update_query)) {
                $affected_rows = mysqli_affected_rows($conn);
                $success_message = "Successfully assigned $affected_rows complaint(s) to staff member";
                
                // Log the action
                $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                            VALUES ($admin_id, 'ASSIGN_COMPLAINTS', 'Assigned $affected_rows complaints to staff member')";
                mysqli_query($conn, $log_query);

                // Feature 3: timeline entry for each complaint that was actually assigned
                $staff_name_row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT name FROM users WHERE id = $staff_id"));
                $assigned_q = mysqli_query($conn,
                    "SELECT complaint_id FROM complaints WHERE complaint_id IN ($ids_str) AND assigned_staff = $staff_id");
                if ($assigned_q) {
                    foreach (mysqli_fetch_all($assigned_q, MYSQLI_ASSOC) as $row) {
                        record_status_event($conn, (int) $row['complaint_id'], 'assigned', null,
                            'Assigned to ' . ($staff_name_row['name'] ?? "staff #$staff_id"), $admin_id, 'admin');
                    }
                }
            } else {
                $error_message = 'Error assigning complaints: ' . mysqli_error($conn);
            }
        }
    } elseif ($action == 'reassign_complaint') {
        $complaint_id = intval($_POST['complaint_id']);
        $staff_id = intval($_POST['staff_id']);
        
        if ($staff_id <= 0) {
            $error_message = 'Please select a staff member';
        } else {
            // Feature 3: remember the previous assignee so the timeline event is honest
            $prev_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT assigned_staff FROM complaints WHERE complaint_id = $complaint_id"));
            $was_assigned = $prev_row && $prev_row['assigned_staff'] !== null;

            $update_query = "UPDATE complaints SET assigned_staff = $staff_id WHERE complaint_id = $complaint_id";
            
            if (mysqli_query($conn, $update_query)) {
                $success_message = 'Complaint reassigned successfully';
                
                // Log the action
                $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                            VALUES ($admin_id, 'REASSIGN_COMPLAINT', 'Reassigned complaint #$complaint_id to different staff')";
                mysqli_query($conn, $log_query);

                // Feature 3: timeline entry
                $staff_name_row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT name FROM users WHERE id = $staff_id"));
                record_status_event($conn, $complaint_id, $was_assigned ? 'reassigned' : 'assigned', null,
                    ($was_assigned ? 'Reassigned to ' : 'Assigned to ')
                        . ($staff_name_row['name'] ?? "staff #$staff_id"), $admin_id, 'admin');
            } else {
                $error_message = 'Error reassigning complaint: ' . mysqli_error($conn);
            }
        }
    } elseif ($action == 'remove_assignment') {
        $complaint_id = intval($_POST['complaint_id']);
        $update_query = "UPDATE complaints SET assigned_staff = NULL WHERE complaint_id = $complaint_id";
        
        if (mysqli_query($conn, $update_query)) {
            $success_message = 'Assignment removed successfully';
            
            // Log the action
            $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                        VALUES ($admin_id, 'REMOVE_ASSIGNMENT', 'Removed assignment for complaint #$complaint_id')";
            mysqli_query($conn, $log_query);

            // Feature 3: timeline entry
            record_status_event($conn, $complaint_id, 'unassigned', null, 'Assignment removed', $admin_id, 'admin');
        } else {
            $error_message = 'Error removing assignment: ' . mysqli_error($conn);
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_assignment = $_GET['assignment'] ?? 'all';
$page = $_GET['page'] ?? 1;
$per_page = 10;

// Build query
$where_clauses = [];

if ($filter_status != 'all') {
    $status_escaped = mysqli_real_escape_string($conn, $filter_status);
    $where_clauses[] = "c.status = '$status_escaped'";
}

if ($filter_assignment == 'unassigned') {
    $where_clauses[] = "c.assigned_staff IS NULL";
} elseif ($filter_assignment == 'assigned') {
    $where_clauses[] = "c.assigned_staff IS NOT NULL";
}

$where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM complaints c $where";
$count_result = mysqli_fetch_assoc(mysqli_query($conn, $count_query));
$total_records = $count_result['total'];
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// Get unassigned complaints
$complaints_query = "
    SELECT c.complaint_id, c.subject, c.status, c.created_at, c.category, 
           u.name as user_name, u.email as user_email,
           CASE WHEN c.assigned_staff IS NOT NULL THEN (SELECT name FROM users WHERE id = c.assigned_staff) ELSE NULL END as staff_name
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    $where
    ORDER BY c.created_at DESC
    LIMIT $offset, $per_page
";
$complaints = mysqli_fetch_all(mysqli_query($conn, $complaints_query), MYSQLI_ASSOC);

// Get all active staff members
$staff_query = "SELECT id, name, email FROM users WHERE role = 'staff' AND status = 'active' ORDER BY name";
$staff_members = mysqli_fetch_all(mysqli_query($conn, $staff_query), MYSQLI_ASSOC);

// Get staff workload
$workload_query = "
    SELECT s.id, s.name, 
           COUNT(c.complaint_id) as total_assigned,
           COUNT(CASE WHEN c.status = 'Pending' THEN 1 END) as pending,
           COUNT(CASE WHEN c.status = 'In Progress' THEN 1 END) as in_progress,
           COUNT(CASE WHEN c.status = 'Resolved' THEN 1 END) as resolved
    FROM users s
    LEFT JOIN complaints c ON s.id = c.assigned_staff
    WHERE s.role = 'staff' AND s.status = 'active'
    GROUP BY s.id
    ORDER BY s.name
";
$staff_workload = mysqli_fetch_all(mysqli_query($conn, $workload_query), MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Staff - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #43e97b;
            --warning: #f5576c;
            --info: #4facfe;
            --light: #f8f9fa;
            --dark: #2d3748;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Sidebar */
        .sidebar {
            background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            padding: 2rem 0;
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 1.5rem;
            text-align: center;
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 1.5rem;
        }

        .sidebar-brand i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
            border-left: 4px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .nav-link:hover,
        .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.15);
            border-left-color: #43e97b;
        }

        /* Navbar */
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            padding: 1rem 2rem;
            margin-left: 250px;
            position: fixed;
            top: 0;
            right: 0;
            left: 250px;
            z-index: 999;
        }

        /* Main Content */
        .main-content {
            margin-left: 250px;
            margin-top: 80px;
            padding: 2rem;
        }

        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0;
        }

        /* Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
        }

        /* Staff Workload Cards */
        .staff-workload-card {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
        }

        .staff-workload-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .staff-name {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .workload-stat {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.9rem;
        }

        .workload-stat:last-child {
            border-bottom: none;
        }

        .workload-value {
            font-weight: 700;
            color: var(--primary);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .form-control,
        .form-select {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        /* Table Section */
        .table-section {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h5 {
            margin: 0;
            font-weight: 700;
            color: var(--dark);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #f9fafb;
            border-bottom: 2px solid #f0f0f0;
            font-weight: 600;
            color: var(--dark);
            padding: 1rem;
        }

        .table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #f9fafb;
        }

        /* Checkboxes */
        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .form-check-input:checked {
            background: var(--primary);
            border-color: var(--primary);
        }

        /* Status Badge */
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-progress {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-resolved {
            background: #dcfce7;
            color: #166534;
        }

        /* Action Buttons */
        .btn-action {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Alerts */
        .alert {
            border: none;
            border-radius: 10px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left-color: #43e97b;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left-color: #ef4444;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        /* Modal */
        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
            }

            .navbar-custom,
            .main-content {
                margin-left: 0;
            }

            .table-responsive {
                font-size: 0.9rem;
            }
        }
    </style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <i class="fas fa-shield-alt"></i>
            <h4>USP Admin</h4>
        </div>

        <nav class="nav flex-column">
            <a class="nav-link " href="admin_dashboard_professional.php">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a class="nav-link" href="manage_complaints.php">
                <i class="fas fa-tasks"></i> Complaints
            </a>
            <a class="nav-link" href="manage_staff.php">
                <i class="fas fa-users-cog"></i> Manage Staff
            </a>
            <a class="nav-link" href="assign_staff.php">
                <i class="fas fa-user-check"></i> Assign Tasks
            </a>
            <a class="nav-link" href="manage_users.php">
                <i class="fas fa-users"></i> Users
            </a>
            <a class="nav-link" href="reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a class="nav-link" href="activity_logs.php">
                <i class="fas fa-history"></i> Activity Logs
            </a>

            <hr style="border-color: rgba(255, 255, 255, 0.2); margin: 1rem 0;">

            <a class="nav-link" href="admin_profile.php">
                <i class="fas fa-user-circle"></i> My Profile
            </a>
            
            <a class="nav-link" href="logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>

    <!-- Navbar -->
    <nav class="navbar-custom">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <h5 style="margin: 0; color: var(--dark); font-weight: 700;">Assign Complaints to Staff</h5>
            <div>
                <span style="color: #6b7280; margin-right: 1rem;"><?php echo $_SESSION['user_name']; ?></span>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h2>
                <i class="fas fa-user-check"></i> Assign Complaints to Staff
            </h2>
            <p style="color: #6b7280; margin: 0;">Distribute complaints to staff members and manage assignments</p>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Staff Workload Section -->
        <div class="section-card">
            <div class="section-title">
                <i class="fas fa-bar-chart"></i> Staff Workload Overview
            </div>

            <?php if (count($staff_workload) > 0): ?>
                <div class="row g-3">
                    <?php foreach ($staff_workload as $staff): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="staff-workload-card">
                                <div class="staff-name">
                                    <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($staff['name']); ?>
                                </div>
                                <div class="workload-stat">
                                    <span>Total Assigned:</span>
                                    <span class="workload-value"><?php echo $staff['total_assigned']; ?></span>
                                </div>
                                <div class="workload-stat">
                                    <span>Pending:</span>
                                    <span class="workload-value" style="color: #f5576c;"><?php echo $staff['pending']; ?></span>
                                </div>
                                <div class="workload-stat">
                                    <span>In Progress:</span>
                                    <span class="workload-value" style="color: #4facfe;"><?php echo $staff['in_progress']; ?></span>
                                </div>
                                <div class="workload-stat">
                                    <span>Resolved:</span>
                                    <span class="workload-value" style="color: #43e97b;"><?php echo $staff['resolved']; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h4>No Staff Available</h4>
                    <p>Please add staff members first</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm" class="row g-3">
                <!-- Status Filter -->
                <div class="col-md-4">
                    <label class="filter-label">
                        <i class="fas fa-filter"></i> Filter by Status
                    </label>
                    <select class="form-select" name="status" onchange="document.getElementById('filterForm').submit();">
                        <option value="all">All Status</option>
                        <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="In Progress" <?php echo $filter_status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="Resolved" <?php echo $filter_status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                </div>

                <!-- Assignment Filter -->
                <div class="col-md-4">
                    <label class="filter-label">
                        <i class="fas fa-tasks"></i> Assignment Status
                    </label>
                    <select class="form-select" name="assignment" onchange="document.getElementById('filterForm').submit();">
                        <option value="all">All Assignments</option>
                        <option value="unassigned" <?php echo $filter_assignment == 'unassigned' ? 'selected' : ''; ?>>Unassigned Only</option>
                        <option value="assigned" <?php echo $filter_assignment == 'assigned' ? 'selected' : ''; ?>>Already Assigned</option>
                    </select>
                </div>

                <!-- Reset Button -->
                <div class="col-md-4 d-flex align-items-end">
                    <a href="assign_staff.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Reset Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Bulk Assignment Form -->
        <?php if (count($complaints) > 0 && count($staff_members) > 0): ?>
            <form method="POST" id="assignForm">
                <div class="section-card">
                    <div class="section-title">
                        <i class="fas fa-magic"></i> Bulk Assignment
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="filter-label">
                                <i class="fas fa-user-tie"></i> Select Staff Member
                            </label>
                            <select class="form-select" name="staff_id" required>
                                <option value="">-- Select Staff Member --</option>
                                <?php foreach ($staff_members as $staff): ?>
                                    <option value="<?php echo $staff['id']; ?>">
                                        <?php echo htmlspecialchars($staff['name']); ?> (<?php echo htmlspecialchars($staff['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-primary w-100" onclick="selectAllComplaints()">
                                <i class="fas fa-check-square"></i> Select All Shown
                            </button>
                        </div>
                    </div>

                    <input type="hidden" name="action" value="assign_complaints">

                    <div class="alert alert-info" style="background: #dbeafe; border-left: 4px solid #4facfe; color: #1e40af;">
                        <i class="fas fa-info-circle"></i> 
                        Select the staff member and check the complaints you want to assign, then click "Assign Selected"
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <!-- Complaints Table -->
        <div class="table-section">
            <div class="table-header">
                <h5><i class="fas fa-list"></i> Complaints (<?php echo count($complaints); ?> of <?php echo $total_records; ?>)</h5>
                <a href="manage_complaints.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back to Complaints
                </a>
            </div>

            <?php if (count($complaints) > 0): ?>
                <form method="POST" id="complaintForm">
                    <input type="hidden" name="action" value="assign_complaints">
                    <input type="hidden" name="staff_id" id="formStaffId">

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" class="form-check-input" onchange="toggleAllCheckboxes(this)">
                                    </th>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Current Assignment</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaints as $complaint): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input complaint-checkbox" 
                                                   name="complaint_ids[]" value="<?php echo $complaint['complaint_id']; ?>">
                                        </td>
                                        <td>
                                            <strong>#<?php echo $complaint['complaint_id']; ?></strong>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($complaint['user_name']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($complaint['user_email']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(substr($complaint['subject'], 0, 35)); ?>...
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($complaint['category']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = 'badge-' . strtolower($complaint['status']);
                                            ?>
                                            <span class="badge-status <?php echo $status_class; ?>">
                                                <?php echo $complaint['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($complaint['staff_name']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($complaint['staff_name']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-action btn-info" data-bs-toggle="modal" 
                                                        data-bs-target="#reassignModal"
                                                        onclick="setReassignData(<?php echo $complaint['complaint_id']; ?>, '<?php echo htmlspecialchars($complaint['subject']); ?>')">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </button>
                                                <?php if ($complaint['staff_name']): ?>
                                                    <button type="button" class="btn btn-action btn-danger" 
                                                            onclick="removeAssignment(<?php echo $complaint['complaint_id']; ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Assign Button -->
                    <?php if (count($complaints) > 0 && count($staff_members) > 0): ?>
                        <div style="padding: 1.5rem; border-top: 2px solid #f0f0f0; text-align: right;">
                            <button type="button" class="btn btn-primary" onclick="submitBulkAssign()">
                                <i class="fas fa-check"></i> Assign Selected Complaints
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4>No Complaints Found</h4>
                    <p>All complaints are already assigned or no complaints match your filters</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav style="margin-top: 2rem;">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?status=<?php echo $filter_status; ?>&assignment=<?php echo $filter_assignment; ?>&page=1">
                                First
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?status=<?php echo $filter_status; ?>&assignment=<?php echo $filter_assignment; ?>&page=<?php echo $page - 1; ?>">
                                Previous
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?status=<?php echo $filter_status; ?>&assignment=<?php echo $filter_assignment; ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?status=<?php echo $filter_status; ?>&assignment=<?php echo $filter_assignment; ?>&page=<?php echo $page + 1; ?>">
                                Next
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?status=<?php echo $filter_status; ?>&assignment=<?php echo $filter_assignment; ?>&page=<?php echo $total_pages; ?>">
                                Last
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <!-- Reassign Modal -->
    <div class="modal fade" id="reassignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exchange-alt"></i> Reassign Complaint
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p id="complaintSubjectDisplay"></p>
                        
                        <input type="hidden" name="action" value="reassign_complaint">
                        <input type="hidden" name="complaint_id" id="reassignComplaintId">
                        
                        <label class="form-label fw-bold">Assign to Staff Member</label>
                        <select class="form-select" name="staff_id" required>
                            <option value="">-- Select Staff Member --</option>
                            <?php foreach ($staff_members as $staff): ?>
                                <option value="<?php echo $staff['id']; ?>">
                                    <?php echo htmlspecialchars($staff['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Reassign
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Remove Assignment Modal -->
    <div class="modal fade" id="removeAssignmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-trash"></i> Remove Assignment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p>Are you sure you want to remove the assignment for this complaint?</p>
                        
                        <input type="hidden" name="action" value="remove_assignment">
                        <input type="hidden" name="complaint_id" id="removeComplaintId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-check"></i> Remove Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle all checkboxes
        function toggleAllCheckboxes(checkbox) {
            const checkboxes = document.querySelectorAll('.complaint-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }

        // Select all complaints
        function selectAllComplaints() {
            document.querySelectorAll('.complaint-checkbox').forEach(cb => cb.checked = true);
            const staffSelect = document.querySelector('[name="staff_id"]:first-of-type');
            if (!staffSelect.value) {
                alert('Please select a staff member first');
                document.querySelectorAll('.complaint-checkbox').forEach(cb => cb.checked = false);
            }
        }

        // Submit bulk assign
        function submitBulkAssign() {
            const staffId = document.querySelector('[name="staff_id"]:first-of-type').value;
            const checkedComplaints = document.querySelectorAll('.complaint-checkbox:checked');
            
            if (!staffId) {
                alert('Please select a staff member');
                return;
            }
            
            if (checkedComplaints.length === 0) {
                alert('Please select at least one complaint');
                return;
            }
            
            document.getElementById('formStaffId').value = staffId;
            document.getElementById('complaintForm').submit();
        }

        // Set reassign data
        function setReassignData(complaintId, subject) {
            document.getElementById('reassignComplaintId').value = complaintId;
            document.getElementById('complaintSubjectDisplay').textContent = 'Complaint: ' + subject;
        }

        // Remove assignment
        function removeAssignment(complaintId) {
            document.getElementById('removeComplaintId').value = complaintId;
            const modal = new bootstrap.Modal(document.getElementById('removeAssignmentModal'));
            modal.show();
        }

        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>
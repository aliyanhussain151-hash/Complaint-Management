<?php
session_start();
include '../config/db_connect.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Get Dashboard Statistics
$total_complaints = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints"))['count'] ?? 0;

$pending = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints WHERE status = 'Pending'"))['count'] ?? 0;

$in_progress = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints WHERE status = 'In Progress'"))['count'] ?? 0;

$resolved = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints WHERE status = 'Resolved'"))['count'] ?? 0;

$total_users = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM users WHERE role = 'user'"))['count'] ?? 0;

$total_staff = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM users WHERE role = 'staff' AND status = 'active'"))['count'] ?? 0;

// Resolution rate
$resolution_rate = $total_complaints > 0 ? round(($resolved / $total_complaints) * 100, 1) : 0;

// Average resolution time
$avg_resolution = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours 
     FROM complaints WHERE status = 'Resolved' AND resolved_at IS NOT NULL"))['avg_hours'] ?? 0;
$avg_resolution = round($avg_resolution, 1);

// Recent Complaints
$recent_complaints_query = "
    SELECT c.complaint_id, c.subject, c.status, c.created_at, u.name as user_name, 
           CASE WHEN c.assigned_staff IS NOT NULL THEN (SELECT name FROM users WHERE id = c.assigned_staff) ELSE 'Unassigned' END as staff_name
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    ORDER BY c.created_at DESC
    LIMIT 8
";
$recent_complaints = mysqli_fetch_all(mysqli_query($conn, $recent_complaints_query), MYSQLI_ASSOC) ?? [];

// Get all Staff Members
$staff_query = "SELECT id, name, email, status, created_at FROM users WHERE role = 'staff' ORDER BY created_at DESC LIMIT 5";
$staff_members = mysqli_fetch_all(mysqli_query($conn, $staff_query), MYSQLI_ASSOC) ?? [];

// Complaints by Category
$category_query = "
    SELECT category, COUNT(*) as count 
    FROM complaints 
    GROUP BY category 
    ORDER BY count DESC 
    LIMIT 6
";
$categories = mysqli_fetch_all(mysqli_query($conn, $category_query), MYSQLI_ASSOC) ?? [];

// Complaints by Status (for chart)
$status_query = "
    SELECT status, COUNT(*) as count 
    FROM complaints 
    GROUP BY status
";
$status_data = mysqli_fetch_all(mysqli_query($conn, $status_query), MYSQLI_ASSOC) ?? [];

// Recent activity
$activity_query = "
    SELECT * FROM admin_logs 
    WHERE admin_id = $admin_id 
    ORDER BY timestamp DESC 
    LIMIT 5
";
$activities = mysqli_fetch_all(mysqli_query($conn, $activity_query), MYSQLI_ASSOC) ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.css">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--dark);
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

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
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

        .sidebar-brand h4 {
            font-weight: 700;
            margin: 0.5rem 0 0 0;
            font-size: 1.1rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
            border-left: 4px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0.25rem 0;
        }

        .nav-link:hover {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #43e97b;
            padding-left: 1.75rem;
        }

        .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.15);
            border-left-color: #43e97b;
        }

        .nav-link i {
            font-size: 1.1rem;
            width: 1.5rem;
            text-align: center;
        }

        /* Top Navbar */
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
            border-bottom: 2px solid #f0f0f0;
        }

        .navbar-custom .navbar-brand {
            font-weight: 700;
            color: var(--dark);
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            margin-left: 250px;
            margin-top: 80px;
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #6b7280;
            margin-bottom: 0;
        }

        /* Statistics Cards */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-icon.primary { color: var(--primary); }
        .stat-icon.success { color: var(--success); }
        .stat-icon.warning { color: var(--warning); }
        .stat-icon.info { color: var(--info); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .stat-change {
            font-size: 0.85rem;
            margin-top: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: #dcfce7;
            color: #166534;
            border-radius: 6px;
            display: inline;
        }

        /* Data Table */
        .data-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .data-table-header {
            padding: 1.5rem;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .data-table-header h5 {
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

        .table tbody tr:last-child td {
            border-bottom: none;
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

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Buttons */
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

        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: relative;
            height: 300px;
        }

        /* Quick Stats Row */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Activity Section */
        .activity-item {
            padding: 1rem;
            border-left: 4px solid var(--primary);
            background: #f9fafb;
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .activity-item:last-child {
            margin-bottom: 0;
        }

        .activity-time {
            font-size: 0.85rem;
            color: #9ca3af;
        }

        .activity-action {
            font-weight: 600;
            color: var(--dark);
            margin: 0.25rem 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
            }

            .navbar-custom {
                margin-left: 0;
                left: 0;
            }

            .main-content {
                margin-left: 0;
                margin-top: 80px;
            }

            .quick-stats {
                grid-template-columns: 1fr;
            }
        }

        /* Loading Animation */
        .spinner-mini {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Header with Icon */
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .section-header i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .section-header h4 {
            margin: 0;
            font-weight: 700;
            color: var(--dark);
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
            <small style="opacity: 0.8;">Control Panel</small>
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

    <!-- Top Navbar -->
    <nav class="navbar-custom">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <div>
                <h5 style="margin: 0; color: var(--dark); font-weight: 700;">Dashboard</h5>
                <small style="color: #9ca3af;"><?php echo date('l, F j, Y'); ?></small>
            </div>

            <div class="navbar-user">
                <div class="user-profile">
                    <div>
                        <p style="margin: 0; font-weight: 600; color: var(--dark);"><?php echo $_SESSION['user_name']; ?></p>
                        <small style="color: #9ca3af;">Administrator</small>
                    </div>
                    <div class="user-avatar"><?php echo substr($_SESSION['user_name'], 0, 1); ?></div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-chart-line"></i> Dashboard Overview</h2>
            <p>Welcome back! Here's your system performance at a glance.</p>
        </div>

        <!-- Quick Statistics -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-inbox"></i>
                </div>
                <div class="stat-value"><?php echo $total_complaints; ?></div>
                <div class="stat-label">Total Complaints</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> 12% from last month
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-hourglass-start"></i>
                </div>
                <div class="stat-value"><?php echo $pending; ?></div>
                <div class="stat-label">Pending Complaints</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-down"></i> 5% from last week
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="stat-value"><?php echo $in_progress; ?></div>
                <div class="stat-label">In Progress</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> 8% from last week
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $resolved; ?></div>
                <div class="stat-label">Resolved</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> 15% from last week
                </div>
            </div>
        </div>

        <!-- Second Row Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $total_users; ?></div>
                <div class="stat-label">Total Users</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> <?php echo $total_users > 10 ? '+3' : 'New'; ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="stat-value"><?php echo $total_staff; ?></div>
                <div class="stat-label">Active Staff</div>
                <div class="stat-change">
                    <i class="fas fa-check"></i> All active
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value"><?php echo $resolution_rate; ?>%</div>
                <div class="stat-label">Resolution Rate</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> Excellent
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $avg_resolution; ?>h</div>
                <div class="stat-label">Avg Resolution Time</div>
                <div class="stat-change">
                    <i class="fas fa-info-circle"></i> Per complaint
                </div>
            </div>
        </div>

        <!-- Content Row -->
        <div class="row mt-4">
            <!-- Recent Complaints -->
            <div class="col-lg-8">
                <div class="data-table">
                    <div class="data-table-header">
                        <div>
                            <h5><i class="fas fa-list"></i> Recent Complaints</h5>
                        </div>
                        <a href="manage_complaints.php" class="btn btn-action btn-sm btn-primary">
                            View All <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Staff</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_complaints) > 0): ?>
                                <?php foreach ($recent_complaints as $complaint): ?>
                                    <tr>
                                        <td><strong>#<?php echo $complaint['complaint_id']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($complaint['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($complaint['subject'], 0, 25)); ?>...</td>
                                        <td>
                                            <?php 
                                            $status_class = 'badge-' . strtolower($complaint['status']);
                                            ?>
                                            <span class="badge-status <?php echo $status_class; ?>">
                                                <?php echo $complaint['status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($complaint['staff_name']); ?></td>
                                        <td><?php echo date('M d', strtotime($complaint['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox"></i> No complaints found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Staff Members -->
            <div class="col-lg-4">
                <div class="data-table">
                    <div class="data-table-header">
                        <h5><i class="fas fa-users-cog"></i> Staff Members</h5>
                    </div>

                    <div style="padding: 1rem;">
                        <?php if (count($staff_members) > 0): ?>
                            <?php foreach ($staff_members as $staff): ?>
                                <div style="padding: 1rem; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <p style="margin: 0; font-weight: 600; color: var(--dark);">
                                            <?php echo htmlspecialchars($staff['name']); ?>
                                        </p>
                                        <small style="color: #9ca3af;"><?php echo htmlspecialchars($staff['email']); ?></small>
                                    </div>
                                    <span class="badge-status badge-<?php echo $staff['status']; ?>">
                                        <?php echo ucfirst($staff['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted py-4">
                                <i class="fas fa-user-slash"></i> No staff members
                            </p>
                        <?php endif; ?>
                    </div>

                    <div style="padding: 1rem; border-top: 1px solid #f0f0f0; text-align: center;">
                        <a href="manage_staff.php" class="btn btn-action btn-sm btn-primary w-100">
                            <i class="fas fa-users-cog"></i> Manage Staff
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Complaints by Category & Status -->
        <div class="row mt-4">
            <!-- Category Chart -->
            <div class="col-lg-6">
                <div class="data-table">
                    <div class="data-table-header">
                        <h5><i class="fas fa-chart-pie"></i> Complaints by Category</h5>
                    </div>

                    <table class="table table-sm" style="margin-bottom: 0;">
                        <tbody>
                            <?php 
                            $total = array_sum(array_column($categories, 'count'));
                            foreach ($categories as $cat): 
                                $percentage = $total > 0 ? round(($cat['count'] / $total) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat['category']); ?></td>
                                    <td>
                                        <div style="height: 20px; background: #f0f0f0; border-radius: 10px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $percentage; ?>%; background: linear-gradient(90deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; font-size: 0.75rem; color: white; font-weight: 600;">
                                                <?php echo $percentage > 5 ? $percentage . '%' : ''; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align: right; font-weight: 600; color: var(--dark);"><?php echo $cat['count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Status Distribution -->
            <div class="col-lg-6">
                <div class="data-table">
                    <div class="data-table-header">
                        <h5><i class="fas fa-chart-bar"></i> Status Distribution</h5>
                    </div>

                    <table class="table table-sm" style="margin-bottom: 0;">
                        <tbody>
                            <?php 
                            $status_mapping = [
                                'Pending' => ['badge-pending', 'fa-hourglass-start'],
                                'In Progress' => ['badge-progress', 'fa-spinner'],
                                'Resolved' => ['badge-resolved', 'fa-check-circle']
                            ];
                            foreach ($status_data as $stat): 
                            ?>
                                <tr>
                                    <td>
                                        <i class="fas <?php echo $status_mapping[$stat['status']][1] ?? 'fa-circle'; ?>" style="margin-right: 0.5rem;"></i>
                                        <?php echo $stat['status']; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="badge-status <?php echo $status_mapping[$stat['status']][0] ?? ''; ?>">
                                            <?php echo $stat['count']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="data-table">
                    <div class="data-table-header">
                        <h5><i class="fas fa-rocket"></i> Quick Actions</h5>
                    </div>

                    <div style="padding: 1.5rem;">
                        
                        <a href="manage_complaints.php" class="btn btn-action btn-info">
                            <i class="fas fa-tasks"></i> View All Complaints
                        </a>
                        <a href="assign_staff.php" class="btn btn-action btn-warning">
                            <i class="fas fa-user-check"></i> Assign Complaints
                        </a>
                        <a href="reports.php" class="btn btn-action btn-success">
                            <i class="fas fa-chart-bar"></i> Generate Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Add smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add animation on scroll
        const cards = document.querySelectorAll('.stat-card');
        cards.forEach((card, index) => {
            card.style.animation = `slideIn 0.3s ease-out ${index * 0.1}s both`;
        });

        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
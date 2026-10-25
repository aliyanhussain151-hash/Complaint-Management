<?php
session_start();
include '../config/db_connect.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Get filter parameters
$filter_action = $_GET['action'] ?? 'all';
$filter_admin = $_GET['admin'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$page = $_GET['page'] ?? 1;
$per_page = 20;

// Build query
$where_clauses = [];

if ($filter_action != 'all') {
    $action_escaped = mysqli_real_escape_string($conn, $filter_action);
    $where_clauses[] = "al.action = '$action_escaped'";
}

if ($filter_admin != 'all') {
    $admin_escaped = intval($filter_admin);
    $where_clauses[] = "al.admin_id = $admin_escaped";
}

if (!empty($search_query)) {
    $search_escaped = mysqli_real_escape_string($conn, $search_query);
    $where_clauses[] = "(al.description LIKE '%$search_escaped%' OR u.name LIKE '%$search_escaped%')";
}

// Date filter
if (!empty($date_from) && !empty($date_to)) {
    $date_from_escaped = mysqli_real_escape_string($conn, $date_from);
    $date_to_escaped = mysqli_real_escape_string($conn, $date_to);
    $where_clauses[] = "DATE(al.timestamp) BETWEEN '$date_from_escaped' AND '$date_to_escaped'";
}

$where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM admin_logs al LEFT JOIN users u ON al.admin_id = u.id $where";
$count_result = mysqli_fetch_assoc(mysqli_query($conn, $count_query));
$total_records = $count_result['total'];
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// Get logs
$logs_query = "
    SELECT al.*, u.name as admin_name, u.email as admin_email
    FROM admin_logs al
    LEFT JOIN users u ON al.admin_id = u.id
    $where
    ORDER BY al.timestamp DESC
    LIMIT $offset, $per_page
";
$logs_result = mysqli_query($conn, $logs_query);
$logs = mysqli_fetch_all($logs_result, MYSQLI_ASSOC);

// Get unique actions for filter
$actions_query = "SELECT DISTINCT action FROM admin_logs ORDER BY action";
$actions_result = mysqli_query($conn, $actions_query);
$actions = mysqli_fetch_all($actions_result, MYSQLI_ASSOC);

// Get all admins for filter
$admins_query = "SELECT id, name FROM users WHERE role = 'admin' ORDER BY name";
$admins_result = mysqli_query($conn, $admins_query);
$admins = mysqli_fetch_all($admins_result, MYSQLI_ASSOC);

// Get activity statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_activities,
        COUNT(DISTINCT DATE(timestamp)) as active_days,
        COUNT(DISTINCT admin_id) as active_admins
    FROM admin_logs
    WHERE DATE(timestamp) BETWEEN '$date_from' AND '$date_to'
";
$stats = mysqli_fetch_assoc(mysqli_query($conn, $stats_query));

// Get top actions
$top_actions_query = "
    SELECT action, COUNT(*) as count
    FROM admin_logs
    WHERE DATE(timestamp) BETWEEN '$date_from' AND '$date_to'
    GROUP BY action
    ORDER BY count DESC
    LIMIT 5
";
$top_actions = mysqli_fetch_all(mysqli_query($conn, $top_actions_query), MYSQLI_ASSOC);

// Get hourly activity
$hourly_query = "
    SELECT HOUR(timestamp) as hour, COUNT(*) as count
    FROM admin_logs
    WHERE DATE(timestamp) BETWEEN '$date_from' AND '$date_to'
    GROUP BY HOUR(timestamp)
    ORDER BY hour
";
$hourly_data = mysqli_fetch_all(mysqli_query($conn, $hourly_query), MYSQLI_ASSOC);

// Handle delete logs
if (isset($_GET['delete_period'])) {
    $delete_period = $_GET['delete_period'];
    $delete_query = '';
    
    if ($delete_period == '30') {
        $delete_query = "DELETE FROM admin_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($delete_period == '90') {
        $delete_query = "DELETE FROM admin_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)";
    }
    
    if ($delete_query && mysqli_query($conn, $delete_query)) {
        header('Location: activity_logs.php?success=1');
        exit();
    }
}

// Get action color
function getActionColor($action) {
    $colors = [
        'LOGIN' => '#4facfe',
        'LOGOUT' => '#667eea',
        'CREATE' => '#43e97b',
        'UPDATE' => '#f5af19',
        'DELETE' => '#f5576c',
        'ASSIGN' => '#00f2fe',
        'DEFAULT' => '#6b7280'
    ];
    
    foreach ($colors as $key => $color) {
        if (strpos($action, $key) !== false) {
            return $color;
        }
    }
    return $colors['DEFAULT'];
}

// Get action icon
function getActionIcon($action) {
    $icons = [
        'LOGIN' => 'fa-sign-in-alt',
        'LOGOUT' => 'fa-sign-out-alt',
        'CREATE' => 'fa-plus-circle',
        'UPDATE' => 'fa-edit',
        'DELETE' => 'fa-trash',
        'ASSIGN' => 'fa-user-check',
        'COMPLAINT' => 'fa-tasks',
        'USER' => 'fa-user',
        'STAFF' => 'fa-user-tie',
        'DEFAULT' => 'fa-history'
    ];
    
    foreach ($icons as $key => $icon) {
        if (strpos($action, $key) !== false) {
            return $icon;
        }
    }
    return $icons['DEFAULT'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - USP Complaint System</title>
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

        .sidebar::-webkit-scrollbar {
            width: 6px;
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

        /* Statistics Cards */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border-top: 4px solid var(--primary);
            margin-bottom: 1.5rem;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.95rem;
            margin-top: 0.5rem;
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

        /* Log Item */
        .log-item {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
        }

        .log-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .log-action {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .log-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .log-content h6 {
            margin: 0;
            font-weight: 700;
            color: var(--dark);
        }

        .log-content p {
            margin: 0.25rem 0 0 0;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .log-time {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 1rem;
        }

        .log-description {
            background: #f9fafb;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #4b5563;
            margin-top: 0.75rem;
            border-left: 3px solid var(--primary);
        }

        /* Table */
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

        /* Badge */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-left-color: #4facfe;
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
            <h5 style="margin: 0; color: var(--dark); font-weight: 700;">Activity Logs</h5>
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
                <i class="fas fa-history"></i> Activity Logs
            </h2>
            <p style="color: #6b7280; margin: 0;">Monitor all admin activities and system events</p>
        </div>

        <!-- Success Alert -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> Old logs deleted successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_activities']); ?></div>
                    <div class="stat-label">Total Activities</div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card" style="border-top-color: var(--success);">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_days']; ?></div>
                    <div class="stat-label">Active Days</div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card" style="border-top-color: var(--info);">
                    <div class="stat-icon" style="color: var(--info);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_admins']; ?></div>
                    <div class="stat-label">Active Admins</div>
                </div>
            </div>
        </div>

        <!-- Top Actions -->
        <?php if (count($top_actions) > 0): ?>
            <div class="row mb-4">
                <div class="col-lg-6">
                    <div style="background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <h5 style="margin-bottom: 1.5rem; font-weight: 700; color: var(--dark);">
                            <i class="fas fa-chart-bar" style="color: var(--primary); margin-right: 0.5rem;"></i> Top Actions
                        </h5>
                        <?php foreach ($top_actions as $action): ?>
                            <div style="display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid #f0f0f0;">
                                <span><?php echo htmlspecialchars($action['action']); ?></span>
                                <span class="badge" style="background: var(--primary); color: white;"><?php echo $action['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div style="background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
                        <h5 style="margin-bottom: 1.5rem; font-weight: 700; color: var(--dark);">
                            <i class="fas fa-cog" style="color: var(--primary); margin-right: 0.5rem;"></i> Maintenance
                        </h5>
                        <div style="display: grid; gap: 0.75rem;">
                            <a href="?delete_period=30" class="btn btn-outline-warning btn-sm" onclick="return confirm('Delete logs older than 30 days?');">
                                <i class="fas fa-trash"></i> Clear Logs (30+ days)
                            </a>
                            <a href="?delete_period=90" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete logs older than 90 days?');">
                                <i class="fas fa-trash"></i> Clear Logs (90+ days)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm">
                <div class="row g-3">
                    <!-- Date From -->
                    <div class="col-md-3">
                        <label class="filter-label">
                            <i class="fas fa-calendar"></i> From Date
                        </label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                    </div>

                    <!-- Date To -->
                    <div class="col-md-3">
                        <label class="filter-label">
                            <i class="fas fa-calendar"></i> To Date
                        </label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                    </div>

                    <!-- Action Filter -->
                    <div class="col-md-3">
                        <label class="filter-label">
                            <i class="fas fa-filter"></i> Action Type
                        </label>
                        <select class="form-select" name="action">
                            <option value="all">All Actions</option>
                            <?php foreach ($actions as $act): ?>
                                <option value="<?php echo htmlspecialchars($act['action']); ?>" 
                                        <?php echo $filter_action == $act['action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($act['action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Admin Filter -->
                    <div class="col-md-3">
                        <label class="filter-label">
                            <i class="fas fa-user"></i> Admin
                        </label>
                        <select class="form-select" name="admin">
                            <option value="all">All Admins</option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?php echo $admin['id']; ?>" 
                                        <?php echo $filter_admin == $admin['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($admin['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Search -->
                    <div class="col-md-12">
                        <label class="filter-label">
                            <i class="fas fa-search"></i> Search Description
                        </label>
                        <input type="text" class="form-control" name="search" placeholder="Search by description..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>

                    <!-- Buttons -->
                    <div class="col-md-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter Logs
                        </button>
                        <a href="activity_logs.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="table-section">
            <div class="table-header">
                <h5><i class="fas fa-list"></i> Activity History (<?php echo count($logs); ?> of <?php echo $total_records; ?>)</h5>
                <div>
                    <span style="color: #6b7280; font-size: 0.9rem;">
                        Showing records from <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?>
                    </span>
                </div>
            </div>

            <?php if (count($logs) > 0): ?>
                <div style="padding: 1.5rem;">
                    <?php foreach ($logs as $log): ?>
                        <div class="log-item" style="border-left-color: <?php echo getActionColor($log['action']); ?>;">
                            <div class="log-action">
                                <div class="log-icon" style="background: <?php echo getActionColor($log['action']); ?>;">
                                    <i class="fas <?php echo getActionIcon($log['action']); ?>"></i>
                                </div>
                                <div class="log-content">
                                    <h6><?php echo htmlspecialchars($log['action']); ?></h6>
                                    <p><?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?> (<?php echo htmlspecialchars($log['admin_email'] ?? 'system'); ?>)</p>
                                    <div class="log-time">
                                        <i class="fas fa-clock"></i> <?php echo date('F j, Y g:i A', strtotime($log['timestamp'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($log['description'])): ?>
                                <div class="log-description">
                                    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($log['description']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div style="padding: 1.5rem; border-top: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-size: 0.9rem; color: #6b7280;">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_records; ?> total records)
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&action=<?php echo $filter_action; ?>&admin=<?php echo $filter_admin; ?>&page=1">
                                            First
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&action=<?php echo $filter_action; ?>&admin=<?php echo $filter_admin; ?>&page=<?php echo $page - 1; ?>">
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
                                        <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&action=<?php echo $filter_action; ?>&admin=<?php echo $filter_admin; ?>&page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&action=<?php echo $filter_action; ?>&admin=<?php echo $filter_admin; ?>&page=<?php echo $page + 1; ?>">
                                            Next
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&action=<?php echo $filter_action; ?>&admin=<?php echo $filter_admin; ?>&page=<?php echo $total_pages; ?>">
                                            Last
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4>No Activity Found</h4>
                    <p>No logs match your filters. Try adjusting your search criteria.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div style="text-align: center; color: #9ca3af; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #f0f0f0;">
            <p>
                <i class="fas fa-info-circle"></i> Last updated: <?php echo date('F j, Y g:i A'); ?>
            </p>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

        // Format timestamps to relative time
        function timeAgo(timestamp) {
            const date = new Date(timestamp);
            const seconds = Math.floor((new Date() - date) / 1000);
            
            let interval = seconds / 31536000;
            if (interval > 1) return Math.floor(interval) + " years ago";
            
            interval = seconds / 2592000;
            if (interval > 1) return Math.floor(interval) + " months ago";
            
            interval = seconds / 86400;
            if (interval > 1) return Math.floor(interval) + " days ago";
            
            interval = seconds / 3600;
            if (interval > 1) return Math.floor(interval) + " hours ago";
            
            interval = seconds / 60;
            if (interval > 1) return Math.floor(interval) + " minutes ago";
            
            return Math.floor(seconds) + " seconds ago";
        }
    </script>
</body>
</html>
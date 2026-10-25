<?php
session_start();
include '../config/db_connect.php';
include '../includes/admin_helpers.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $user_id = intval($_POST['user_id']);
    
    if ($action == 'edit_user') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $status   = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';

        if (!csrf_valid()) {
            $error_message = 'Security token expired. Please reload the page and try again.';
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $error_message = 'Invalid status';
        } elseif (($error_message = validate_account_fields($conn, $user_id, $name, $email, $password)) !== '') {
            // $error_message already set by the validator
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'user'");
            $check->bind_param('i', $user_id);
            $check->execute();
            $check->store_result();
            $exists = $check->num_rows > 0;
            $check->close();

            if (!$exists) {
                $error_message = 'User not found';
            } else {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, status = ?, password = ?, updated_at = NOW() WHERE id = ? AND role = 'user'");
                    $stmt->bind_param('ssssi', $name, $email, $status, $hash, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, status = ?, updated_at = NOW() WHERE id = ? AND role = 'user'");
                    $stmt->bind_param('sssi', $name, $email, $status, $user_id);
                }

                if ($stmt->execute()) {
                    $success_message = 'User updated successfully';
                    $note = "Edited user #$user_id (name, email, status" . ($password !== '' ? ', password' : '') . ')';
                    admin_log($conn, $admin_id, 'EDIT_USER', $note);
                } else {
                    $error_message = 'Error updating user: ' . $conn->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action == 'toggle_status') {
        $current_status_query = "SELECT status FROM users WHERE id = $user_id AND role = 'user'";
        $current_result = mysqli_query($conn, $current_status_query);
        
        if ($current_result && mysqli_num_rows($current_result) > 0) {
            $current_status = mysqli_fetch_assoc($current_result)['status'];
            $new_status = $current_status == 'active' ? 'inactive' : 'active';
            
            $update_query = "UPDATE users SET status = '$new_status', updated_at = NOW() WHERE id = $user_id AND role = 'user'";
            
            if (mysqli_query($conn, $update_query)) {
                $success_message = "User status updated to " . ucfirst($new_status);
                
                $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                            VALUES ($admin_id, 'UPDATE_USER_STATUS', 'Changed user #$user_id status to $new_status')";
                mysqli_query($conn, $log_query);
            } else {
                $error_message = 'Error updating user status';
            }
        }
    } elseif ($action == 'delete') {
        $delete_query = "DELETE FROM users WHERE id = $user_id AND role = 'user'";
        
        if (mysqli_query($conn, $delete_query)) {
            $success_message = 'User deleted successfully';
            
            $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                        VALUES ($admin_id, 'DELETE_USER', 'Deleted user #$user_id')";
            mysqli_query($conn, $log_query);
        } else {
            $error_message = 'Error deleting user: ' . mysqli_error($conn);
        }
    } elseif ($action == 'reset_password') {
        $new_password = 'User@123';
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $update_query = "UPDATE users SET password = '$hashed_password', updated_at = NOW() WHERE id = $user_id AND role = 'user'";
        
        if (mysqli_query($conn, $update_query)) {
            $success_message = "Password reset successfully. New password: $new_password";
            
            $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                        VALUES ($admin_id, 'RESET_PASSWORD', 'Reset password for user #$user_id')";
            mysqli_query($conn, $log_query);
        } else {
            $error_message = 'Error resetting password';
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';
$page = $_GET['page'] ?? 1;
$per_page = 15;

// Build query
$where_clauses = ["role = 'user'"];

if ($filter_status != 'all') {
    $status_escaped = mysqli_real_escape_string($conn, $filter_status);
    $where_clauses[] = "status = '$status_escaped'";
}

if (!empty($search_query)) {
    $search_escaped = mysqli_real_escape_string($conn, $search_query);
    $where_clauses[] = "(name LIKE '%$search_escaped%' OR email LIKE '%$search_escaped%')";
}

$where = implode(' AND ', $where_clauses);

// Validate sort
$allowed_sorts = ['created_at', 'name', 'email', 'status'];
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'created_at';
}

$allowed_orders = ['ASC', 'DESC'];
if (!in_array($sort_order, $allowed_orders)) {
    $sort_order = 'DESC';
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM users WHERE $where";
$count_result = mysqli_fetch_assoc(mysqli_query($conn, $count_query));
$total_records = $count_result['total'];
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// Get users
$users_query = "
    SELECT id, name, email, status, created_at, updated_at,
           (SELECT COUNT(*) FROM complaints WHERE user_id = users.id) as total_complaints,
           (SELECT COUNT(*) FROM complaints WHERE user_id = users.id AND status = 'Resolved') as resolved_complaints
    FROM users
    WHERE $where
    ORDER BY $sort_by $sort_order
    LIMIT $offset, $per_page
";
$users_result = mysqli_query($conn, $users_query);
$users = mysqli_fetch_all($users_result, MYSQLI_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users
    FROM users WHERE role = 'user'
";
$stats = mysqli_fetch_assoc(mysqli_query($conn, $stats_query));

// Get user details for modal
$user_detail = null;
if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    $detail_query = "
        SELECT u.*, 
               COUNT(c.complaint_id) as total_complaints,
               COUNT(CASE WHEN c.status = 'Pending' THEN 1 END) as pending_complaints,
               COUNT(CASE WHEN c.status = 'In Progress' THEN 1 END) as progress_complaints,
               COUNT(CASE WHEN c.status = 'Resolved' THEN 1 END) as resolved_complaints
        FROM users u
        LEFT JOIN complaints c ON u.id = c.user_id
        WHERE u.id = $view_id AND u.role = 'user'
        GROUP BY u.id
    ";
    $detail_result = mysqli_query($conn, $detail_query);
    if ($detail_result && mysqli_num_rows($detail_result) > 0) {
        $user_detail = mysqli_fetch_assoc($detail_result);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - USP Complaint System</title>
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
            margin-bottom: 2rem;
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
            cursor: pointer;
            user-select: none;
            transition: all 0.3s;
        }

        .table thead th:hover {
            background: #f3f4f6;
        }

        .table thead th a {
            color: var(--dark);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #f9fafb;
        }

        /* User Avatar */
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
            font-size: 0.85rem;
            margin-right: 0.75rem;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        /* Status Badge */
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline-block;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Action Buttons */
        .btn-action {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Pagination */
        .pagination-section {
            padding: 1.5rem;
            border-top: 2px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination-info {
            font-size: 0.9rem;
            color: #6b7280;
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

        .user-detail-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }

        .user-detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .detail-value {
            color: #6b7280;
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
            <h5 style="margin: 0; color: var(--dark); font-weight: 700;">Manage Users</h5>
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
                <i class="fas fa-users"></i> Manage System Users
            </h2>
            <p style="color: #6b7280; margin: 0;">View, manage, and control user accounts</p>
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

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_users'] ?? 0; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-top-color: var(--success);">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_users'] ?? 0; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-top-color: var(--warning);">
                    <div class="stat-icon" style="color: var(--warning);">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['inactive_users'] ?? 0; ?></div>
                    <div class="stat-label">Inactive Users</div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm" class="row g-3">
                <!-- Search -->
                <div class="col-md-4">
                    <label class="filter-label">
                        <i class="fas fa-search"></i> Search Users
                    </label>
                    <input type="text" class="form-control" name="search" placeholder="Search by name or email..." 
                           value="<?php echo htmlspecialchars($search_query); ?>">
                </div>

                <!-- Status Filter -->
                <div class="col-md-3">
                    <label class="filter-label">
                        <i class="fas fa-filter"></i> Filter by Status
                    </label>
                    <select class="form-select" name="status" onchange="document.getElementById('filterForm').submit();">
                        <option value="all">All Status</option>
                        <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <!-- Buttons -->
                <div class="col-md-5 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="manage_users.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="table-section">
            <div class="table-header">
                <h5><i class="fas fa-list"></i> Users (<?php echo count($users); ?> of <?php echo $total_records; ?>)</h5>
                <a href="admin_dashboard_professional.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>

            <?php if (count($users) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>
                                    <a href="?status=<?php echo $filter_status; ?>&sort=name&order=<?php echo $sort_by == 'name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                        <i class="fas fa-user"></i> User
                                        <?php if ($sort_by == 'name'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($sort_order) == 'asc' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?status=<?php echo $filter_status; ?>&sort=email&order=<?php echo $sort_by == 'email' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                        <i class="fas fa-envelope"></i> Email
                                        <?php if ($sort_by == 'email'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($sort_order) == 'asc' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Complaints</th>
                                <th>
                                    <a href="?status=<?php echo $filter_status; ?>&sort=status&order=<?php echo $sort_by == 'status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                        <i class="fas fa-check-circle"></i> Status
                                        <?php if ($sort_by == 'status'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($sort_order) == 'asc' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?status=<?php echo $filter_status; ?>&sort=created_at&order=<?php echo $sort_by == 'created_at' && $sort_order == 'DESC' ? 'ASC' : 'DESC'; ?>">
                                        <i class="fas fa-calendar"></i> Joined
                                        <?php if ($sort_by == 'created_at'): ?>
                                            <i class="fas fa-sort-<?php echo strtolower($sort_order) == 'asc' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo substr($user['name'], 0, 1); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge bg-info"><?php echo $user['total_complaints']; ?> Total</span>
                                            <span class="badge bg-success"><?php echo $user['resolved_complaints']; ?> Resolved</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?php echo strtolower($user['status']); ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo date('M d, Y', strtotime($user['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-action btn-info" data-bs-toggle="modal" 
                                                    data-bs-target="#viewUserModal"
                                                    onclick="loadUserDetail(<?php echo $user['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-action btn-primary" data-bs-toggle="modal"
                                                    data-bs-target="#editUserModal"
                                                    data-user='<?php echo htmlspecialchars(json_encode(['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'status' => $user['status']]), ENT_QUOTES); ?>'
                                                    onclick="openEditUser(this)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-action btn-warning" data-bs-toggle="modal"
                                                    data-bs-target="#toggleStatusModal"
                                                    onclick="setUserId(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo $user['status']; ?>')">
                                                <i class="fas fa-toggle-on"></i>
                                            </button>
                                            <button type="button" class="btn btn-action btn-secondary" data-bs-toggle="modal" 
                                                    data-bs-target="#resetPasswordModal"
                                                    onclick="setUserId(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <button type="button" class="btn btn-action btn-danger" 
                                                    onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-section">
                        <div class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                            (<?php echo $total_records; ?> total users)
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=1">
                                            First
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo $page - 1; ?>">
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
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo $page + 1; ?>">
                                            Next
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&search=<?php echo htmlspecialchars($search_query); ?>&page=<?php echo $total_pages; ?>">
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
                    <i class="fas fa-users"></i>
                    <h4>No Users Found</h4>
                    <p>Try adjusting your filters or search criteria</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle"></i> User Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="userDetailContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" autocomplete="off">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" id="editUserId">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Full Name</label>
                            <input type="text" class="form-control" name="name" id="editUserName" minlength="3" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" class="form-control" name="email" id="editUserEmail" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status" id="editUserStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-bold">New Password <small class="text-muted">(optional)</small></label>
                            <input type="password" class="form-control" name="password" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep current password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toggle Status Modal -->
    <div class="modal fade" id="toggleStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-toggle-on"></i> Change User Status
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p>Are you sure you want to change the status for <strong id="statusUserName"></strong>?</p>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="user_id" id="statusUserId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-check"></i> Change Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key"></i> Reset Password
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <p>Are you sure you want to reset the password for <strong id="resetUserName"></strong>?</p>
                        <p class="alert alert-info" style="background: #dbeafe; border-left: 4px solid #4facfe; color: #1e40af;">
                            <i class="fas fa-info-circle"></i> New password will be: <strong>User@123</strong>
                        </p>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="resetUserId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fill the Edit User modal from the row's data attribute
        function openEditUser(btn) {
            const u = JSON.parse(btn.getAttribute('data-user'));
            document.getElementById('editUserId').value = u.id;
            document.getElementById('editUserName').value = u.name;
            document.getElementById('editUserEmail').value = u.email;
            document.getElementById('editUserStatus').value = u.status;
            document.querySelector('#editUserModal input[name="password"]').value = '';
        }

        // Set user ID for modals
        function setUserId(id, name, status = '') {
            document.getElementById('statusUserId').value = id;
            document.getElementById('statusUserName').textContent = name;
            document.getElementById('resetUserId').value = id;
            document.getElementById('resetUserName').textContent = name;
        }

        // Load user detail
        function loadUserDetail(id) {
            const xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (this.readyState == 4 && this.status == 200) {
                    document.getElementById('userDetailContent').innerHTML = this.responseText;
                }
            };
            xhr.open('GET', 'get_user_detail.php?id=' + id, true);
            xhr.send();
        }

        // Delete user
        function deleteUser(id, name) {
            if (confirm('Are you sure you want to delete ' + name + '? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
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
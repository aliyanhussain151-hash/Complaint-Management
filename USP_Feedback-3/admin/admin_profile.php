<?php
session_start();
include '../config/db_connect.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Get admin details
$admin_query = "SELECT * FROM users WHERE id = $admin_id AND role = 'admin'";
$admin_result = mysqli_query($conn, $admin_query);
$admin = mysqli_fetch_assoc($admin_result);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action == 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Validation
        if (empty($name)) {
            $error_message = 'Name is required';
        } elseif (empty($email)) {
            $error_message = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Invalid email format';
        } else {
            // Check if email already exists (for other users)
            $email_check = "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $email) . "' AND id != $admin_id";
            $email_result = mysqli_query($conn, $email_check);
            
            if (mysqli_num_rows($email_result) > 0) {
                $error_message = 'Email already in use';
            } else {
                $name_escaped = mysqli_real_escape_string($conn, $name);
                $email_escaped = mysqli_real_escape_string($conn, $email);
                
                $update_query = "UPDATE users SET name = '$name_escaped', email = '$email_escaped', updated_at = NOW() WHERE id = $admin_id";
                
                if (mysqli_query($conn, $update_query)) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $success_message = 'Profile updated successfully';
                    $admin['name'] = $name;
                    $admin['email'] = $email;
                    
                    // Log the action
                    $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                                VALUES ($admin_id, 'UPDATE_PROFILE', 'Updated own profile information')";
                    mysqli_query($conn, $log_query);
                } else {
                    $error_message = 'Error updating profile: ' . mysqli_error($conn);
                }
            }
        }
    } elseif ($action == 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($current_password)) {
            $error_message = 'Current password is required';
        } elseif (empty($new_password)) {
            $error_message = 'New password is required';
        } elseif (empty($confirm_password)) {
            $error_message = 'Password confirmation is required';
        } elseif (strlen($new_password) < 8) {
            $error_message = 'New password must be at least 8 characters';
        } elseif ($new_password !== $confirm_password) {
            $error_message = 'Passwords do not match';
        } elseif (!password_verify($current_password, $admin['password'])) {
            $error_message = 'Current password is incorrect';
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_query = "UPDATE users SET password = '$hashed_password', updated_at = NOW() WHERE id = $admin_id";
            
            if (mysqli_query($conn, $update_query)) {
                $success_message = 'Password changed successfully';
                
                // Log the action
                $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                            VALUES ($admin_id, 'CHANGE_PASSWORD', 'Changed own password')";
                mysqli_query($conn, $log_query);
            } else {
                $error_message = 'Error changing password: ' . mysqli_error($conn);
            }
        }
    }
}

// Get admin statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM complaints) as total_complaints,
        (SELECT COUNT(*) FROM complaints WHERE status = 'Resolved') as resolved_complaints,
        (SELECT COUNT(*) FROM users WHERE role = 'staff') as total_staff,
        (SELECT COUNT(*) FROM users WHERE role = 'user') as total_users
";
$stats = mysqli_fetch_assoc(mysqli_query($conn, $stats_query));

// Get recent activities
$activities_query = "
    SELECT * FROM admin_logs 
    WHERE admin_id = $admin_id 
    ORDER BY timestamp DESC 
    LIMIT 5
";
$activities = mysqli_fetch_all(mysqli_query($conn, $activities_query), MYSQLI_ASSOC);

// Get login history
$login_history_query = "
    SELECT * FROM admin_login_attempts 
    WHERE email = '" . mysqli_real_escape_string($conn, $admin['email']) . "' AND success = TRUE
    ORDER BY attempt_time DESC 
    LIMIT 10
";
$login_history = mysqli_fetch_all(mysqli_query($conn, $login_history_query), MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - USP Complaint System</title>
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

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            margin: 0 auto 1rem;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .profile-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .profile-role {
            color: #6b7280;
            font-size: 1rem;
        }

        .profile-badges {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Form Sections */
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
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

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.875rem 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        /* Statistics */
        .stat-card {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
            margin-bottom: 1rem;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        /* Activity List */
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

        .activity-action {
            font-weight: 600;
            color: var(--dark);
        }

        .activity-time {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 0.5rem;
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

        /* Buttons */
        .btn-custom {
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Tables */
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

            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }

            .profile-name {
                font-size: 1.5rem;
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
            <h5 style="margin: 0; color: var(--dark); font-weight: 700;">My Profile</h5>
            <a href="logout.php" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h2>
                <i class="fas fa-user-circle"></i> My Profile
            </h2>
            <p style="color: #6b7280; margin: 0;">Manage your admin account and settings</p>
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

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-4">
                <!-- Profile Card -->
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo substr($admin['name'], 0, 1); ?>
                        </div>
                        <div class="profile-name"><?php echo htmlspecialchars($admin['name']); ?></div>
                        <div class="profile-role">
                            <i class="fas fa-shield-alt"></i> Administrator
                        </div>
                        <div class="profile-badges">
                            <span class="badge-custom" style="background: #dbeafe; color: #1e40af;">
                                <i class="fas fa-check-circle"></i> Active
                            </span>
                            <span class="badge-custom" style="background: #dcfce7; color: #166534;">
                                <i class="fas fa-user-shield"></i> Full Access
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> Email Address
                        </label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-calendar"></i> Member Since
                        </label>
                        <input type="text" class="form-control" value="<?php echo date('F j, Y', strtotime($admin['created_at'])); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-clock"></i> Last Updated
                        </label>
                        <input type="text" class="form-control" value="<?php echo date('F j, Y g:i A', strtotime($admin['updated_at'])); ?>" readonly>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="profile-card">
                    <h6 style="margin-bottom: 1.5rem; font-weight: 700; color: var(--dark);">
                        <i class="fas fa-chart-bar" style="color: var(--primary); margin-right: 0.5rem;"></i> System Overview
                    </h6>
                    <div class="stat-card" style="border-left-color: var(--info);">
                        <div class="stat-value"><?php echo $stats['total_complaints']; ?></div>
                        <div class="stat-label">Total Complaints</div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--success);">
                        <div class="stat-value"><?php echo $stats['resolved_complaints']; ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--warning);">
                        <div class="stat-value"><?php echo $stats['total_staff']; ?></div>
                        <div class="stat-label">Staff Members</div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--primary);">
                        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                        <div class="stat-label">Users</div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-8">
                <!-- Update Profile Form -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-edit"></i> Update Profile Information
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-user"></i> Full Name
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-custom">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="reset" class="btn btn-outline-secondary btn-custom">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Form -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-lock"></i> Change Password
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password" class="form-label">
                                <i class="fas fa-lock"></i> Current Password
                            </label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                            <small class="text-muted">Enter your current password for security</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="new_password" class="form-label">
                                        <i class="fas fa-key"></i> New Password
                                    </label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <small class="text-muted">Minimum 8 characters</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="confirm_password" class="form-label">
                                        <i class="fas fa-key"></i> Confirm Password
                                    </label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    <small class="text-muted">Re-enter new password</small>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info" style="background: #dbeafe; border-left: 4px solid #4facfe; color: #1e40af;">
                            <i class="fas fa-info-circle"></i>
                            <strong>Password Requirements:</strong>
                            <ul class="mb-0 mt-2" style="margin-left: 1.5rem;">
                                <li>Minimum 8 characters</li>
                                <li>Use a mix of uppercase and lowercase letters</li>
                                <li>Include numbers and special characters</li>
                            </ul>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning btn-custom">
                                <i class="fas fa-check"></i> Change Password
                            </button>
                            <button type="reset" class="btn btn-outline-secondary btn-custom">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Recent Activities -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-history"></i> Recent Activities
                    </div>
                    <?php if (count($activities) > 0): ?>
                        <?php foreach ($activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-action">
                                    <i class="fas fa-<?php echo strpos($activity['action'], 'UPDATE') !== false ? 'edit' : 'info-circle'; ?>"></i>
                                    <?php echo htmlspecialchars($activity['action']); ?>
                                </div>
                                <?php if (!empty($activity['description'])): ?>
                                    <p style="margin: 0.5rem 0 0 0; color: #6b7280; font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </p>
                                <?php endif; ?>
                                <div class="activity-time">
                                    <i class="fas fa-clock"></i> <?php echo date('F j, Y g:i A', strtotime($activity['timestamp'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #9ca3af;">
                            <i class="fas fa-inbox" style="font-size: 2rem; opacity: 0.5;"></i><br>
                            No recent activities
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Login History -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-sign-in-alt"></i> Login History
                    </div>
                    <?php if (count($login_history) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>IP Address</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($login_history as $login): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y g:i A', strtotime($login['attempt_time'])); ?></td>
                                            <td><code><?php echo htmlspecialchars($login['ip_address']); ?></code></td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle"></i> Successful
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #9ca3af;">
                            <i class="fas fa-inbox"></i> No login history available
                        </p>
                    <?php endif; ?>
                </div>
            </div>
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

        // Password validation on change
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');

        if (newPasswordInput && confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value && newPasswordInput.value !== this.value) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            });
        }

        // Form submission validation
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                this.classList.add('was-validated');
            });
        });
    </script>
</body>
</html>
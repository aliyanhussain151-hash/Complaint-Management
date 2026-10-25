<?php
session_start();
include '../config/db_connect.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Get date range from parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'overview';

// Validate dates
$start_date = date('Y-m-d', strtotime($start_date));
$end_date = date('Y-m-d', strtotime($end_date));

// ========================
// OVERALL STATISTICS
// ========================

// Total complaints in date range
$total_complaints = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'"))['count'] ?? 0;

// Complaints by status
$status_query = "
    SELECT status, COUNT(*) as count 
    FROM complaints 
    WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY status
";
$status_data = mysqli_fetch_all(mysqli_query($conn, $status_query), MYSQLI_ASSOC);

// Complaints by category
$category_query = "
    SELECT category, COUNT(*) as count 
    FROM complaints 
    WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY category 
    ORDER BY count DESC
";
$category_data = mysqli_fetch_all(mysqli_query($conn, $category_query), MYSQLI_ASSOC);

// Daily complaints trend
$daily_query = "
    SELECT DATE(created_at) as date, COUNT(*) as count 
    FROM complaints 
    WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(created_at)
    ORDER BY date ASC
";
$daily_data = mysqli_fetch_all(mysqli_query($conn, $daily_query), MYSQLI_ASSOC);

// Staff performance
$staff_query = "
    SELECT u.id, u.name, 
           COUNT(c.complaint_id) as total_assigned,
           COUNT(CASE WHEN c.status = 'Pending' THEN 1 END) as pending,
           COUNT(CASE WHEN c.status = 'In Progress' THEN 1 END) as in_progress,
           COUNT(CASE WHEN c.status = 'Resolved' THEN 1 END) as resolved,
           AVG(TIMESTAMPDIFF(HOUR, c.created_at, c.resolved_at)) as avg_resolution_time
    FROM users u
    LEFT JOIN complaints c ON u.id = c.assigned_staff AND DATE(c.created_at) BETWEEN '$start_date' AND '$end_date'
    WHERE u.role = 'staff'
    GROUP BY u.id
    ORDER BY total_assigned DESC
";
$staff_data = mysqli_fetch_all(mysqli_query($conn, $staff_query), MYSQLI_ASSOC);

// Average resolution time
$avg_resolution_query = "
    SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours 
    FROM complaints 
    WHERE status = 'Resolved' AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'
";
$avg_resolution = round(mysqli_fetch_assoc(mysqli_query($conn, $avg_resolution_query))['avg_hours'] ?? 0, 1);

// Top 10 complaining users
$top_users_query = "
    SELECT u.id, u.name, u.email, COUNT(c.complaint_id) as total_complaints
    FROM users u
    LEFT JOIN complaints c ON u.id = c.user_id AND DATE(c.created_at) BETWEEN '$start_date' AND '$end_date'
    WHERE u.role = 'user'
    GROUP BY u.id
    ORDER BY total_complaints DESC
    LIMIT 10
";
$top_users = mysqli_fetch_all(mysqli_query($conn, $top_users_query), MYSQLI_ASSOC);

// Resolution rate
$resolved_count = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints WHERE status = 'Resolved' AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'"))['count'] ?? 0;
$resolution_rate = $total_complaints > 0 ? round(($resolved_count / $total_complaints) * 100, 1) : 0;

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=complaints_report_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, ['Report Generated', date('F j, Y g:i A')]);
    fputcsv($output, ['Report Period', "$start_date to $end_date"]);
    fputcsv($output, []);
    
    fputcsv($output, ['SUMMARY STATISTICS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Complaints', $total_complaints]);
    fputcsv($output, ['Resolution Rate', $resolution_rate . '%']);
    fputcsv($output, ['Average Resolution Time', $avg_resolution . ' hours']);
    fputcsv($output, []);
    
    fputcsv($output, ['COMPLAINTS BY STATUS']);
    fputcsv($output, ['Status', 'Count']);
    foreach ($status_data as $status) {
        fputcsv($output, [$status['status'], $status['count']]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['COMPLAINTS BY CATEGORY']);
    fputcsv($output, ['Category', 'Count']);
    foreach ($category_data as $cat) {
        fputcsv($output, [$cat['category'], $cat['count']]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['STAFF PERFORMANCE']);
    fputcsv($output, ['Staff Name', 'Total Assigned', 'Pending', 'In Progress', 'Resolved', 'Avg Resolution Time (Hours)']);
    foreach ($staff_data as $staff) {
        fputcsv($output, [
            $staff['name'],
            $staff['total_assigned'],
            $staff['pending'],
            $staff['in_progress'],
            $staff['resolved'],
            round($staff['avg_resolution_time'] ?? 0, 1)
        ]);
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - USP Complaint System</title>
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

        /* Statistics Cards */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border-top: 4px solid var(--primary);
            margin-bottom: 1rem;
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

        /* Report Section */
        .report-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .report-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .report-title i {
            color: var(--primary);
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 350px;
            margin-bottom: 2rem;
        }

        /* Table */
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

        /* Status Badge */
        .badge-status {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline-block;
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

        /* Buttons */
        .btn-action {
            padding: 0.6rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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

            .chart-container {
                height: 250px;
            }
        }

        .print-btn {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .print-btn:hover {
            background: var(--primary);
            color: white;
        }

        @media print {
            .sidebar,
            .navbar-custom,
            .filter-section,
            .btn {
                display: none;
            }

            .main-content {
                margin-left: 0;
                margin-top: 0;
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
            <h5 style="margin: 0; color: var(--dark); font-weight: 700;">Generate Reports</h5>
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
                <i class="fas fa-chart-bar"></i> Reports & Analytics
            </h2>
            <p style="color: #6b7280; margin: 0;">Analyze complaint trends and staff performance</p>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm" class="row g-3">
                <!-- Start Date -->
                <div class="col-md-3">
                    <label class="filter-label">
                        <i class="fas fa-calendar"></i> Start Date
                    </label>
                    <input type="date" class="form-control" name="start_date" 
                           value="<?php echo $start_date; ?>" required>
                </div>

                <!-- End Date -->
                <div class="col-md-3">
                    <label class="filter-label">
                        <i class="fas fa-calendar"></i> End Date
                    </label>
                    <input type="date" class="form-control" name="end_date" 
                           value="<?php echo $end_date; ?>" required>
                </div>

                <!-- Action Buttons -->
                <div class="col-md-6 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-action flex-grow-1">
                        <i class="fas fa-filter"></i> Generate Report
                    </button>
                    <a href="?export=csv&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                       class="btn btn-success btn-action">
                        <i class="fas fa-download"></i> Export CSV
                    </a>
                    <button type="button" class="btn btn-action print-btn" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_complaints; ?></div>
                    <div class="stat-label">Total Complaints</div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="stat-card" style="border-top-color: var(--success);">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $resolution_rate; ?>%</div>
                    <div class="stat-label">Resolution Rate</div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="stat-card" style="border-top-color: var(--info);">
                    <div class="stat-icon" style="color: var(--info);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $avg_resolution; ?></div>
                    <div class="stat-label">Avg Resolution (Hours)</div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="stat-card" style="border-top-color: var(--warning);">
                    <div class="stat-icon" style="color: var(--warning);">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-value"><?php echo $resolved_count; ?></div>
                    <div class="stat-label">Resolved</div>
                </div>
            </div>
        </div>

        <!-- Status Distribution Chart -->
        <div class="report-section">
            <div class="report-title">
                <i class="fas fa-chart-pie"></i> Complaints by Status
            </div>
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($status_data as $status): 
                        $percentage = $total_complaints > 0 ? round(($status['count'] / $total_complaints) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td>
                                <?php 
                                $status_class = 'badge-' . strtolower($status['status']);
                                ?>
                                <span class="badge-status <?php echo $status_class; ?>">
                                    <?php echo $status['status']; ?>
                                </span>
                            </td>
                            <td><?php echo $status['count']; ?></td>
                            <td><?php echo $percentage; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Daily Trend Chart -->
        <div class="report-section">
            <div class="report-title">
                <i class="fas fa-chart-line"></i> Daily Complaints Trend
            </div>
            <div class="chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <!-- Category Breakdown -->
        <div class="row">
            <div class="col-lg-6">
                <div class="report-section">
                    <div class="report-title">
                        <i class="fas fa-tag"></i> Complaints by Category
                    </div>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($category_data as $cat): 
                                $percentage = $total_complaints > 0 ? round(($cat['count'] / $total_complaints) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat['category']); ?></td>
                                    <td><?php echo $cat['count']; ?></td>
                                    <td><?php echo $percentage; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Complaining Users -->
            <div class="col-lg-6">
                <div class="report-section">
                    <div class="report-title">
                        <i class="fas fa-users"></i> Top Complaining Users
                    </div>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>User Name</th>
                                <th>Email</th>
                                <th>Total Complaints</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach (array_slice($top_users, 0, 10) as $user): 
                                if ($user['total_complaints'] > 0):
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $rank; ?>. <?php echo htmlspecialchars($user['name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $user['total_complaints']; ?></span>
                                    </td>
                                </tr>
                                <?php $rank++; endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Staff Performance -->
        <div class="report-section">
            <div class="report-title">
                <i class="fas fa-user-tie"></i> Staff Performance Report
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Staff Name</th>
                            <th>Total Assigned</th>
                            <th>Pending</th>
                            <th>In Progress</th>
                            <th>Resolved</th>
                            <th>Avg Resolution Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff_data as $staff): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($staff['name']); ?></strong></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $staff['total_assigned']; ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-pending"><?php echo $staff['pending']; ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-progress"><?php echo $staff['in_progress']; ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-resolved"><?php echo $staff['resolved']; ?></span>
                                </td>
                                <td>
                                    <?php echo round($staff['avg_resolution_time'] ?? 0, 1); ?> hours
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div style="text-align: center; color: #9ca3af; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #f0f0f0;">
            <p>
                <i class="fas fa-info-circle"></i> Report generated on <?php echo date('F j, Y g:i A'); ?>
                <br>
                <small>Period: <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></small>
            </p>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($status_data, 'status')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($status_data, 'count')); ?>,
                    backgroundColor: [
                        '#fef3c7',
                        '#dbeafe',
                        '#dcfce7'
                    ],
                    borderColor: [
                        '#92400e',
                        '#1e40af',
                        '#166534'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Daily Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($daily_data, 'date')); ?>,
                datasets: [{
                    label: 'Daily Complaints',
                    data: <?php echo json_encode(array_column($daily_data, 'count')); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f0f0f0'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

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
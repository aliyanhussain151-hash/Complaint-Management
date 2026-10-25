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
$filter_status = $_GET['status'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$page = $_GET['page'] ?? 1;
$per_page = 10;

// Build query
$where_clauses = [];

if ($filter_status != 'all') {
    $filter_status_escaped = mysqli_real_escape_string($conn, $filter_status);
    $where_clauses[] = "c.status = '$filter_status_escaped'";
}

if ($filter_category != 'all') {
    $filter_category_escaped = mysqli_real_escape_string($conn, $filter_category);
    $where_clauses[] = "c.category = '$filter_category_escaped'";
}

if (!empty($search_query)) {
    $search_escaped = mysqli_real_escape_string($conn, $search_query);
    $where_clauses[] = "(c.subject LIKE '%$search_escaped%' OR c.message LIKE '%$search_escaped%' OR u.name LIKE '%$search_escaped%')";
}

$where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM complaints c JOIN users u ON c.user_id = u.id $where";
$count_result = mysqli_fetch_assoc(mysqli_query($conn, $count_query));
$total_records = $count_result['total'];
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// Get complaints
$complaints_query = "
    SELECT c.complaint_id, c.subject, c.status, c.created_at, c.category, c.message, c.solution, c.assigned_staff, 
           u.name as user_name, u.email as user_email,
           CASE WHEN c.assigned_staff IS NOT NULL THEN (SELECT name FROM users WHERE id = c.assigned_staff) ELSE 'Unassigned' END as staff_name
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    $where
    ORDER BY c.created_at DESC
    LIMIT $offset, $per_page
";
$complaints_result = mysqli_query($conn, $complaints_query);
$complaints = mysqli_fetch_all($complaints_result, MYSQLI_ASSOC);

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM complaints ORDER BY category";
$categories_result = mysqli_query($conn, $categories_query);
$categories = mysqli_fetch_all($categories_result, MYSQLI_ASSOC);

// Category options for the edit modal: defaults + anything already used
$default_categories = ['Technical', 'Billing', 'Service', 'Feedback', 'Other'];
$category_options = $default_categories;
foreach ($categories as $cat_row) {
    if (!in_array($cat_row['category'], $category_options, true)) {
        $category_options[] = $cat_row['category'];
    }
}

// All staff (active first) for the assignment dropdown in the edit modal
$all_staff = mysqli_fetch_all(mysqli_query($conn,
    "SELECT id, name, status FROM users WHERE role = 'staff' ORDER BY status ASC, name ASC"), MYSQLI_ASSOC);

// One-time flash message (set after an edit/redirect)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $complaint_id = intval($_POST['complaint_id']);
    
    if ($action == 'edit_complaint') {
        $subject   = trim($_POST['subject'] ?? '');
        $category  = trim($_POST['category'] ?? '');
        $message   = trim($_POST['message'] ?? '');
        $status    = $_POST['status'] ?? '';
        $solution  = trim($_POST['solution'] ?? '');
        $staff_raw = $_POST['assigned_staff'] ?? '';
        $staff_id  = ($staff_raw === '') ? null : intval($staff_raw);
        $err = '';

        if (!csrf_valid()) {
            $err = 'Security token expired. Please reload the page and try again.';
        } elseif (strlen($subject) < 3 || strlen($subject) > 200) {
            $err = 'Subject must be between 3 and 200 characters';
        } elseif ($category === '' || strlen($category) > 100) {
            $err = 'Please choose a category';
        } elseif ($message === '') {
            $err = 'Message cannot be empty';
        } elseif (!in_array($status, ['Pending', 'In Progress', 'Resolved'], true)) {
            $err = 'Invalid status';
        }

        if ($err === '' && $staff_id !== null) {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff'");
            $chk->bind_param('i', $staff_id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows === 0) {
                $err = 'Selected staff member does not exist';
            }
            $chk->close();
        }

        $old = null;
        if ($err === '') {
            $q = $conn->prepare("SELECT status, resolved_at FROM complaints WHERE complaint_id = ?");
            $q->bind_param('i', $complaint_id);
            $q->execute();
            $old = $q->get_result()->fetch_assoc();
            $q->close();
            if (!$old) {
                $err = 'Complaint not found';
            }
        }

        if ($err === '') {
            // Keep resolved_at in step with the status
            if ($status === 'Resolved') {
                $resolved_at = ($old['status'] === 'Resolved' && $old['resolved_at']) ? $old['resolved_at'] : date('Y-m-d H:i:s');
            } else {
                $resolved_at = null;
            }
            $solution_val = ($solution === '') ? null : $solution;

            $stmt = $conn->prepare("UPDATE complaints
                SET subject = ?, category = ?, message = ?, status = ?, assigned_staff = ?, solution = ?, resolved_at = ?
                WHERE complaint_id = ?");
            $stmt->bind_param('ssssissi', $subject, $category, $message, $status, $staff_id, $solution_val, $resolved_at, $complaint_id);
            if ($stmt->execute()) {
                $stmt->close();
                // Feature 3: if the edit changed the status, it must appear in
                // the student-visible timeline (same request as the change).
                if ($old['status'] !== $status) {
                    record_status_event($conn, $complaint_id, 'status_changed', $status,
                        'Status changed via complaint edit', $admin_id, 'admin');
                }
                admin_log($conn, $admin_id, 'EDIT_COMPLAINT', "Edited complaint #$complaint_id (subject, category, message, status, assignment, solution)");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "Complaint #$complaint_id updated successfully"];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error updating complaint: ' . $conn->error];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $err];
        }
        header('Location: manage_complaints.php?status=' . urlencode($filter_status) . '&page=' . intval($page));
        exit();
    } elseif ($action == 'edit_reply') {
        $reply_id = intval($_POST['reply_id'] ?? 0);
        $message  = trim($_POST['message'] ?? '');
        if (!csrf_valid()) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Security token expired. Please reload the page and try again.'];
        } elseif ($message === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Reply cannot be empty'];
        } elseif (!reply_exists($conn, $reply_id, $complaint_id)) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Reply not found'];
        } else {
            $stmt = $conn->prepare("UPDATE replies SET message = ? WHERE reply_id = ? AND complaint_id = ?");
            $stmt->bind_param('sii', $message, $reply_id, $complaint_id);
            if ($stmt->execute()) {
                admin_log($conn, $admin_id, 'EDIT_REPLY', "Edited reply #$reply_id on complaint #$complaint_id");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Reply updated'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error updating reply'];
            }
            $stmt->close();
        }
        header('Location: manage_complaints.php?open=' . $complaint_id);
        exit();
    } elseif ($action == 'delete_reply') {
        $reply_id = intval($_POST['reply_id'] ?? 0);
        if (!csrf_valid()) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Security token expired. Please reload the page and try again.'];
        } else {
            $stmt = $conn->prepare("DELETE FROM replies WHERE reply_id = ? AND complaint_id = ?");
            $stmt->bind_param('ii', $reply_id, $complaint_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                admin_log($conn, $admin_id, 'DELETE_REPLY', "Deleted reply #$reply_id from complaint #$complaint_id");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Reply deleted'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Reply not found'];
            }
            $stmt->close();
        }
        header('Location: manage_complaints.php?open=' . $complaint_id);
        exit();
    } elseif ($action == 'update_status') {
        $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
        // resolved_at is only set when the complaint becomes Resolved, and cleared otherwise.
        // (resolved_at is assigned before status so IF() still sees the old status.)
        $resolved_sql = ($new_status === 'Resolved')
            ? "IF(status = 'Resolved' AND resolved_at IS NOT NULL, resolved_at, NOW())"
            : "NULL";
        $update_query = "UPDATE complaints SET resolved_at = $resolved_sql, status = '$new_status' WHERE complaint_id = $complaint_id";
        mysqli_query($conn, $update_query);

        // Feature 3: keep the student-visible timeline in step with every
        // status change (recorded right after the change, same request).
        $owner_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT user_id FROM complaints WHERE complaint_id = $complaint_id"));
        if ($owner_row && in_array($new_status, ['Pending', 'In Progress', 'Resolved'], true)) {
            record_status_event($conn, $complaint_id, 'status_changed', $new_status, null, $admin_id, 'admin');
        }
        
        // Log the action
        $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                    VALUES ($admin_id, 'UPDATE_COMPLAINT', 'Updated complaint #$complaint_id status to $new_status')";
        mysqli_query($conn, $log_query);
        
        header('Location: manage_complaints.php?status=' . $filter_status . '&page=' . $page);
        exit();
    } elseif ($action == 'close_complaint') {
        // ------------------------------------------------------------------
        // Feature 2: close a complaint.
        //
        // Root cause of the original bug: nothing in the system could close a
        // complaint AND tell the student about it - update_status changed the
        // row but never notified anyone, and there was no notifications table
        // or read path at all. This action fixes that end-to-end:
        //   1. validates (CSRF + status guard),
        //   2. sets status = 'Resolved' + resolved_at,
        //   3. records the timeline event,
        //   4. inserts the in-app notification with the required message,
        // all inside ONE transaction, so the status can never change without
        // the student being notified (and vice versa).
        //
        // Idempotent: the status guard rejects a second close attempt, and
        // notify_user() uses INSERT IGNORE against uq_notification_dedupe,
        // so a retry or double-click cannot duplicate the notification.
        // ------------------------------------------------------------------
        if (!csrf_valid()) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Security token expired. Please reload the page and try again.'];
            header('Location: manage_complaints.php?status=' . $filter_status . '&page=' . $page);
            exit();
        }

        $solution = trim($_POST['solution'] ?? '');
        if ($solution === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Resolution details are required to close a complaint.'];
            header('Location: manage_complaints.php?open=' . $complaint_id);
            exit();
        }

        $close_error = '';
        try {
            $conn->begin_transaction();

            // Read the current row FOR UPDATE so two concurrent closes cannot race.
            $stmt = $conn->prepare("SELECT status, user_id, subject FROM complaints WHERE complaint_id = ? FOR UPDATE");
            $stmt->bind_param('i', $complaint_id);
            $stmt->execute();
            $cur = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$cur) {
                $close_error = 'Complaint not found';
            } elseif ($cur['status'] === 'Resolved') {
                // Already closed - report honestly instead of writing a second notification.
                $close_error = 'This complaint is already closed.';
            }

            if ($close_error === '') {
                $stmt = $conn->prepare("UPDATE complaints SET status = 'Resolved', solution = ?, resolved_at = NOW() WHERE complaint_id = ?");
                $stmt->bind_param('si', $solution, $complaint_id);
                $stmt->execute();
                $stmt->close();

                if (!record_status_event($conn, $complaint_id, 'closed', 'Resolved', 'Resolution: ' . $solution, $admin_id, 'admin')) {
                    throw new Exception('status history insert failed');
                }

                $title = 'Complaint #' . $complaint_id . ' closed';
                $body = closure_notification_message($complaint_id, $solution);
                if (!notify_user($conn, (int) $cur['user_id'], $complaint_id, 'closed', $title, $body)) {
                    throw new Exception('notification insert failed');
                }
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[close_complaint] ' . $e->getMessage());
            $close_error = 'The complaint could not be closed (no changes were made). Please try again.';
        }

        if ($close_error === '') {
            admin_log($conn, $admin_id, 'CLOSE_COMPLAINT', "Closed complaint #$complaint_id and notified the student");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Complaint #$complaint_id closed and the student has been notified."];
            header('Location: manage_complaints.php?status=' . $filter_status . '&page=' . $page);
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $close_error];
            header('Location: manage_complaints.php?open=' . $complaint_id);
        }
        exit();
    } elseif ($action == 'delete') {
        $delete_query = "DELETE FROM complaints WHERE complaint_id = $complaint_id";
        mysqli_query($conn, $delete_query);
        
        $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                    VALUES ($admin_id, 'DELETE_COMPLAINT', 'Deleted complaint #$complaint_id')";
        mysqli_query($conn, $log_query);
        
        header('Location: manage_complaints.php');
        exit();
    }
}

// Get complaint details for modal
$complaint_detail = null;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $detail_query = "
        SELECT c.*, u.name as user_name, u.email as user_email, 
               CASE WHEN c.assigned_staff IS NOT NULL THEN (SELECT name FROM users WHERE id = c.assigned_staff) ELSE 'Unassigned' END as staff_name
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.complaint_id = $id
    ";
    $detail_result = mysqli_query($conn, $detail_query);
    if ($detail_result && mysqli_num_rows($detail_result) > 0) {
        $complaint_detail = mysqli_fetch_assoc($detail_result);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Complaints - USP Complaint System</title>
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

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .filter-section .form-control,
        .filter-section .form-select {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }

        .filter-section .form-control:focus,
        .filter-section .form-select:focus {
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

        /* Modal */
        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .complaint-detail-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }

        .complaint-detail-item:last-child {
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

            .navbar-custom {
                left: 0;
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
            <h5 style="margin: 0; color: var(--dark); font-weight: 700;">Manage Complaints</h5>
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
                <i class="fas fa-tasks"></i> Manage All Complaints
            </h2>
            <p style="color: #6b7280; margin: 0;">View, filter, and manage all complaints in the system</p>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm">
                <div class="row g-3">
                    <!-- Search -->
                    <div class="col-md-4">
                        <label class="filter-label">
                            <i class="fas fa-search"></i> Search
                        </label>
                        <input type="text" class="form-control" name="search" placeholder="Search by subject, message, or user..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>

                    <!-- Status Filter -->
                    <div class="col-md-3">
                        <label class="filter-label">
                            <i class="fas fa-filter"></i> Status
                        </label>
                        <select class="form-select" name="status" onchange="document.getElementById('filterForm').submit();">
                            <option value="all">All Status</option>
                            <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="In Progress" <?php echo $filter_status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Resolved" <?php echo $filter_status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>

                    <!-- Category Filter -->
                    <div class="col-md-3">
                        <label class="filter-label">
                            <i class="fas fa-tag"></i> Category
                        </label>
                        <select class="form-select" name="category" onchange="document.getElementById('filterForm').submit();">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                        <?php echo $filter_category == $cat['category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Buttons -->
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="manage_complaints.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($flash['msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="table-section">
            <div class="table-header">
                <h5><i class="fas fa-list"></i> Complaints (Showing <?php echo count($complaints); ?> of <?php echo $total_records; ?>)</h5>
                <a href="admin_dashboard_professional.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>

            <?php if (count($complaints) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
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
                                        <?php echo htmlspecialchars(substr($complaint['subject'], 0, 40)); ?>...
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
                                        <span class="badge-status  <?php echo $status_class; ?>">
                                            <?php echo $complaint['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($complaint['staff_name'] != 'Unassigned'): ?>
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
                                                    data-bs-target="#viewComplaintModal" 
                                                    onclick="loadComplaintDetail(<?php echo $complaint['complaint_id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-action btn-primary" data-bs-toggle="modal"
                                                    data-bs-target="#editComplaintModal"
                                                    data-complaint='<?php echo htmlspecialchars(json_encode(['id' => (int)$complaint['complaint_id'], 'subject' => $complaint['subject'], 'category' => $complaint['category'], 'message' => $complaint['message'], 'status' => $complaint['status'], 'assigned_staff' => $complaint['assigned_staff'], 'solution' => $complaint['solution']]), ENT_QUOTES); ?>'
                                                    onclick="openEditComplaint(this)">
                                                <i class="fas fa-pen-to-square"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-action btn-warning" data-bs-toggle="modal" 
                                                    data-bs-target="#updateStatusModal"
                                                    onclick="setComplaintId(<?php echo $complaint['complaint_id']; ?>)">
                                                <i class="fas fa-flag"></i> Status
                                            </button>
                                            <?php if ($complaint['status'] !== 'Resolved'): ?>
                                            <button type="button" class="btn btn-action btn-success"
                                                    data-complaint='<?php echo htmlspecialchars(json_encode(['id' => (int)$complaint['complaint_id'], 'subject' => $complaint['subject']]), ENT_QUOTES); ?>'
                                                    onclick="openCloseComplaint(this)"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#closeComplaintModal">
                                                <i class="fas fa-check-circle"></i> Close
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-action btn-danger" 
                                                    onclick="deleteComplaint(<?php echo $complaint['complaint_id']; ?>)">
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
                            (<?php echo $total_records; ?> total complaints)
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&category=<?php echo $filter_category; ?>&page=1">
                                            First
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&category=<?php echo $filter_category; ?>&page=<?php echo $page - 1; ?>">
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
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&category=<?php echo $filter_category; ?>&page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&category=<?php echo $filter_category; ?>&page=<?php echo $page + 1; ?>">
                                            Next
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?status=<?php echo $filter_status; ?>&category=<?php echo $filter_category; ?>&page=<?php echo $total_pages; ?>">
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
                    <h4>No Complaints Found</h4>
                    <p>Try adjusting your filters or search criteria</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Complaint Modal -->
    <div class="modal fade" id="viewComplaintModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle"></i> Complaint Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="complaintDetailContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Complaint Modal -->
    <div class="modal fade" id="editComplaintModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen-to-square"></i> Edit Complaint <span id="editComplaintLabel"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_complaint">
                        <input type="hidden" name="complaint_id" id="editComplaintId">
                        <?php echo csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Subject</label>
                                <input type="text" class="form-control" name="subject" id="editSubject" minlength="3" maxlength="200" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Category</label>
                                <select class="form-select" name="category" id="editCategory" required>
                                    <?php foreach ($category_options as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Status</label>
                                <select class="form-select" name="status" id="editStatus" required>
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Resolved">Resolved</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Assigned Staff</label>
                                <select class="form-select" name="assigned_staff" id="editAssigned">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($all_staff as $st): ?>
                                        <option value="<?php echo (int)$st['id']; ?>">
                                            <?php echo htmlspecialchars($st['name']) . ($st['status'] === 'inactive' ? ' (inactive)' : ''); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Message</label>
                                <textarea class="form-control" name="message" id="editMessage" rows="4" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Solution <small class="text-muted">(optional)</small></label>
                                <textarea class="form-control" name="solution" id="editSolution" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Update Complaint Status
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="complaint_id" id="updateComplaintId">
                        
                        <div class="mb-3">
                            <label for="newStatus" class="form-label">New Status</label>
                            <select class="form-select" id="newStatus" name="new_status" required>
                                <option value="">Select Status</option>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Resolved">Resolved</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Close Complaint Modal (Feature 2) -->
    <div class="modal fade" id="closeComplaintModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle"></i> Close Complaint
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="closeComplaintForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="close_complaint">
                        <input type="hidden" name="complaint_id" id="closeComplaintId">
                        <?php echo csrf_field(); ?>

                        <p class="mb-2">Closing <strong id="closeComplaintSubject"></strong> will:</p>
                        <ul class="mb-3">
                            <li>Set its status to <strong>Resolved</strong>.</li>
                            <li>Record the resolution on the complaint.</li>
                            <li>Add an entry to the student's status history.</li>
                            <li>Send the student an in-app notification with your resolution.</li>
                        </ul>

                        <div class="mb-3">
                            <label for="closeSolution" class="form-label">Resolution details <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="solution" id="closeSolution" rows="4"
                                      placeholder="Describe how the complaint was resolved..." required></textarea>
                            <div class="form-text">This text is shown to the student in the notification.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="closeComplaintSubmit">
                            <i class="fas fa-check-circle"></i> Close Complaint &amp; Notify
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;

        // Fill the Edit Complaint modal from the row's data attribute
        function openEditComplaint(btn) {
            const c = JSON.parse(btn.getAttribute('data-complaint'));
            document.getElementById('editComplaintId').value = c.id;
            document.getElementById('editComplaintLabel').textContent = '#' + c.id;
            document.getElementById('editSubject').value = c.subject;
            document.getElementById('editMessage').value = c.message;
            document.getElementById('editSolution').value = c.solution || '';
            document.getElementById('editStatus').value = c.status;
            document.getElementById('editAssigned').value = c.assigned_staff ? String(c.assigned_staff) : '';
            const cat = document.getElementById('editCategory');
            if (![...cat.options].some(o => o.value === c.category)) {
                cat.add(new Option(c.category, c.category));
            }
            cat.value = c.category;
        }

        // ---- Reply editing (controls are rendered by get_complaint_detail.php) ----
        function toggleReplyEdit(id) {
            document.getElementById('reply-view-' + id).classList.toggle('d-none');
            document.getElementById('reply-edit-' + id).classList.toggle('d-none');
        }

        function deleteReply(replyId, complaintId) {
            if (!confirm('Delete this reply? This cannot be undone.')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_reply">
                <input type="hidden" name="reply_id" value="${replyId}">
                <input type="hidden" name="complaint_id" value="${complaintId}">
                <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">`;
            document.body.appendChild(form);
            form.submit();
        }

        // Re-open the complaint detail modal after a reply edit/delete
        document.addEventListener('DOMContentLoaded', function() {
            const open = new URLSearchParams(location.search).get('open');
            if (open && /^\d+$/.test(open)) {
                loadComplaintDetail(open);
                new bootstrap.Modal(document.getElementById('viewComplaintModal')).show();
            }
        });

        // Set complaint ID for update
        function setComplaintId(id) {
            document.getElementById('updateComplaintId').value = id;
        }

        // ---- Feature 2: Close complaint ----
        function openCloseComplaint(el) {
            const c = JSON.parse(el.getAttribute('data-complaint'));
            document.getElementById('closeComplaintId').value = c.id;
            document.getElementById('closeComplaintSubject').textContent = '#' + c.id + ' - ' + c.subject;
            document.getElementById('closeSolution').value = '';
        }

        // Disable the close button while the request is in flight so a
        // double-click cannot send two close requests.
        document.getElementById('closeComplaintForm').addEventListener('submit', function (e) {
            if (this.dataset.submitted === '1') {
                e.preventDefault();
                return;
            }
            this.dataset.submitted = '1';
            const btn = document.getElementById('closeComplaintSubmit');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Closing...';
        });

        // Load complaint detail
        function loadComplaintDetail(id) {
            const xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (this.readyState == 4 && this.status == 200) {
                    document.getElementById('complaintDetailContent').innerHTML = this.responseText;
                }
            };
            xhr.open('GET', 'get_complaint_detail.php?id=' + id, true);
            xhr.send();
        }

        // Delete complaint
        function deleteComplaint(id) {
            if (confirm('Are you sure you want to delete this complaint? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="complaint_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Export to CSV
        function exportToCSV() {
            const table = document.querySelector('table');
            if (!table) {
                alert('No data to export');
                return;
            }

            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cols = row.querySelectorAll('td, th');
                const csvRow = [];
                cols.forEach(col => {
                    csvRow.push('"' + col.innerText.replace(/"/g, '""') + '"');
                });
                csv.push(csvRow.join(','));
            });

            const csvContent = csv.join('\n');
            const link = document.createElement('a');
            link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvContent);
            link.download = 'complaints_' + new Date().getTime() + '.csv';
            link.click();
        }
    </script>
</body>
</html>
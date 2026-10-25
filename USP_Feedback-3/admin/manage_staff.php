<?php
session_start();
include '../config/db_connect.php';
include '../includes/admin_helpers.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: admin_login.php');
    exit();
}

// Get all staff members
$staff_query = "
    SELECT u.id, u.name, u.email, u.status, u.created_at,
           COUNT(c.complaint_id) as total_complaints,
           COUNT(CASE WHEN c.status = 'Resolved' THEN 1 END) as resolved_complaints
    FROM users u
    LEFT JOIN complaints c ON u.id = c.assigned_staff
    WHERE u.role = 'staff'
    GROUP BY u.id
    ORDER BY u.created_at DESC
";
$staff_members = mysqli_fetch_all(mysqli_query($conn, $staff_query), MYSQLI_ASSOC);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $staff_id = intval($_POST['staff_id']);
    
    if ($action == 'edit_staff') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $status   = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $err      = '';

        if (!csrf_valid()) {
            $err = 'Security token expired. Please reload the page and try again.';
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $err = 'Invalid status';
        } else {
            $err = validate_account_fields($conn, $staff_id, $name, $email, $password);
        }

        if ($err === '') {
            $check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'staff'");
            $check->bind_param('i', $staff_id);
            $check->execute();
            $check->store_result();
            if ($check->num_rows === 0) {
                $err = 'Staff member not found';
            }
            $check->close();
        }

        if ($err === '') {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, status = ?, password = ?, updated_at = NOW() WHERE id = ? AND role = 'staff'");
                $stmt->bind_param('ssssi', $name, $email, $status, $hash, $staff_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, status = ?, updated_at = NOW() WHERE id = ? AND role = 'staff'");
                $stmt->bind_param('sssi', $name, $email, $status, $staff_id);
            }
            if ($stmt->execute()) {
                admin_log($conn, (int) $_SESSION['user_id'], 'EDIT_STAFF',
                    "Edited staff #$staff_id (name, email, status" . ($password !== '' ? ', password' : '') . ')');
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Staff member updated successfully'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error updating staff: ' . $conn->error];
            }
            $stmt->close();
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $err];
        }

        header('Location: manage_staff.php');
        exit();
    } elseif ($action == 'toggle_status') {
        $current_status_query = "SELECT status FROM users WHERE id = $staff_id AND role = 'staff'";
        $current_status = mysqli_fetch_assoc(mysqli_query($conn, $current_status_query))['status'] ?? null;
        if ($current_status === null) { header('Location: manage_staff.php'); exit(); }
        $new_status = $current_status == 'active' ? 'inactive' : 'active';
        
        $update_query = "UPDATE users SET status = '$new_status' WHERE id = $staff_id AND role = 'staff'";
        mysqli_query($conn, $update_query);
        
        // Log action
        $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                    VALUES ({$_SESSION['user_id']}, 'UPDATE_STAFF', 'Changed staff status to $new_status')";
        mysqli_query($conn, $log_query);
        
        header('Location: manage_staff.php');
        exit();
    } elseif ($action == 'delete') {
        $delete_query = "DELETE FROM users WHERE id = $staff_id AND role = 'staff'";
        mysqli_query($conn, $delete_query);
        
        // Log action
        $log_query = "INSERT INTO admin_logs (admin_id, action, description) 
                    VALUES ({$_SESSION['user_id']}, 'DELETE_STAFF', 'Deleted staff member')";
        mysqli_query($conn, $log_query);
        
        header('Location: manage_staff.php');
        exit();
    }
}
?>
<?php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body {
            background-color: #f5f7fa;
        }
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
            position: sticky;
            top: 0;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
            border-left: 4px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: white;
        }
        .data-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .data-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .data-table thead th {
            border: none;
            padding: 1rem;
            font-weight: 600;
        }
        .data-table tbody td {
            padding: 1rem;
            border-color: #f0f0f0;
            vertical-align: middle;
        }
        .navbar-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .main-content {
            padding: 2rem;
        }
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
    </style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="admin_dashboard.php">
                <i class="fas fa-shield-alt"></i> USP Admin Panel
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 sidebar">
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

            <!-- Main Content -->
            <div class="col-lg-10 main-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="fw-bold mb-2">
                                <i class="fas fa-users-cog"></i> Manage Staff Members
                            </h2>
                            <p class="text-muted mb-0">Add, edit, and manage staff members</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                            <i class="fas fa-user-plus"></i> Add New Staff
                        </button>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Staff Table -->
                <div class="data-table">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><i class="fas fa-user"></i> Name</th>
                                <th><i class="fas fa-envelope"></i> Email</th>
                                <th><i class="fas fa-tasks"></i> Total Tasks</th>
                                <th><i class="fas fa-check"></i> Resolved</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-calendar"></i> Joined</th>
                                <th><i class="fas fa-cog"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($staff_members) > 0): ?>
                                <?php foreach ($staff_members as $staff): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($staff['name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($staff['email']); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $staff['total_complaints']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo $staff['resolved_complaints']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $staff['status'] == 'active' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($staff['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($staff['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                                                    <button type="submit" class="btn btn-warning">
                                                        <i class="fas fa-toggle-on"></i>
                                                    </button>
                                                </form>
                                                <button class="btn btn-primary" data-bs-toggle="modal"
                                                        data-bs-target="#editStaffModal"
                                                        data-staff="<?php echo htmlspecialchars(json_encode(['id' => (int)$staff['id'], 'name' => $staff['name'], 'email' => $staff['email'], 'status' => $staff['status']]), ENT_QUOTES); ?>"
                                                        onclick="openEditStaff(this)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-info" data-bs-toggle="modal" 
                                                        data-bs-target="#viewStaffModal"
                                                        onclick="viewStaffDetails(<?php echo htmlspecialchars(json_encode($staff)); ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" 
                                                      onsubmit="return confirm('Are you sure?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                                                    <button type="submit" class="btn btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox"></i> No staff members found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div class="modal fade" id="addStaffModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-user-plus"></i> Add New Staff Member
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addStaffForm">
                        <div class="mb-3">
                            <label for="staffName" class="form-label fw-bold">Full Name</label>
                            <input type="text" class="form-control" id="staffName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="staffEmail" class="form-label fw-bold">Email Address</label>
                            <input type="email" class="form-control" id="staffEmail" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="staffPassword" class="form-label fw-bold">Password</label>
                            <input type="password" class="form-control" id="staffPassword" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="staffConfirmPassword" class="form-label fw-bold">Confirm Password</label>
                            <input type="password" class="form-control" id="staffConfirmPassword" name="confirm_password" required>
                        </div>
                        <div id="alertMessage"></div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-check"></i> Add Staff Member
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div class="modal fade" id="editStaffModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit"></i> Edit Staff Member</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" autocomplete="off">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_staff">
                        <input type="hidden" name="staff_id" id="editStaffId">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Full Name</label>
                            <input type="text" class="form-control" name="name" id="editStaffName" minlength="3" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Email Address</label>
                            <input type="email" class="form-control" name="email" id="editStaffEmail" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status" id="editStaffStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-bold">New Password <small class="text-muted">(optional)</small></label>
                            <input type="password" class="form-control" name="password" id="editStaffPassword" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep current password">
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

    <!-- View Staff Modal -->
    <div class="modal fade" id="viewStaffModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title fw-bold">Staff Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Name:</strong> <span id="viewName"></span></p>
                    <p><strong>Email:</strong> <span id="viewEmail"></span></p>
                    <p><strong>Status:</strong> <span id="viewStatus"></span></p>
                    <p><strong>Total Tasks:</strong> <span id="viewTasks"></span></p>
                    <p><strong>Resolved:</strong> <span id="viewResolved"></span></p>
                    <p><strong>Joined:</strong> <span id="viewJoined"></span></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add Staff Form Handler
        document.getElementById('addStaffForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('add_staff.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const alertDiv = document.getElementById('alertMessage');
                if (data.success) {
                    alertDiv.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle"></i> ${data.message}</div>`;
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    alertDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ${data.message}</div>`;
                }
            });
        });

        // Fill the Edit Staff modal from the row's data attribute
        function openEditStaff(btn) {
            const st = JSON.parse(btn.getAttribute('data-staff'));
            document.getElementById('editStaffId').value = st.id;
            document.getElementById('editStaffName').value = st.name;
            document.getElementById('editStaffEmail').value = st.email;
            document.getElementById('editStaffStatus').value = st.status;
            document.getElementById('editStaffPassword').value = '';
        }

        // View Staff Details
        function viewStaffDetails(staff) {
            document.getElementById('viewName').textContent = staff.name;
            document.getElementById('viewEmail').textContent = staff.email;
            document.getElementById('viewStatus').innerHTML = `<span class="badge bg-${staff.status == 'active' ? 'success' : 'danger'}">${staff.status}</span>`;
            document.getElementById('viewTasks').textContent = staff.total_complaints;
            document.getElementById('viewResolved').textContent = staff.resolved_complaints;
            document.getElementById('viewJoined').textContent = new Date(staff.created_at).toLocaleDateString();
        }
    </script>
</body>
</html>
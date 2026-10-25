<?php
session_start();
include '../config/db_connect.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    http_response_code(401);
    exit('Unauthorized');
}

if (isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    
    $query = "
        SELECT u.*, 
               COUNT(c.complaint_id) as total_complaints,
               COUNT(CASE WHEN c.status = 'Pending' THEN 1 END) as pending_complaints,
               COUNT(CASE WHEN c.status = 'In Progress' THEN 1 END) as progress_complaints,
               COUNT(CASE WHEN c.status = 'Resolved' THEN 1 END) as resolved_complaints
        FROM users u
        LEFT JOIN complaints c ON u.id = c.user_id
        WHERE u.id = $user_id AND u.role = 'user'
        GROUP BY u.id
    ";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        echo '
        <div class="user-detail-item">
            <div class="detail-label">User Name</div>
            <div class="detail-value">' . htmlspecialchars($user['name']) . '</div>
        </div>

        <div class="user-detail-item">
            <div class="detail-label">Email Address</div>
            <div class="detail-value">' . htmlspecialchars($user['email']) . '</div>
        </div>

        <div class="user-detail-item">
            <div class="detail-label">Account Status</div>
            <div class="detail-value">
                <span class="badge badge-' . strtolower($user['status']) . '">' . ucfirst($user['status']) . '</span>
            </div>
        </div>

        <div class="user-detail-item">
            <div class="detail-label">Member Since</div>
            <div class="detail-value">' . date('F j, Y g:i A', strtotime($user['created_at'])) . '</div>
        </div>

        <div class="user-detail-item">
            <div class="detail-label">Last Updated</div>
            <div class="detail-value">' . date('F j, Y g:i A', strtotime($user['updated_at'])) . '</div>
        </div>

        <div class="user-detail-item">
            <div class="detail-label">Complaint Statistics</div>
            <div class="detail-value">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <strong>Total Complaints:</strong><br>
                        <span class="badge bg-primary">' . $user['total_complaints'] . '</span>
                    </div>
                    <div>
                        <strong>Pending:</strong><br>
                        <span class="badge bg-warning">' . $user['pending_complaints'] . '</span>
                    </div>
                    <div>
                        <strong>In Progress:</strong><br>
                        <span class="badge bg-info">' . $user['progress_complaints'] . '</span>
                    </div>
                    <div>
                        <strong>Resolved:</strong><br>
                        <span class="badge bg-success">' . $user['resolved_complaints'] . '</span>
                    </div>
                </div>
            </div>
        </div>
        ';
    } else {
        echo '<div class="alert alert-danger">User not found</div>';
    }
} else {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid user ID</div>';
}
?>
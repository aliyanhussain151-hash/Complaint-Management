<?php
session_start();
include '../config/db_connect.php';
include '../includes/admin_helpers.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    http_response_code(401);
    exit('Unauthorized');
}

if (isset($_GET['id'])) {
    $complaint_id = intval($_GET['id']);
    
    $query = "
        SELECT c.*, u.name as user_name, u.email as user_email, 
               CASE WHEN c.assigned_staff IS NOT NULL THEN (SELECT name FROM users WHERE id = c.assigned_staff) ELSE 'Unassigned' END as staff_name
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.complaint_id = $complaint_id
    ";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $complaint = mysqli_fetch_assoc($result);
        
        // Get replies
        $replies_query = "
            SELECT r.*, u.name as replied_by_name
            FROM replies r
            JOIN users u ON r.replied_by = u.id
            WHERE r.complaint_id = $complaint_id
            ORDER BY r.reply_date DESC
        ";
        $replies = mysqli_fetch_all(mysqli_query($conn, $replies_query), MYSQLI_ASSOC);
        
        echo '
        <div class="complaint-detail-item">
            <div class="detail-label">Complaint ID</div>
            <div class="detail-value">#' . $complaint['complaint_id'] . '</div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">User Information</div>
            <div class="detail-value">
                <strong>' . htmlspecialchars($complaint['user_name']) . '</strong><br>
                ' . htmlspecialchars($complaint['user_email']) . '
            </div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Subject</div>
            <div class="detail-value">' . htmlspecialchars($complaint['subject']) . '</div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Category</div>
            <div class="detail-value">
                <span class="badge bg-light text-dark">' . htmlspecialchars($complaint['category']) . '</span>
            </div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Status</div>
            <div class="detail-value">';
            
            $status_class = 'badge-' . strtolower($complaint['status']);
            echo '<span class="badge-status ' . $status_class . '">' . $complaint['status'] . '</span>';
            
        echo '</div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Message</div>
            <div class="detail-value" style="padding: 1rem; background: #f9fafb; border-radius: 6px;">
                ' . nl2br(htmlspecialchars($complaint['message'])) . '
            </div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Assigned To</div>
            <div class="detail-value">
                ' . ($complaint['staff_name'] != 'Unassigned' ? '<span class="badge bg-info">' . htmlspecialchars($complaint['staff_name']) . '</span>' : '<span class="badge bg-warning">Unassigned</span>') . '
            </div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Solution</div>
            <div class="detail-value">';
            if (!empty($complaint['solution'])) {
                echo '<div style="padding: 1rem; background: #f9fafb; border-radius: 6px;">' . nl2br(htmlspecialchars($complaint['solution'])) . '</div>';
            } else {
                echo '<span class="text-muted">No solution added yet</span>';
            }
            echo '</div>
        </div>

        <div class="complaint-detail-item">
            <div class="detail-label">Created Date</div>
            <div class="detail-value">' . date('F j, Y g:i A', strtotime($complaint['created_at'])) . '</div>
        </div>
        ';
        
        if (!empty($complaint['resolved_at'])) {
            echo '
            <div class="complaint-detail-item">
                <div class="detail-label">Resolved Date</div>
                <div class="detail-value">' . date('F j, Y g:i A', strtotime($complaint['resolved_at'])) . '</div>
            </div>
            ';
        }

        // ---- Feature 1: evidence files (download goes through the
        // authenticated endpoint; admins are always allowed there) ----
        $att_q = $conn->prepare("SELECT attachment_id, original_name, file_size, mime_type FROM complaint_attachments WHERE complaint_id = ? ORDER BY attachment_id ASC");
        $att_q->bind_param('i', $complaint_id);
        $att_q->execute();
        $attachments = $att_q->get_result()->fetch_all(MYSQLI_ASSOC);
        $att_q->close();

        echo '
        <div class="complaint-detail-item">
            <div class="detail-label"><i class="fas fa-paperclip"></i> Evidence Files (' . count($attachments) . ')</div>
            <div class="detail-value">';
        if (count($attachments) > 0) {
            echo '<ul class="mb-0">';
            foreach ($attachments as $att) {
                echo '<li><a href="../user/view_attachment.php?id=' . (int) $att['attachment_id'] . '" target="_blank" rel="noopener">'
                    . htmlspecialchars($att['original_name']) . '</a>'
                    . ' <small style="color:#9ca3af;">(' . round($att['file_size'] / 1024, 1) . ' KB, ' . htmlspecialchars($att['mime_type']) . ')</small></li>';
            }
            echo '</ul>';
        } else {
            echo '<span class="text-muted">No evidence files</span>';
        }
        echo '</div>
        </div>';

        // ---- Feature 3: status history timeline ----
        $timeline = get_complaint_timeline($conn, $complaint_id);
        echo '
        <div class="complaint-detail-item">
            <div class="detail-label"><i class="fas fa-history"></i> Status History (' . count($timeline) . ')</div>
            <div class="detail-value">';
        if (count($timeline) > 0) {
            echo '<ul style="list-style:none; padding-left:1rem; border-left:3px solid #667eea; margin:0;">';
            foreach ($timeline as $ev) {
                echo '<li style="padding:0.25rem 0 0.75rem 0.75rem;">'
                    . '<strong>' . htmlspecialchars($ev['event_type']) . '</strong>'
                    . ($ev['status'] !== null ? ' &rarr; ' . htmlspecialchars($ev['status']) : '')
                    . ($ev['message'] !== null ? ' <span style="color:#6b7280;">' . htmlspecialchars($ev['message']) . '</span>' : '')
                    . '<br><small style="color:#9ca3af;">' . htmlspecialchars($ev['created_at'])
                    . ($ev['actor_name'] !== null ? ' &middot; ' . htmlspecialchars($ev['actor_name']) . ' (' . htmlspecialchars((string) $ev['actor_role']) . ')' : '')
                    . '</small></li>';
            }
            echo '</ul>';
        } else {
            echo '<span class="text-muted">No history recorded</span>';
        }
        echo '</div>
        </div>';
        
        if (count($replies) > 0) {
            echo '
            <div class="complaint-detail-item">
                <div class="detail-label">
                    <i class="fas fa-comments"></i> Replies (' . count($replies) . ')
                </div>
                <div style="margin-top: 1rem;">';
                
                foreach ($replies as $reply) {
                    $rid = (int) $reply['reply_id'];
                    $cid = (int) $complaint['complaint_id'];
                    echo '
                    <div style="padding: 1rem; background: #f9fafb; border-left: 3px solid #667eea; border-radius: 6px; margin-bottom: 1rem;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong>' . htmlspecialchars($reply['replied_by_name']) . '</strong>
                                <small style="color: #9ca3af; display: block; margin-top: 0.25rem;">' . date('F j, Y g:i A', strtotime($reply['reply_date'])) . '</small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="toggleReplyEdit(' . $rid . ')" title="Edit reply"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-outline-danger" onclick="deleteReply(' . $rid . ', ' . $cid . ')" title="Delete reply"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <p id="reply-view-' . $rid . '" style="margin: 0.75rem 0 0 0; color: #6b7280;">' . nl2br(htmlspecialchars($reply['message'])) . '</p>
                        <form id="reply-edit-' . $rid . '" method="POST" action="manage_complaints.php" class="d-none mt-2">
                            <input type="hidden" name="action" value="edit_reply">
                            <input type="hidden" name="reply_id" value="' . $rid . '">
                            <input type="hidden" name="complaint_id" value="' . $cid . '">
                            ' . csrf_field() . '
                            <textarea name="message" class="form-control mb-2" rows="3" required>' . htmlspecialchars($reply['message']) . '</textarea>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleReplyEdit(' . $rid . ')">Cancel</button>
                        </form>
                    </div>
                    ';
                }
                
                echo '
                </div>
            </div>
            ';
        }
    } else {
        echo '<div class="alert alert-danger">Complaint not found</div>';
    }
} else {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid complaint ID</div>';
}
?>
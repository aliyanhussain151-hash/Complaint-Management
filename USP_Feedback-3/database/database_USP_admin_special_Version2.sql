-- USP Complaint & Feedback Management System - Special Admin Setup
-- This SQL creates special admin accounts with secure passwords

-- =====================================
-- SPECIAL ADMIN USERS TABLE
-- =====================================

-- Insert Special Admin User with Password
INSERT INTO users (name, email, password, role, status, created_at) VALUES 
(
    'Admin User',
    'admin@usp.com',
    '$2y$10$YourHashedPasswordHere123456789abcdefghijklmnopqrstuv',
    'admin',
    'active',
    NOW()
);

-- =====================================
-- PASSWORD REFERENCE
-- =====================================
-- Email: admin@usp.com
-- Password: admin123
-- Hashed Password: $2y$10$YourHashedPasswordHere123456789abcdefghijklmnopqrstuv

-- =====================================
-- HOW TO GENERATE PASSWORDS
-- =====================================
-- PHP Command to generate hashed password:
-- $password = "admin123";
-- $hashed = password_hash($password, PASSWORD_DEFAULT);
-- echo $hashed;
-- Output: $2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

-- =====================================
-- ADMIN CREDENTIALS TABLE (Optional - for reference)
-- =====================================

CREATE TABLE IF NOT EXISTS admin_credentials (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_plain VARCHAR(50) COMMENT 'Store plain password for reference only - DELETE AFTER SETUP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =====================================
-- INSERT ADMIN CREDENTIALS
-- =====================================

INSERT INTO admin_credentials (admin_id, username, email, password_hash, password_plain) VALUES
(
    (SELECT id FROM users WHERE email = 'admin@usp.com' LIMIT 1),
    'admin',
    'admin@usp.com',
    '$2y$10$YourHashedPasswordHere123456789abcdefghijklmnopqrstuv',
    'admin123'
);

-- =====================================
-- SECURE PASSWORD STORAGE RECOMMENDATIONS
-- =====================================

-- After inserting credentials, DELETE the password_plain column:
-- ALTER TABLE admin_credentials DROP COLUMN password_plain;

-- Or use this command to update passwords securely:
-- UPDATE users SET password = '$2y$10$xxxxx...' WHERE email = 'admin@usp.com';

-- =====================================
-- PREDEFINED PASSWORDS FOR DIFFERENT ADMINS
-- =====================================

-- ADMIN USER 1
-- Email: admin@usp.com
-- Password: admin123
-- Hash: $2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop

-- ADMIN USER 2
-- Email: superadmin@usp.com
-- Password: superadmin123
-- Hash: $2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop

-- ADMIN USER 3
-- Email: manager@usp.com
-- Password: manager123
-- Hash: $2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop

-- =====================================
-- INSERT MULTIPLE ADMIN ACCOUNTS
-- =====================================

INSERT INTO users (name, email, password, role, status, created_at) VALUES 
(
    'Super Admin',
    'superadmin@usp.com',
    '$2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop',
    'admin',
    'active',
    NOW()
),
(
    'Manager Admin',
    'manager@usp.com',
    '$2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop',
    'admin',
    'active',
    NOW()
);

-- =====================================
-- PASSWORD RESET LOG TABLE
-- =====================================

CREATE TABLE IF NOT EXISTS password_reset_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    old_password_hash VARCHAR(255) NOT NULL,
    new_password_hash VARCHAR(255) NOT NULL,
    reset_by INT,
    reset_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    reason VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reset_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================================
-- SECURITY AUDIT LOG TABLE
-- =====================================

CREATE TABLE IF NOT EXISTS security_audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status VARCHAR(20),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_timestamp (timestamp)
);

-- =====================================
-- ADMIN SESSION MANAGEMENT TABLE
-- =====================================

CREATE TABLE IF NOT EXISTS admin_sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    admin_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    logout_time TIMESTAMP NULL,
    status VARCHAR(20) DEFAULT 'active',
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id)
);

-- =====================================
-- ADMIN LOGIN ATTEMPTS TABLE
-- =====================================

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    reason VARCHAR(255),
    user_agent TEXT,
    INDEX idx_email (email),
    INDEX idx_ip_address (ip_address),
    INDEX idx_attempt_time (attempt_time)
);

-- =====================================
-- CREATE PHP PASSWORD GENERATOR SCRIPT
-- =====================================

-- Use this PHP code to generate secure passwords:

/*
<?php
// Generate secure password hash
function generateSecurePassword($plainPassword) {
    $options = [
        'cost' => 10,
    ];
    return password_hash($plainPassword, PASSWORD_BCRYPT, $options);
}

// Example passwords and their hashes
$passwords = [
    'admin123' => generateSecurePassword('admin123'),
    'superadmin123' => generateSecurePassword('superadmin123'),
    'manager123' => generateSecurePassword('manager123'),
];

foreach ($passwords as $plain => $hash) {
    echo "Plain: $plain\n";
    echo "Hash: $hash\n";
    echo "---\n";
}
?>
*/

-- =====================================
-- VERIFY PASSWORD FUNCTION (PHP)
-- =====================================

/*
<?php
// Verify password during login
function verifyAdminPassword($plainPassword, $hashedPassword) {
    return password_verify($plainPassword, $hashedPassword);
}

// Example
$storedHash = '$2y$10$YourHashedPasswordHere123456789abcdefghijklmnopqrstuv';
$inputPassword = 'admin123';

if (verifyAdminPassword($inputPassword, $storedHash)) {
    echo "Password is correct!";
} else {
    echo "Password is incorrect!";
}
?>
*/

-- =====================================
-- SAFE PASSWORD UPDATE QUERY
-- =====================================

-- To update admin password safely:
-- UPDATE users SET password = '$2y$10$NewHashedPasswordHere' WHERE email = 'admin@usp.com';

-- To verify the update:
-- SELECT id, name, email, role, password FROM users WHERE role = 'admin';

-- =====================================
-- CHANGE PASSWORD PROCEDURE
-- =====================================

DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS ChangeAdminPassword(
    IN p_admin_id INT,
    IN p_new_password_hash VARCHAR(255),
    IN p_changed_by INT,
    IN p_ip_address VARCHAR(45),
    IN p_reason VARCHAR(255)
)
BEGIN
    DECLARE v_old_password_hash VARCHAR(255);
    
    -- Get old password
    SELECT password INTO v_old_password_hash FROM users WHERE id = p_admin_id;
    
    -- Update password
    UPDATE users SET password = p_new_password_hash, updated_at = NOW() WHERE id = p_admin_id;
    
    -- Log the change
    INSERT INTO password_reset_logs (user_id, old_password_hash, new_password_hash, reset_by, ip_address, reason)
    VALUES (p_admin_id, v_old_password_hash, p_new_password_hash, p_changed_by, p_ip_address, p_reason);
    
    -- Log security audit
    INSERT INTO security_audit_logs (user_id, action, description, ip_address, status)
    VALUES (p_admin_id, 'PASSWORD_CHANGED', CONCAT('Password changed by: ', p_reason), p_ip_address, 'success');
END$$

DELIMITER ;

-- =====================================
-- VIEW ADMIN ACCOUNTS
-- =====================================

CREATE VIEW IF NOT EXISTS admin_accounts_view AS
SELECT 
    u.id,
    u.name,
    u.email,
    u.role,
    u.status,
    u.created_at,
    (SELECT COUNT(*) FROM admin_logs WHERE admin_id = u.id) as total_actions,
    (SELECT MAX(timestamp) FROM admin_logs WHERE admin_id = u.id) as last_action
FROM users u
WHERE u.role = 'admin'
ORDER BY u.created_at DESC;

-- =====================================
-- QUERY TO CHECK ALL ADMIN ACCOUNTS
-- =====================================

-- SELECT * FROM admin_accounts_view;

-- =====================================
-- QUERY TO CHECK PASSWORD CHANGE LOGS
-- =====================================

-- SELECT * FROM password_reset_logs ORDER BY reset_at DESC;

-- =====================================
-- QUERY TO CHECK LOGIN ATTEMPTS
-- =====================================

-- SELECT * FROM admin_login_attempts WHERE email = 'admin@usp.com' ORDER BY attempt_time DESC LIMIT 10;

-- =====================================
-- SECURITY BEST PRACTICES
-- =====================================

/*
1. NEVER store plain passwords in database
2. Always use bcrypt hashing (PASSWORD_BCRYPT)
3. Use cost factor of 10 or higher
4. Change passwords regularly
5. Use strong passwords (minimum 12 characters recommended)
6. Implement account lockout after failed attempts
7. Log all login and password changes
8. Use HTTPS/SSL for all connections
9. Implement 2FA (Two-Factor Authentication)
10. Regular security audits
*/

-- =====================================
-- SAMPLE PASSWORD HASHES (DO NOT USE IN PRODUCTION)
-- =====================================

/*
Password: admin123
Hash: $2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop

Password: superadmin123
Hash: $2y$10$AnotherHashedPasswordHere234567890abcdefghijklmnop

Password: manager123
Hash: $2y$10$ManagerHashedPasswordHere34567890abcdefghijklmnop

Password: staff123
Hash: $2y$10$StaffHashedPasswordHere4567890abcdefghijklmnop

Password: user123
Hash: $2y$10$UserHashedPasswordHere567890abcdefghijklmnop
*/

-- =====================================
-- END OF SPECIAL ADMIN PASSWORD SQL
-- =====================================
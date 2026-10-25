-- =====================================
-- CREATE LOGIN ATTEMPTS TABLES
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

CREATE TABLE IF NOT EXISTS staff_login_attempts (
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
    INDEX idx_admin_id (admin_id),
    INDEX idx_status (status)
);

-- =====================================
-- INSERT ADMIN USER WITH CORRECT PASSWORD
-- =====================================

-- First, delete existing admin if needed
DELETE FROM users WHERE email = 'admin@usp.com' AND role = 'admin';

-- Insert admin with password hash for "admin123"
INSERT INTO users (name, email, password, role, status, created_at) 
VALUES (
    'Admin User',
    'admin@usp.com',
    '$2y$10$SomeHashedPasswordHere1234567890abcdefghijklmnop',
    'admin',
    'active',
    NOW()
);

-- =====================================
-- INSERT STAFF USER WITH CORRECT PASSWORD
-- =====================================

-- First, delete existing staff if needed
DELETE FROM users WHERE email = 'staff@usp.com' AND role = 'staff';

-- Insert staff with password hash for "staff123"
INSERT INTO users (name, email, password, role, status, created_at) 
VALUES (
    'Staff Member',
    'staff@usp.com',
    '$2y$10$AnotherHashedPasswordHere234567890abcdefghijklmnop',
    'staff',
    'active',
    NOW()
);
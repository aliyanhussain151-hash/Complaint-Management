-- USP Complaint & Feedback Management System Database

CREATE DATABASE IF NOT EXISTS USP;
USE USP;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff', 'user') NOT NULL DEFAULT 'user',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Complaints Table
CREATE TABLE IF NOT EXISTS complaints (
    complaint_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    subject VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL,
    message LONGTEXT NOT NULL,
    status ENUM('Pending', 'In Progress', 'Resolved') DEFAULT 'Pending',
    assigned_staff INT,
    solution LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_staff) REFERENCES users(id) ON DELETE SET NULL
);

-- Replies Table
CREATE TABLE IF NOT EXISTS replies (
    reply_id INT PRIMARY KEY AUTO_INCREMENT,
    complaint_id INT NOT NULL,
    message LONGTEXT NOT NULL,
    replied_by INT NOT NULL,
    reply_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE,
    FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert Sample Admin User
INSERT INTO users (name, email, password, role) VALUES 
('Admin User', 'admin@usp.com', '$2y$10$YourHashedPasswordHere', 'admin'),
('Staff Member', 'staff@usp.com', '$2y$10$YourHashedPasswordHere', 'staff'),
('Test User', 'user@usp.com', '$2y$10$YourHashedPasswordHere', 'user');

-- Insert Sample Complaints
INSERT INTO complaints (user_id, subject, category, message, status, assigned_staff) VALUES 
(3, 'Issue with service', 'Technical', 'I am facing issues with the service', 'Pending', NULL),
(3, 'Billing Query', 'Billing', 'Need clarification on billing', 'In Progress', 2);
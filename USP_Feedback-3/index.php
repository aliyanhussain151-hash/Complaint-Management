<?php
session_start();
include 'config/db_connect.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'user') {
        header('Location: user/user_dashboard.php');
    } elseif ($_SESSION['role'] == 'admin') {
        header('Location: admin/admin_dashboard_professional.php');
    } elseif ($_SESSION['role'] == 'staff') {
        header('Location: staff/staff_dashboard.php');
    }
    exit();
}

// Get system statistics
$total_complaints = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints"))['count'] ?? 0;

$resolved_complaints = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM complaints WHERE status = 'Resolved'"))['count'] ?? 0;

$total_users = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM users WHERE role = 'user'"))['count'] ?? 0;

$total_staff = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as count FROM users WHERE role = 'staff'"))['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>USP Complaint & Feedback Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Navbar */
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }

        .navbar-custom .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .navbar-custom .navbar-brand i {
            margin-right: 0.5rem;
        }

        .nav-link {
            color: var(--dark) !important;
            font-weight: 500;
            margin: 0 0.5rem;
            transition: all 0.3s;
            position: relative;
        }

        .nav-link:hover {
            color: var(--primary) !important;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 0.3s;
        }

        .nav-link:hover::after {
            width: 100%;
        }

        .navbar-buttons {
            display: flex;
            gap: 1rem;
        }

        .btn-nav {
            padding: 0.6rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }

        .btn-nav-login {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-nav-login:hover {
            background: var(--primary);
            color: white;
        }

        .btn-nav-signup {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-nav-signup:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 120px 0 80px;
            margin-top: 60px;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            animation: gradientShift 15s ease infinite;
        }

        @keyframes gradientShift {
            0%, 100% { filter: brightness(1); }
            50% { filter: brightness(1.1); }
        }

        .hero-content {
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1.2;
            animation: slideInDown 0.8s ease-out;
        }

        .hero p {
            font-size: 1.3rem;
            margin-bottom: 2rem;
            opacity: 0.95;
            animation: slideInUp 0.8s ease-out 0.2s both;
        }

        .hero-buttons {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
            animation: slideInUp 0.8s ease-out 0.4s both;
        }

        .btn-hero {
            padding: 1rem 2.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-hero-primary {
            background: white;
            color: var(--primary);
        }

        .btn-hero-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }

        .btn-hero-secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
        }

        .btn-hero-secondary:hover {
            background: white;
            color: var(--primary);
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Features Section */
        .features-section {
            padding: 100px 0;
            background: #f9fafb;
        }

        .section-title {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-title h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .section-title p {
            font-size: 1.1rem;
            color: #6b7280;
            max-width: 600px;
            margin: 0 auto;
        }

        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border: 2px solid transparent;
            text-align: center;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
            border-color: var(--primary);
        }

        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .feature-card h4 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .feature-card p {
            color: #6b7280;
            margin-bottom: 0;
            line-height: 1.8;
        }

        /* Portal Cards Section */
        .portals-section {
            padding: 100px 0;
            background: white;
        }

        .portal-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            border-top: 4px solid var(--primary);
        }

        .portal-card:nth-child(2) {
            border-top-color: var(--success);
        }

        .portal-card:nth-child(3) {
            border-top-color: var(--warning);
        }

        .portal-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
        }

        .portal-header {
            padding: 2rem;
            text-align: center;
        }

        .portal-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .portal-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .portal-header p {
            color: #6b7280;
            margin-bottom: 1.5rem;
        }

        .portal-features {
            padding: 0 2rem 2rem;
            background: #f9fafb;
        }

        .portal-features ul {
            list-style: none;
            margin-bottom: 1.5rem;
        }

        .portal-features li {
            padding: 0.5rem 0;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .portal-features i {
            color: var(--success);
            font-weight: bold;
        }

        .portal-button {
            padding: 0 2rem 2rem;
            text-align: center;
        }

        .btn-portal {
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            transition: all 0.3s;
            width: 100%;
        }

        .btn-portal-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-portal-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        /* Statistics Section */
        .stats-section {
            padding: 100px 0;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .stat-box {
            text-align: center;
            padding: 2rem;
        }

        .stat-number {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* How it Works Section */
        .how-it-works {
            padding: 100px 0;
            background: #f9fafb;
        }

        .step-card {
            text-align: center;
            padding: 2rem;
            position: relative;
        }

        .step-number {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0 auto 1.5rem;
        }

        .step-card h4 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .step-card p {
            color: #6b7280;
            margin-bottom: 0;
        }

        /* Footer */
        .footer {
            background: var(--dark);
            color: white;
            padding: 3rem 0 1rem;
        }

        .footer h5 {
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .footer ul {
            list-style: none;
            margin-bottom: 0;
        }

        .footer ul li {
            margin-bottom: 0.75rem;
        }

        .footer ul li a {
            color: #d1d5db;
            text-decoration: none;
            transition: all 0.3s;
        }

        .footer ul li a:hover {
            color: var(--primary);
            padding-left: 0.5rem;
        }

        .footer-divider {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin: 2rem 0 1rem;
            padding-top: 1rem;
            text-align: center;
            color: #9ca3af;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1.1rem;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn-hero {
                width: 100%;
                max-width: 300px;
            }

            .section-title h2 {
                font-size: 2rem;
            }

            .stat-number {
                font-size: 2.5rem;
            }
        }

        /* Utility Classes */
        .text-gradient {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .shadow-lg-custom {
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12) !important;
        }
    </style>
<script src="js/screen-fit.js"></script>
<link rel="stylesheet" href="css/responsive.css">
<script src="js/responsive.js" defer></script>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-lg">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-shield-alt"></i> USP Students Complaint and Feedback System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#portals">Portals</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#how">How It Works</a>
                    </li>
                </ul>
                <div class="navbar-buttons ms-3">
                    <a href="user/login.php" class="btn btn-nav btn-nav-login">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                    <a href="user/register.php" class="btn btn-nav btn-nav-signup">
                        <i class="fas fa-user-plus"></i> User Signup
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container-lg">
            <div class="hero-content">
                <h1><i class="fas fa-headset"></i> Manage Complaints with Ease</h1>
                <p>A comprehensive complaint and feedback management system designed to streamline your customer support process</p>
                
                <div class="hero-buttons">
                    <a href="user/register.php" class="btn btn-hero btn-hero-primary">
                        <i class="fas fa-arrow-right"></i> Get Started as User
                    </a>
                    <a href="admin/admin_login.php" class="btn btn-hero btn-hero-secondary">
                        <i class="fas fa-sign-in-alt"></i> Admin Portal
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats-section">
        <div class="container-lg">
            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($total_complaints); ?></div>
                        <div class="stat-label">Total Complaints</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($resolved_complaints); ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($total_users); ?></div>
                        <div class="stat-label">Active Users</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($total_staff); ?></div>
                        <div class="stat-label">Staff Members</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="container-lg">
            <div class="section-title">
                <h2>Powerful Features</h2>
                <p>Everything you need to manage complaints and feedback efficiently</p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h4>Secure Authentication</h4>
                        <p>Role-based access control with secure login and password management for users, staff, and admins</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <h4>Complaint Management</h4>
                        <p>Submit, track, and manage complaints with real-time status updates and detailed history</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h4>Staff Management</h4>
                        <p>Add, manage, and assign staff members to handle complaints effectively</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <h4>Analytics & Reports</h4>
                        <p>Generate comprehensive reports and analyze complaint trends with detailed statistics</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <h4>Direct Communication</h4>
                        <p>Direct messaging system for quick communication between users, staff, and admins</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <h4>Responsive Design</h4>
                        <p>Fully responsive interface that works seamlessly on desktop, tablet, and mobile devices</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Portals Section -->
    <section class="portals-section" id="portals">
        <div class="container-lg">
            <div class="section-title">
                <h2>Three Powerful Portals</h2>
                <p>Different access levels for users, staff, and administrators</p>
            </div>

            <div class="row g-4">
                <!-- User Portal -->
                <div class="col-md-6 col-lg-4">
                    <div class="portal-card">
                        <div class="portal-header">
                            <div class="portal-icon text-primary">
                                <i class="fas fa-user-circle"></i>
                            </div>
                            <h3>User Portal</h3>
                            <p>Submit and track your complaints</p>
                        </div>
                        <div class="portal-features">
                            <ul>
                                <li><i class="fas fa-check"></i> Easy registration</li>
                                <li><i class="fas fa-check"></i> Submit complaints</li>
                                <li><i class="fas fa-check"></i> Real-time tracking</li>
                                <li><i class="fas fa-check"></i> Direct messaging</li>
                                <li><i class="fas fa-check"></i> View history</li>
                            </ul>
                        </div>
                        <div class="portal-button">
                            <a href="user/register.php" class="btn btn-portal btn-portal-primary">
                                Register Now
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Admin Portal -->
                <div class="col-md-6 col-lg-4">
                    <div class="portal-card">
                        <div class="portal-header">
                            <div class="portal-icon text-success">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <h3>Admin Portal</h3>
                            <p>Complete system management</p>
                        </div>
                        <div class="portal-features">
                            <ul>
                                <li><i class="fas fa-check"></i> Full dashboard</li>
                                <li><i class="fas fa-check"></i> Manage all complaints</li>
                                <li><i class="fas fa-check"></i> Staff management</li>
                                <li><i class="fas fa-check"></i> Generate reports</li>
                                <li><i class="fas fa-check"></i> Activity monitoring</li>
                            </ul>
                        </div>
                        <div class="portal-button">
                            <a href="admin/admin_login.php" class="btn btn-portal btn-portal-primary">
                                Admin Login
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Staff Portal -->
                <div class="col-md-6 col-lg-4">
                    <div class="portal-card">
                        <div class="portal-header">
                            <div class="portal-icon text-warning">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <h3>Staff Portal</h3>
                            <p>Resolve complaints efficiently</p>
                        </div>
                        <div class="portal-features">
                            <ul>
                                <li><i class="fas fa-check"></i> View assigned tasks</li>
                                <li><i class="fas fa-check"></i> Resolve complaints</li>
                                <li><i class="fas fa-check"></i> Update status</li>
                                <li><i class="fas fa-check"></i> Add solutions</li>
                                <li><i class="fas fa-check"></i> Track performance</li>
                            </ul>
                        </div>
                        <div class="portal-button">
                            <a href="staff/staff_login.php" class="btn btn-portal btn-portal-primary">
                                Staff Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works" id="how">
        <div class="container-lg">
            <div class="section-title">
                <h2>How It Works</h2>
                <p>Simple and efficient complaint management process</p>
            </div>

            <div class="row">
                <div class="col-md-3 col-sm-6">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h4>Register</h4>
                        <p>Create an account with your email and password</p>
                    </div>
                </div>

                <div class="col-md-3 col-sm-6">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h4>Submit</h4>
                        <p>File your complaint with detailed information</p>
                    </div>
                </div>

                <div class="col-md-3 col-sm-6">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h4>Track</h4>
                        <p>Monitor the progress in real-time</p>
                    </div>
                </div>

                <div class="col-md-3 col-sm-6">
                    <div class="step-card">
                        <div class="step-number">4</div>
                        <h4>Resolve</h4>
                        <p>Get your issue resolved quickly</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 80px 0; text-align: center;">
        <div class="container-lg">
            <h2 style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem;">Ready to Get Started?</h2>
            <p style="font-size: 1.1rem; margin-bottom: 2rem; opacity: 0.95;">Join thousands of users managing complaints efficiently</p>
            <a href="user/register.php" class="btn btn-hero btn-hero-primary" style="color: var(--primary);">
                <i class="fas fa-arrow-right"></i> Create Account Now
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container-lg">
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-4">
                    <h5><i class="fas fa-shield-alt"></i> USP System</h5>
                    <ul>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#portals">Portals</a></li>
                        <li><a href="#how">How It Works</a></li>
                    </ul>
                </div>

                <div class="col-md-3 col-sm-6 mb-4">
                    <h5>User Access</h5>
                    <ul>
                        <li><a href="user/register.php">Register</a></li>
                        <li><a href="user/login.php">User Login</a></li>
                        <li><a href="user/submit_complaint.php">Submit Complaint</a></li>
                    </ul>
                </div>

                <div class="col-md-3 col-sm-6 mb-4">
                    <h5>Admin Access</h5>
                    <ul>
                        <li><a href="admin/admin_login.php">Admin Login</a></li>
                        <li><a href="admin/admin_signup.php">Admin Signup</a></li>
                        <li><a href="admin/manage_complaints.php">Manage System</a></li>
                    </ul>
                </div>

                <div class="col-md-3 col-sm-6 mb-4">
                    <h5>Support</h5>
                    <ul>
                        <li><a href="#">Help & FAQ</a></li>
                        <li><a href="#">Contact Us</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-divider">
                <p>&copy; 2026 USP Complaint & Feedback Management System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'slideInUp 0.6s ease-out forwards';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.feature-card, .portal-card, .step-card').forEach(el => {
            el.style.opacity = '0';
            observer.observe(el);
        });

        // Add animation keyframes
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
        document.head.appendChild(style);

        // Navbar color change on scroll
        window.addEventListener('scroll', () => {
            const navbar = document.querySelector('.navbar-custom');
            if (window.scrollY > 50) {
                navbar.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.1)';
            } else {
                navbar.style.boxShadow = '0 2px 10px rgba(0, 0, 0, 0.08)';
            }
        });
    </script>
</body>
</html>
<?php
session_start();
include '../config/db_connect.php';

$error = '';
$remember_email = '';

// Check if remember-me cookie exists
if (isset($_COOKIE['admin_email'])) {
    $remember_email = htmlspecialchars($_COOKIE['admin_email']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    // Validation
    if (empty($email) || empty($password)) {
        $error = 'Email and password are required';
    } else {
        $email = mysqli_real_escape_string($conn, $email);
        
        // Query to find admin user - SIMPLIFIED WITHOUT LOCKOUT CHECKS
        $query = "SELECT id, name, email, password, status FROM users WHERE email = '$email' AND role = 'admin'";
        $result = mysqli_query($conn, $query);
        
        if (!$result) {
            $error = 'Database connection error: ' . mysqli_error($conn);
        } elseif (mysqli_num_rows($result) == 0) {
            $error = 'Invalid email or password';
        } else {
            $admin = mysqli_fetch_assoc($result);
            
            // Check if account is active
            if ($admin['status'] == 'inactive') {
                $error = 'Your account has been deactivated. Please contact support.';
            } else {
                // Verify password
                if (password_verify($password, $admin['password'])) {
                    // Login successful
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['user_name'] = $admin['name'];
                    $_SESSION['user_email'] = $admin['email'];
                    $_SESSION['role'] = 'admin';
                    $_SESSION['login_time'] = date('Y-m-d H:i:s');
                    $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];
                    
                    // Remember me
                    if ($remember_me) {
                        setcookie('admin_email', $email, time() + (30 * 24 * 60 * 60), '/');
                    } else {
                        setcookie('admin_email', '', time() - 3600, '/');
                    }
                    
                    // Update last login
                    $update_query = "UPDATE users SET updated_at = NOW() WHERE id = {$admin['id']}";
                    mysqli_query($conn, $update_query);
                    
                    header('Location: admin_dashboard.php');
                    exit();
                } else {
                    $error = 'Invalid email or password';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-wrapper {
            display: flex;
            gap: 2rem;
            max-width: 1000px;
            width: 100%;
            align-items: center;
        }

        .info-section {
            flex: 1;
            color: white;
        }

        .info-section h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .features-list {
            list-style: none;
            margin-top: 2rem;
        }

        .features-list li {
            padding: 0.7rem 0;
            padding-left: 2rem;
            position: relative;
        }

        .features-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            font-size: 1.5rem;
            color: #4ade80;
            font-weight: bold;
        }

        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 450px;
            flex: 1;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .card-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .card-header h3 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        .alert {
            border: none;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.7rem;
        }

        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.875rem 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        .input-group .form-control {
            border-right: none;
        }

        .input-group-text {
            border: 2px solid #e5e7eb;
            border-left: none;
            background: white;
            cursor: pointer;
        }

        .input-group .form-control:focus + .input-group-text {
            border-color: #667eea;
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.875rem;
            border-radius: 8px;
            transition: all 0.3s;
            margin-top: 1rem;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .divider {
            text-align: center;
            margin: 1.5rem 0;
            color: #9ca3af;
        }

        .footer-links {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .footer-links a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
            }

            .info-section {
                display: none;
            }

            .login-card {
                margin: 1rem;
            }
        }
    </style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>
<body>
    <div class="container">
        <div class="login-wrapper">
            <!-- Info Section -->
            <div class="info-section">
                <h2>Admin Portal</h2>
                <p style="font-size: 1.1rem; opacity: 0.95;">Access the complete complaint management system</p>
                <ul class="features-list">
                    <li>Manage all complaints and feedback</li>
                    <li>Add and manage staff members</li>
                    <li>Assign complaints to staff</li>
                    <li>View detailed analytics</li>
                    <li>Monitor system activities</li>
                    <li>Secure role-based access</li>
                </ul>
            </div>

            <!-- Login Card -->
            <div class="login-card">
                <div class="card-header">
                    <i class="fas fa-user-shield"></i>
                    <h3>Admin Login</h3>
                    <p style="margin: 0; opacity: 0.9;">Secure Access</p>
                </div>

                <div class="card-body">
                    <!-- Error Alert -->
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle"></i> <strong>Error:</strong>
                            <p class="mb-0 mt-2"><?php echo htmlspecialchars($error); ?></p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Info Alert with Test Credentials -->
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Test Credentials:</strong>
                        <p class="mb-1 mt-2"><strong>Email:</strong> <code>admin@usp.com</code></p>
                        <p class="mb-0"><strong>Password:</strong> <code>admin123</code></p>
                    </div>

                    <!-- Login Form -->
                    <form method="POST">
                        <!-- Email Field -->
                        <div class="form-group">
                            <label for="email" class="form-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <input 
                                type="email" 
                                class="form-control" 
                                id="email" 
                                name="email" 
                                placeholder="admin@usp.com"
                                value="<?php echo htmlspecialchars($remember_email); ?>"
                                required
                            >
                        </div>

                        <!-- Password Field -->
                        <div class="form-group">
                            <label for="password" class="form-label">
                                <i class="fas fa-lock"></i> Password
                            </label>
                            <div class="input-group">
                                <input 
                                    type="password" 
                                    class="form-control" 
                                    id="password" 
                                    name="password" 
                                    placeholder="Enter your password"
                                    required
                                >
                                <button class="input-group-text" type="button" onclick="togglePassword()">
                                    <i class="fas fa-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Remember Me -->
                        <div class="form-check mb-3">
                            <input 
                                class="form-check-input" 
                                type="checkbox" 
                                id="remember_me" 
                                name="remember_me"
                            >
                            <label class="form-check-label" for="remember_me">
                                Remember this email
                            </label>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-login w-100">
                            <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                        </button>

                        <div class="divider">or</div>

                        <!-- Links -->
                        <div class="footer-links">
                            <a href="../index.php">
                                <i class="fas fa-home"></i> Back to Home
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

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
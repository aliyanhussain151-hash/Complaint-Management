<?php
session_start();
include '../config/db_connect.php';



$error = '';
$remember_email = '';

// Check if remember-me cookie exists
if (isset($_COOKIE['admin_email'])) {
    $remember_email = htmlspecialchars($_COOKIE['admin_email']);
}

/**
 * Records a login attempt in admin_login_attempts (feeds the "login history" on the admin profile page).
 * Never allowed to break the login itself, so any DB problem here is ignored.
 */
function record_admin_login_attempt(mysqli $conn, string $email, bool $success, string $reason): void
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $ok = $success ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO admin_login_attempts (email, ip_address, success, reason, user_agent) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssiss', $email, $ip, $ok, $reason, $ua);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // ignore - logging must not block a valid login
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);

    // Validation
    if (empty($email) || empty($password)) {
        $error = 'Email and password are required';
    } else {
        // Find the admin account (prepared statement)
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin) {
            $error = 'Invalid email or not an admin account';
            record_admin_login_attempt($conn, $email, false, 'Not an admin account');
        } elseif (!password_verify($password, $admin['password'])) {
            $error = 'Invalid password';
            record_admin_login_attempt($conn, $email, false, 'Invalid password');
        } elseif ($admin['status'] == 'inactive') {
            $error = 'Your account has been deactivated. Please contact support.';
            record_admin_login_attempt($conn, $email, false, 'Account deactivated');
        } else {
            // Login successful - new session id so an old/pre-login session id can't be reused
            session_regenerate_id(true);
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['user_name'] = $admin['name'];
            $_SESSION['user_email'] = $admin['email'];
            $_SESSION['role'] = 'admin';
            $_SESSION['login_time'] = date('Y-m-d H:i:s');
            $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];

            // Remember me functionality (remembers the email only, never the password)
            if ($remember_me) {
                setcookie('admin_email', $admin['email'], time() + (30 * 24 * 60 * 60), '/');
            } else {
                setcookie('admin_email', '', time() - 3600, '/');
            }

            record_admin_login_attempt($conn, $admin['email'], true, 'Login successful');

            header('Location: admin_dashboard_professional.php');
            exit();
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Animated Background */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            animation: gradientShift 15s ease infinite;
            z-index: 1;
        }

        @keyframes gradientShift {
            0%, 100% {
                filter: brightness(1);
            }
            50% {
                filter: brightness(1.1);
            }
        }

        .container {
            position: relative;
            z-index: 2;
        }

        .login-wrapper {
            display: flex;
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Left Panel - Info */
        .info-panel {
            flex: 1;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            animation: slideInLeft 0.8s ease-out;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .logo-section {
            margin-bottom: 3rem;
        }

        .logo-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }

        .logo-text h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .logo-text p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .features-list {
            list-style: none;
            margin-top: 2rem;
        }

        .features-list li {
            padding: 0.8rem 0;
            padding-left: 2.5rem;
            position: relative;
            font-size: 1rem;
            opacity: 0.95;
        }

        .features-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            font-size: 1.5rem;
            font-weight: bold;
            color: #4ade80;
        }

        /* Right Panel - Login Form */
        .login-panel {
            flex: 1;
            animation: slideInRight 0.8s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .login-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            backdrop-filter: blur(10px);
            animation: scaleIn 0.6s ease-out;
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 2.5rem 2rem;
            text-align: center;
        }

        .card-header h3 {
            color: white;
            font-weight: 700;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .card-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }

        .header-icon {
            font-size: 3rem;
            color: white;
            margin-bottom: 1rem;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        .security-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            color: white;
            margin-top: 0.5rem;
        }

        .card-body {
            padding: 2.5rem;
        }

        /* Alert Styling */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }

        .alert-info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.7rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.875rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-control:focus {
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .input-group {
            position: relative;
        }

        .input-group-text {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            background: transparent;
            border: none;
            padding: 0.875rem 1rem;
            cursor: pointer;
            color: #6b7280;
            transition: all 0.3s;
        }

        .input-group-text:hover {
            color: #667eea;
        }

        .form-control-with-icon {
            padding-right: 2.5rem;
        }

        /* Checkbox Styling */
        .form-check {
            padding: 0;
            margin-top: 1rem;
        }

        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .form-check-input:checked {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-check-label {
            cursor: pointer;
            color: #4b5563;
            font-size: 0.95rem;
            margin-left: 0.5rem;
        }

        /* Button Styling */
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.875rem 1.5rem;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            margin-top: 1rem;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-login:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-login:active {
            transform: translateY(-1px);
        }

        .btn-login-text {
            position: relative;
            z-index: 1;
        }

        /* Links */
        .forgot-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .forgot-link:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .divider {
            position: relative;
            margin: 1.5rem 0;
            text-align: center;
            color: #9ca3af;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 45%;
            height: 1px;
            background: #e5e7eb;
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
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
            transition: all 0.3s;
        }

        .footer-links a:hover {
            color: #764ba2;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
                gap: 1rem;
            }

            .info-panel {
                display: none;
            }

            .login-card {
                margin: 1rem;
            }

            .card-body {
                padding: 1.5rem;
            }

            .card-header {
                padding: 1.5rem 1rem;
            }

            .logo-icon {
                font-size: 2.5rem;
            }
        }

        /* Loading Animation */
        .spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Password Strength */
        .password-info {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .password-info i {
            color: #667eea;
        }
    </style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>
<body>
    <div class="container">
        <div class="login-wrapper">
            <!-- Left Panel - Info -->
            <div class="info-panel d-none d-lg-flex">
                <div class="logo-section">
                    <div class="logo-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="logo-text">
                        <h1>USP Admin</h1>
                        <p>Complaint Management System</p>
                    </div>
                </div>

                <ul class="features-list">
                    <li><strong>Complete Control</strong> - Manage all complaints and staff</li>
                    <li><strong>Real-time Analytics</strong> - Track system performance</li>
                    <li><strong>Secure Access</strong> - Protected with advanced encryption</li>
                    <li><strong>User Management</strong> - Control all system users</li>
                    <li><strong>Activity Logs</strong> - Monitor all system activities</li>
                    <li><strong>Staff Assignment</strong> - Efficiently assign tasks</li>
                </ul>
            </div>

            <!-- Right Panel - Login Form -->
            <div class="login-panel">
                <div class="card login-card">
                    <!-- Card Header -->
                    <div class="card-header">
                        <div class="header-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3>Admin Portal</h3>
                        <p>Access Control Center</p>
                        <div class="security-badge">
                            <i class="fas fa-lock"></i> Secure Connection
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="card-body">
                        <!-- Error Alert -->
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle"></i> <strong>Login Failed</strong>
                                <p class="mb-0 mt-2"><?php echo $error; ?></p>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Information Alert -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Test Credentials:</strong>
                            <p class="mb-1 mt-2">
                                <strong>Email:</strong> <code>admin@usp.com</code>
                            </p>
                            <p class="mb-0">
                                <strong>Password:</strong> <code>admin123</code>
                            </p>
                        </div>

                        <!-- Login Form -->
                        <form method="POST" id="adminLoginForm">
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
                                    value="<?php echo $remember_email; ?>"
                                    required
                                    autocomplete="email"
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
                                        class="form-control form-control-with-icon" 
                                        id="password" 
                                        name="password" 
                                        placeholder="Enter your password"
                                        required
                                        autocomplete="current-password"
                                    >
                                    <button 
                                        class="input-group-text" 
                                        type="button" 
                                        id="togglePassword"
                                        title="Show/Hide Password"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-info">
                                    <i class="fas fa-shield-alt"></i>
                                    <span>Your password is encrypted and secure</span>
                                </div>
                            </div>

                            <!-- Remember Me -->
                            <div class="form-check">
                                <input 
                                    class="form-check-input" 
                                    type="checkbox" 
                                    id="remember_me" 
                                    name="remember_me"
                                    <?php echo $remember_email ? 'checked' : ''; ?>
                                >
                                <label class="form-check-label" for="remember_me">
                                    Remember this email for next time
                                </label>
                            </div>

                            <!-- Login Button -->
                            <button 
                                type="submit" 
                                class="btn btn-login w-100"
                                id="loginBtn"
                            >
                                <span class="btn-login-text">
                                    <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                                </span>
                            </button>

                            <!-- Divider -->
                            <div class="divider">or</div>

                            <!-- Help Link -->
                            <div class="text-center">
                                <a href="#" class="forgot-link" data-bs-toggle="modal" data-bs-target="#helpModal">
                                    <i class="fas fa-question-circle"></i> Need Help?
                                </a>
                            </div>
                        </form>

                        <!-- Footer Links -->
                        <div class="footer-links">
                            <p class="text-muted mb-0">
                                <i class="fas fa-home"></i>
                                <a href="../index.php">Back to Home</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-headset"></i> Help & Support
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h6 class="fw-bold text-danger">
                            <i class="fas fa-times-circle"></i> Forgot Password?
                        </h6>
                        <p class="text-muted">
                            Please contact your system administrator to reset your password. For security reasons, password resets cannot be done through this interface.
                        </p>
                    </div>

                    <div class="mb-3">
                        <h6 class="fw-bold text-info">
                            <i class="fas fa-ban"></i> Account Locked?
                        </h6>
                        <p class="text-muted">
                            If your account has been deactivated, contact the super admin for account reactivation.
                        </p>
                    </div>

                    <div class="mb-3">
                        <h6 class="fw-bold text-warning">
                            <i class="fas fa-lightbulb"></i> Tips
                        </h6>
                        <ul class="text-muted small mb-0">
                            <li>Ensure your email address is correct</li>
                            <li>Check Caps Lock is not enabled</li>
                            <li>Use a modern browser (Chrome, Firefox, Edge)</li>
                            <li>Clear browser cache if having issues</li>
                        </ul>
                    </div>

                    <hr>

                    <div class="text-center">
                        <h6 class="fw-bold">Contact Support</h6>
                        <p class="text-muted small">
                            <i class="fas fa-envelope"></i> support@usp.com<br>
                            <i class="fas fa-phone"></i> +1-800-USP-HELP<br>
                            <i class="fas fa-clock"></i> 24/7 Available
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Password Visibility
        const togglePasswordBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePasswordBtn.addEventListener('click', function() {
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Form Validation
        const loginForm = document.getElementById('adminLoginForm');
        const loginBtn = document.getElementById('loginBtn');

        loginForm.addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }

            if (!email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }

            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters');
                return false;
            }

            // Show loading state
            loginBtn.disabled = true;
            loginBtn.innerHTML = '<span class="spinner"></span><span class="btn-login-text">Logging in...</span>';
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });

        // Email autofill from cookie
        window.addEventListener('load', function() {
            const emailField = document.getElementById('email');
            if (emailField.value) {
                emailField.focus();
                document.getElementById('password').focus();
            }
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + L = Focus Email
            if (e.altKey && e.code === 'KeyL') {
                document.getElementById('email').focus();
            }
            // Alt + P = Focus Password
            if (e.altKey && e.code === 'KeyP') {
                document.getElementById('password').focus();
            }
        });
    </script>
</body>
</html>
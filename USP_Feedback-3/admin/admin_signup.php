<?php
session_start();
include '../config/db_connect.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $admin_code = trim($_POST['admin_code'] ?? '');
    
    // Expected admin registration code (change this in production)
    $EXPECTED_ADMIN_CODE = 'ADMIN2024SECRET';
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Name is required';
    } elseif (strlen($name) < 3) {
        $errors[] = 'Name must be at least 3 characters long';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    } elseif (!preg_match('/[!@#$%^&*]/', $password)) {
        $errors[] = 'Password must contain at least one special character (!@#$%^&*)';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($admin_code)) {
        $errors[] = 'Admin registration code is required';
    } elseif ($admin_code !== $EXPECTED_ADMIN_CODE) {
        $errors[] = 'Invalid admin registration code';
    }
    
    // Check if email already exists
    if (empty($errors)) {
        $email_escaped = mysqli_real_escape_string($conn, $email);
        $check_query = "SELECT id FROM users WHERE email = '$email_escaped'";
        $check_result = mysqli_query($conn, $check_query);
        
        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $errors[] = 'Email already registered';
        }
    }
    
    // Register admin if no errors
    if (empty($errors)) {
        $name_escaped = mysqli_real_escape_string($conn, $name);
        $email_escaped = mysqli_real_escape_string($conn, $email);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $insert_query = "INSERT INTO users (name, email, password, role, status, created_at) 
                        VALUES ('$name_escaped', '$email_escaped', '$hashed_password', 'admin', 'active', NOW())";
        
        if (mysqli_query($conn, $insert_query)) {
            $admin_id = mysqli_insert_id($conn);
            $success = 'Admin account created successfully! Redirecting to login...';
            
            // Optional: Log the registration
            $log_query = "INSERT INTO admin_logs (admin_id, action, description, timestamp) 
                         VALUES ($admin_id, 'REGISTRATION', 'New admin account created', NOW())";
            mysqli_query($conn, $log_query);
            
            header('refresh:3;url=admin_login.php');
        } else {
            $errors[] = 'Error creating admin account: ' . mysqli_error($conn);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Signup - USP Complaint System</title>
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

        .signup-container {
            max-width: 950px;
            width: 100%;
            display: flex;
            gap: 2rem;
            align-items: stretch;
        }

        .info-section {
            flex: 1;
            color: white;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .info-section h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .info-section p {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            opacity: 0.95;
        }

        .benefits-list {
            list-style: none;
            margin-top: 2rem;
        }

        .benefits-list li {
            padding: 0.8rem 0;
            padding-left: 2.5rem;
            position: relative;
            font-size: 1rem;
            opacity: 0.9;
        }

        .benefits-list li::before {
            content: '✓';
            position: absolute;
            left: 0;
            font-size: 1.5rem;
            color: #4ade80;
            font-weight: bold;
        }

        .signup-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px;
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
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .card-header p {
            opacity: 0.9;
            margin-bottom: 0;
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

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        .alert ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }

        .alert li {
            margin-bottom: 0.3rem;
        }

        .form-group {
            margin-bottom: 1.3rem;
        }

        .form-label {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.875rem 1rem;
            transition: all 0.3s;
            font-size: 0.95rem;
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
            transition: all 0.3s;
        }

        .input-group .form-control:focus + .input-group-text {
            border-color: #667eea;
        }

        .password-strength {
            height: 6px;
            border-radius: 3px;
            margin-top: 0.5rem;
            background-color: #e5e7eb;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s;
        }

        .strength-text {
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }

        .requirements {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.7rem;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }

        .requirement i {
            font-size: 0.8rem;
        }

        .requirement.met {
            color: #22c55e;
        }

        .requirement.unmet {
            color: #ef4444;
        }

        .btn-signup {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.875rem;
            border-radius: 8px;
            transition: all 0.3s;
            margin-top: 1rem;
        }

        .btn-signup:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-signup:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .divider {
            text-align: center;
            margin: 1.5rem 0;
            color: #9ca3af;
            position: relative;
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
            text-decoration: underline;
        }

        .admin-code-hint {
            background: #f3f4f6;
            border-left: 4px solid #667eea;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #4b5563;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .signup-container {
                flex-direction: column;
            }

            .info-section {
                display: none;
            }

            .signup-card {
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
        <div class="signup-container">
            <!-- Info Section -->
            <div class="info-section">
                <h2>Become an Admin</h2>
                <p>Create an administrator account to manage the entire system</p>
                <ul class="benefits-list">
                    <li>Full system management control</li>
                    <li>Manage all complaints and feedback</li>
                    <li>Add and manage staff members</li>
                    <li>Assign complaints to staff</li>
                    <li>View detailed analytics and reports</li>
                    <li>Monitor all system activities</li>
                    <li>Secure role-based access</li>
                </ul>
            </div>

            <!-- Signup Card -->
            <div class="signup-card">
                <div class="card-header">
                    <i class="fas fa-user-shield"></i>
                    <h3>Admin Signup</h3>
                    <p>Create Admin Account</p>
                </div>

                <div class="card-body">
                    <!-- Success Alert -->
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle"></i> <strong>Success!</strong>
                            <p class="mb-0 mt-2"><?php echo htmlspecialchars($success); ?></p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Error Alerts -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle"></i> <strong>Errors:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Signup Form -->
                    <form method="POST" id="adminSignupForm">
                        <!-- Name Field -->
                        <div class="form-group">
                            <label for="name" class="form-label">
                                <i class="fas fa-user"></i> Full Name
                            </label>
                            <input 
                                type="text" 
                                class="form-control" 
                                id="name" 
                                name="name" 
                                placeholder="Enter your full name"
                                value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                required
                            >
                            <small class="text-muted">Minimum 3 characters</small>
                        </div>

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
                                placeholder="your.email@example.com"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                required
                            >
                            <small class="text-muted">Must be a valid email</small>
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
                                    placeholder="Enter a strong password"
                                    required
                                >
                                <button class="input-group-text" type="button" onclick="togglePassword('password', 'toggleIcon1')">
                                    <i class="fas fa-eye" id="toggleIcon1"></i>
                                </button>
                            </div>
                            
                            <!-- Password Strength -->
                            <div class="password-strength">
                                <div class="password-strength-bar" id="strengthBar"></div>
                            </div>
                            <div class="strength-text" id="strengthText"></div>

                            <!-- Password Requirements -->
                            <div class="requirements">
                                <div class="requirement unmet" id="req-length">
                                    <i class="fas fa-times"></i> At least 8 characters
                                </div>
                                <div class="requirement unmet" id="req-upper">
                                    <i class="fas fa-times"></i> One uppercase letter (A-Z)
                                </div>
                                <div class="requirement unmet" id="req-lower">
                                    <i class="fas fa-times"></i> One lowercase letter (a-z)
                                </div>
                                <div class="requirement unmet" id="req-number">
                                    <i class="fas fa-times"></i> One number (0-9)
                                </div>
                                <div class="requirement unmet" id="req-special">
                                    <i class="fas fa-times"></i> One special character (!@#$%^&*)
                                </div>
                            </div>
                        </div>

                        <!-- Confirm Password Field -->
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                <i class="fas fa-lock"></i> Confirm Password
                            </label>
                            <div class="input-group">
                                <input 
                                    type="password" 
                                    class="form-control" 
                                    id="confirm_password" 
                                    name="confirm_password" 
                                    placeholder="Re-enter your password"
                                    required
                                >
                                <button class="input-group-text" type="button" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                    <i class="fas fa-eye" id="toggleIcon2"></i>
                                </button>
                            </div>
                            <small id="matchText" class="text-danger d-none mt-2">
                                <i class="fas fa-times"></i> Passwords do not match
                            </small>
                            <small id="matchTextSuccess" class="text-success d-none mt-2">
                                <i class="fas fa-check"></i> Passwords match
                            </small>
                        </div>

                        <!-- Admin Registration Code -->
                        <div class="form-group">
                            <label for="admin_code" class="form-label">
                                <i class="fas fa-key"></i> Admin Registration Code
                            </label>
                            <input 
                                type="text" 
                                class="form-control" 
                                id="admin_code" 
                                name="admin_code" 
                                placeholder="Enter admin registration code"
                                required
                            >
                            <div class="admin-code-hint">
                                <i class="fas fa-info-circle"></i> 
                                This code is required to create an admin account. Contact the system owner for the code.
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button 
                            type="submit" 
                            class="btn btn-signup w-100"
                            id="signupBtn"
                        >
                            <i class="fas fa-user-plus"></i> Create Admin Account
                        </button>

                        <div class="divider">or</div>

                        <!-- Links -->
                        <div class="footer-links">
                            <p class="mb-2">Already have an account? <a href="admin_login.php">Login here</a></p>
                            <p class="mb-0"><a href="../index.php"><i class="fas fa-home"></i> Back to Home</a></p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Password Visibility
        function togglePassword(fieldId, iconId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password Strength Checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            let requirements = [];
            
            // Check requirements
            const hasLength = password.length >= 8;
            const hasUpper = /[A-Z]/.test(password);
            const hasLower = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecial = /[!@#$%^&*]/.test(password);
            
            // Update requirement indicators
            updateRequirement('req-length', hasLength);
            updateRequirement('req-upper', hasUpper);
            updateRequirement('req-lower', hasLower);
            updateRequirement('req-number', hasNumber);
            updateRequirement('req-special', hasSpecial);
            
            // Calculate strength
            if (hasLength) strength++;
            if (hasUpper) strength++;
            if (hasLower) strength++;
            if (hasNumber) strength++;
            if (hasSpecial) strength++;
            
            // Update strength bar
            const colors = ['#ef4444', '#f97316', '#eab308', '#84cc16', '#22c55e'];
            const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            const percentage = (strength / 5) * 100;
            
            strengthBar.style.width = percentage + '%';
            strengthBar.style.backgroundColor = colors[strength - 1] || '#e5e7eb';
            strengthText.textContent = strength > 0 ? 'Strength: ' + labels[strength - 1] : '';
            strengthText.style.color = colors[strength - 1] || '#6b7280';
        });

        // Password Match Checker
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchText = document.getElementById('matchText');
            const matchTextSuccess = document.getElementById('matchTextSuccess');
            
            if (confirmPassword && password !== confirmPassword) {
                matchText.classList.remove('d-none');
                matchTextSuccess.classList.add('d-none');
            } else if (confirmPassword && password === confirmPassword) {
                matchText.classList.add('d-none');
                matchTextSuccess.classList.remove('d-none');
            } else {
                matchText.classList.add('d-none');
                matchTextSuccess.classList.add('d-none');
            }
        });

        // Update Requirement Indicator
        function updateRequirement(id, met) {
            const element = document.getElementById(id);
            const icon = element.querySelector('i');
            
            if (met) {
                element.classList.remove('unmet');
                element.classList.add('met');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-check');
            } else {
                element.classList.remove('met');
                element.classList.add('unmet');
                icon.classList.remove('fa-check');
                icon.classList.add('fa-times');
            }
        }

        // Form Validation
        document.getElementById('adminSignupForm').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const adminCode = document.getElementById('admin_code').value.trim();
            
            if (!name || !email || !password || !confirmPassword || !adminCode) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            if (name.length < 3) {
                e.preventDefault();
                alert('Name must be at least 3 characters');
                return false;
            }
            
            if (!email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters');
                return false;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }
            
            if (adminCode.length < 5) {
                e.preventDefault();
                alert('Please enter a valid admin code');
                return false;
            }
        });

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
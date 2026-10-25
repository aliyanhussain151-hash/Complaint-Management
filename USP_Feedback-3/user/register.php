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
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    // Check if email already exists
    if (empty($errors)) {
        $email_check = mysqli_real_escape_string($conn, $email);
        $query = "SELECT id FROM users WHERE email = '$email_check'";
        $result = mysqli_query($conn, $query);
        
        if (mysqli_num_rows($result) > 0) {
            $errors[] = 'Email already registered. Please use a different email or <a href="login.php">login here</a>';
        }
    }
    
    // Register user if no errors
    if (empty($errors)) {
        $name = mysqli_real_escape_string($conn, $name);
        $email = mysqli_real_escape_string($conn, $email);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (name, email, password, role) VALUES ('$name', '$email', '$hashed_password', 'user')";
        
        if (mysqli_query($conn, $query)) {
            $success = 'Registration successful! Redirecting to login...';
            header('refresh:2;url=login.php');
        } else {
            $errors[] = 'Registration failed: ' . mysqli_error($conn);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Registration - USP Complaint System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .registration-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .registration-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.5s ease-out;
        }
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.7rem;
        }
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: transform 0.2s;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            color: white;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 0.5rem;
            background-color: #e0e0e0;
        }
        .strength-text {
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }
    </style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>
<body>
    <div class="registration-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-5">
                    <div class="card registration-card">
                        <div class="card-body p-5">
                            <div class="text-center mb-4">
                                <h2 class="fw-bold mb-2">
                                    <i class="fas fa-user-plus text-primary"></i> Register
                                </h2>
                                <p class="text-muted">Create your USP account</p>
                            </div>

                            <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST" id="registrationForm">
                                <div class="form-group">
                                    <label for="name" class="form-label">
                                        <i class="fas fa-user"></i> Full Name
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           placeholder="Enter your full name" 
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                           required>
                                    <small class="text-muted">Minimum 3 characters</small>
                                </div>

                                <div class="form-group">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope"></i> Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           placeholder="your.email@example.com" 
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                           required>
                                    <small class="text-muted">We'll never share your email</small>
                                </div>

                                <div class="form-group">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock"></i> Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Enter a strong password" 
                                               required>
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength" id="strengthBar"></div>
                                    <small class="text-muted d-block">Minimum 6 characters</small>
                                    <small class="strength-text" id="strengthText"></small>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password" class="form-label">
                                        <i class="fas fa-lock"></i> Confirm Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" 
                                               placeholder="Re-enter your password" 
                                               required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small id="matchText" class="text-danger d-block mt-2" style="display: none;">
                                        <i class="fas fa-times"></i> Passwords do not match
                                    </small>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="agreeTerms" name="agreeTerms" required>
                                    <label class="form-check-label" for="agreeTerms">
                                        I agree to the <a href="#" class="text-primary">Terms & Conditions</a>
                                    </label>
                                </div>

                                <button type="submit" class="btn btn-register w-100 mb-3">
                                    <i class="fas fa-user-plus"></i> Create Account
                                </button>

                                <div class="text-center">
                                    <p class="text-muted mb-0">Already have an account? 
                                        <a href="login.php" class="text-primary fw-bold">Login here</a>
                                    </p>
                                </div>
                            </form>

                            <hr class="my-4">
                            <div class="text-center">
                                <a href="../index.php" class="text-primary text-decoration-none">
                                    <i class="fas fa-arrow-left"></i> Back to Home
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
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

        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const confirmInput = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            if (confirmInput.type === 'password') {
                confirmInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                confirmInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            let strength = 0;

            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[!@#$%^&*]/.test(password)) strength++;

            const colors = ['#e74c3c', '#f39c12', '#f1c40f', '#2ecc71', '#27ae60'];
            const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            const percentage = (strength / 5) * 100;

            strengthBar.style.width = percentage + '%';
            strengthBar.style.backgroundColor = colors[strength - 1] || '#e0e0e0';
            strengthText.textContent = strength > 0 ? 'Strength: ' + labels[strength - 1] : '';
            strengthText.style.color = colors[strength - 1] || '#6c757d';
        });

        // Password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchText = document.getElementById('matchText');

            if (confirmPassword && password !== confirmPassword) {
                matchText.style.display = 'block';
            } else {
                matchText.style.display = 'none';
            }
        });

        // Form validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>
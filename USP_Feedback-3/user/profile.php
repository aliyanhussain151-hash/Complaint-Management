<?php
session_start();
include '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

/* ======================
   GET USER DATA
====================== */
$query = "SELECT * FROM users WHERE id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    die("User not found");
}

/* ======================
   HANDLE FORMS
====================== */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    /* -------- PROFILE UPDATE -------- */
    if (isset($_POST['update_profile'])) {

        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);

        if ($name == '' || $email == '') {
            $error = "All fields required";
        } else {

            $update = "
                UPDATE users 
                SET name='$name', email='$email', updated_at=NOW()
                WHERE id=$user_id
            ";

            if (mysqli_query($conn, $update)) {
                $success = "Profile updated successfully";
                $_SESSION['user_name'] = $name;
                $user['name'] = $name;
                $user['email'] = $email;
            } else {
                $error = mysqli_error($conn);
            }
        }
    }

    /* -------- PASSWORD CHANGE -------- */
    if (isset($_POST['change_password'])) {

        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        if ($new !== $confirm) {
            $error = "Passwords do not match";
        } elseif (strlen($new) < 6) {
            $error = "Password must be at least 6 characters";
        } elseif (!password_verify($current, $user['password'])) {
            $error = "Current password is incorrect";
        } else {

            $hashed = password_hash($new, PASSWORD_DEFAULT);

            $update_pass = "
                UPDATE users 
                SET password='$hashed', updated_at=NOW()
                WHERE id=$user_id
            ";

            if (mysqli_query($conn, $update_pass)) {
                $success = "Password changed successfully";
            } else {
                $error = mysqli_error($conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Profile</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>

/* SIDEBAR */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    background: #667eea;
    padding: 20px;
    color: white;
}

.sidebar a {
    color: white;
    display: block;
    padding: 10px;
    text-decoration: none;
}

.sidebar a:hover {
    background: rgba(255,255,255,0.2);
}

/* NAVBAR */
.navbar-custom {
    position: fixed;
    left: 250px;
    right: 0;
    top: 0;
    background: white;
    padding: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 1000;
}

/* MAIN CONTENT */
.main-content {
    margin-left: 250px;
    margin-top: 70px;
    padding: 20px;
}

/* CARD */
.card-box {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}

</style>
<script src="../js/screen-fit.js"></script>
<link rel="stylesheet" href="../css/responsive.css">
<script src="../js/responsive.js" defer></script>
</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <h4>USER PANEL</h4>
    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
    <a href="submit_complaint.php"><i class="fas fa-plus"></i> New Complaint</a>
    <a href="complaint_status.php"><i class="fas fa-list"></i> Complaints</a>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<!-- NAVBAR -->
<div class="navbar-custom d-flex justify-content-between">
    <h5>My Profile</h5>
    <span><i class="fas fa-user"></i> <?php echo $_SESSION['user_name']; ?></span>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">

<?php if($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<!-- PROFILE UPDATE -->
<div class="card-box mb-4">
    <h4>Update Profile</h4>

    <form method="POST">

        <input type="hidden" name="update_profile" value="1">

        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" class="form-control"
                   value="<?php echo htmlspecialchars($user['name']); ?>">
        </div>

        <div class="mb-3">
            <label>Email</label>
            <input type="email" name="email" class="form-control"
                   value="<?php echo htmlspecialchars($user['email']); ?>">
        </div>

        <button class="btn btn-primary">Update Profile</button>
    </form>
</div>

<!-- PASSWORD CHANGE -->
<div class="card-box">
    <h4>Change Password</h4>

    <form method="POST">

        <input type="hidden" name="change_password" value="1">

        <div class="mb-3">
            <label>Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>New Password</label>
            <input type="password" name="new_password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" required>
        </div>

        <button class="btn btn-warning">Change Password</button>
    </form>
</div>

</div>

</body>
</html>
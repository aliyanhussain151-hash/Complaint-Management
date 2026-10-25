<?php
if (!isset($_SESSION)) {
    session_start();
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php">
            <strong>🛠️ USP Complaint System</strong>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item">
                        <span class="nav-link">Welcome, <?php echo $_SESSION['user_name']; ?></span>
                    </li>
                    <li class="nav-item">
                        <?php
                        $logout_url = '';
                        if ($_SESSION['role'] == 'user') {
                            $logout_url = '../user/logout.php';
                        } elseif ($_SESSION['role'] == 'admin') {
                            $logout_url = '../admin/logout.php';
                        } elseif ($_SESSION['role'] == 'staff') {
                            $logout_url = '../staff/logout.php';
                        }
                        ?>
                        <a class="nav-link" href="<?php echo $logout_url; ?>">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../user/login.php">User Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../admin/admin_login.php">Admin</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
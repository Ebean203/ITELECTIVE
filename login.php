<?php
session_start();
include 'conn.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_or_username = trim($_POST['email_or_username']);
    $password = $_POST['password'];

    // Check if admin
    $admin_query = mysqli_query($conn, "SELECT * FROM admins WHERE email = '$email_or_username' OR username = '$email_or_username'");
    if (mysqli_num_rows($admin_query) > 0) {
        $admin = mysqli_fetch_assoc($admin_query);
        if ($password === $admin['password_hash']) {
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['role'] = 'admin';
            header("Location: index.php");
            exit;
        }
    }

    // Check if customer
    $customer_query = mysqli_query($conn, "SELECT * FROM customers WHERE email = '$email_or_username'");
    if (mysqli_num_rows($customer_query) > 0) {
        $customer = mysqli_fetch_assoc($customer_query);
        if ($password === $customer['password_hash']) {
            $_SESSION['customer_id'] = $customer['customer_id'];
            $_SESSION['role'] = 'customer';
            header("Location: view_product.php?id=1"); // You can redirect to customer_home.php instead
            exit;
        }
    }

    $error = "Invalid email/username or password.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container d-flex justify-content-center align-items-center" style="height: 100vh;">
  <div class="col-md-6">
    <div class="card shadow">
      <div class="card-body">
        <h4 class="card-title text-center mb-4">Login</h4>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
          <div class="mb-3">
            <label for="email_or_username" class="form-label">Email or Username</label>
            <input type="text" class="form-control" id="email_or_username" name="email_or_username" required>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-primary">Log In</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

</body>
</html>

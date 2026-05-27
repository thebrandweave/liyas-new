<?php
session_start();
require_once __DIR__ . '/../config/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $password = $_POST['password'];

    if ($name === '' || $email === '' || $phone === '' || $password === '') {
        $error = "All fields are required.";
    } else {

        // ✅ Check if email or phone already exists
        $check = $pdo->prepare("
            SELECT user_id 
            FROM users 
            WHERE email = ? OR phone = ?
            LIMIT 1
        ");
        $check->execute([$email, $phone]);

        if ($check->fetch()) {
            $error = "Email or phone already exists.";
        } else {

            // ✅ Insert new user
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $insert = $pdo->prepare("
                INSERT INTO users (name, email, phone, password_hash)
                VALUES (?, ?, ?, ?)
            ");

            if ($insert->execute([$name, $email, $phone, $password_hash])) {
                // After signup → go to login page
                header("Location: ../login1/index.php?registered=1");
                exit;
            } else {
                $error = "Something went wrong. Please try again.";
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign Up | LIYAS Mineral Water</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">

<style>
body{
    font-family:Poppins,sans-serif;
    background:linear-gradient(135deg,#eafcff,#fff);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
}
.auth-card{
    background:#fff;
    max-width:450px;
    width:100%;
    padding:2.5rem;
    border-radius:20px;
    box-shadow:0 20px 40px rgba(0,0,0,.08);
}
.auth-card h2{text-align:center;margin-bottom:1.5rem}
.input-group{margin-bottom:1.1rem}
.input-group input{
    width:100%;
    padding:14px;
    border-radius:12px;
    border:1px solid #e2e8f0;
}
.btn-primary{
    width:100%;
    padding:14px;
    border-radius:30px;
    background:#4ad2e2;
    color:#fff;
    border:none;
    font-weight:600;
    cursor:pointer;
}
.error{
    background:#fee2e2;
    color:#991b1b;
    padding:10px;
    border-radius:8px;
    margin-bottom:1rem;
    text-align:center;
}
.auth-footer{text-align:center;margin-top:1.5rem}
.auth-footer a{color:#4ad2e2;font-weight:600;text-decoration:none}
</style>
</head>

<body>
<div class="auth-card">
    <h2>Create Account ✨</h2>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <input type="text" name="name" placeholder="Full Name" required>
        </div>
        <div class="input-group">
            <input type="email" name="email" placeholder="Email address" required>
        </div>
        <div class="input-group">
            <input type="text" name="phone" placeholder="Phone number" required>
        </div>
        <div class="input-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button class="btn-primary">Sign Up</button>
    </form>

    <div class="auth-footer">
        Already have an account? <a href="../login1/index.php">Login</a>
    </div>
</div>
</body>
</html>

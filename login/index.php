<?php
session_start();
require_once __DIR__ . '/../config/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("
        SELECT user_id, name, password_hash 
        FROM users 
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {

        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['user_name'] = $user['name'];

        $redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            header('Location: ' . rtrim(BASE_URL, '/') . $redirect);
        } else {
            header('Location: ' . rtrim(BASE_URL, '/') . '/products/');
        }
        exit;

    } else {
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | LIYAS Mineral Water</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">

<style>
body{
    font-family:'Poppins',sans-serif;
    background:linear-gradient(135deg,#eafcff,#ffffff);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
}
.auth-card{
    background:#ffffff;
    width:100%;
    max-width:420px;
    padding:2.5rem;
    border-radius:20px;
    box-shadow:0 20px 40px rgba(0,0,0,0.08);
}
.auth-card h2{
    text-align:center;
    margin-bottom:1.2rem;
    color:#0f172a;
}
.input-group{
    margin-bottom:1.2rem;
}
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
    color:#ffffff;
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
.success{
    background:#dcfce7;
    color:#166534;
    padding:10px;
    border-radius:8px;
    margin-bottom:1rem;
    text-align:center;
}
.auth-footer{
    text-align:center;
    margin-top:1.5rem;
}
.auth-footer a{
    color:#4ad2e2;
    font-weight:600;
    text-decoration:none;
}
</style>
</head>

<body>

<?php
$redirectAfterLogin = '';
if (isset($_GET['redirect']) && is_string($_GET['redirect'])) {
    $candidate = $_GET['redirect'];
    if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
        $redirectAfterLogin = $candidate;
    }
}
?>

<div class="auth-card">
    <h2>Welcome Back </h2>

    <!-- ✅ SUCCESS MESSAGE AFTER SIGNUP -->
    <?php if (isset($_GET['registered'])): ?>
        <div class="success">
            Account created successfully. Please login.
        </div>
    <?php endif; ?>

    <!-- ❌ ERROR MESSAGE -->
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- LOGIN FORM -->
    <form method="POST">
        <?php if ($redirectAfterLogin !== ''): ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectAfterLogin) ?>">
        <?php endif; ?>
        <div class="input-group">
            <input type="email" name="email" placeholder="Email address" required>
        </div>
        <div class="input-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button class="btn-primary">Login</button>
    </form>

    <div class="auth-footer">
        Don’t have an account? <a href="../signup/index.php">Create one</a>
    </div>
</div>

</body>
</html>

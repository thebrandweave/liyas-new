<?php
require_once '../config/config.php';
require_once 'includes/activity_logger.php';

// Import JWT classes
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Redirect if already logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['jwt_token'])) {
    header("Location: index.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        // Check if admin exists
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password_hash'])) {
            // Generate JWT token
            $payload = [
                'admin_id' => $admin['admin_id'],
                'email' => $admin['email'],
                'iat' => time(),
                'exp' => time() + $JWT_EXPIRE
            ];
            
            $token = JWT::encode($payload, $JWT_SECRET, 'HS256');

            // Store token in database
            $tokenStmt = $pdo->prepare("INSERT INTO admin_tokens (admin_id, token, is_valid) VALUES (?, ?, TRUE)");
            $tokenStmt->execute([$admin['admin_id'], $token]);

            // Set session variables
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['jwt_token'] = $token;
            $_SESSION['admin_name'] = $admin['username'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'];

            // Log activity
            logActivity($pdo, $admin['admin_id'], $admin['username'], 'login', null, null, "Admin logged in");

            // Redirect to dashboard
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login - Liyas Mineral Water</title>

<!-- Favicon -->
<link rel="icon" type="image/jpeg" href="../assets/images/logo/logo-bg.jpg">
<link rel="shortcut icon" type="image/jpeg" href="../assets/images/logo/logo-bg.jpg">
<link rel="apple-touch-icon" href="../assets/images/logo/logo-bg.jpg">
<link rel="icon" type="image/jpeg" sizes="32x32" href="../assets/images/logo/logo-bg.jpg">
<link rel="icon" type="image/jpeg" sizes="16x16" href="../assets/images/logo/logo-bg.jpg">

<!-- Google Font: Poppins -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link rel="stylesheet" href="assets/css/prody-admin.css">
</head>
<body>
<div class="login-container">
	<div class="login-card">
		<div class="login-logo">
			<div class="login-logo-icon" style="background: transparent; padding: 0;">
				<img src="../assets/images/logo/logo-bg.jpg" alt="Liyas Logo" style="width: 48px; height: 48px; border-radius: 12px; object-fit: cover;">
			</div>
			<h1 class="login-title">Liyas</h1>
			<p class="login-subtitle">Admin Panel</p>
		</div>

		<?php if ($error): ?>
			<div class="alert alert-error">
				<?= htmlspecialchars($error) ?>
			</div>
		<?php endif; ?>

		<form method="post" class="form-modern">
			<div class="form-group">
				<label for="email">Email Address</label>
				<input 
					type="email" 
					name="email" 
					id="email" 
					class="form-input" 
					placeholder="Enter your email" 
					required 
					autofocus
				>
			</div>
			<div class="form-group">
				<label for="password">Password</label>
				<input 
					type="password" 
					name="password" 
					id="password" 
					class="form-input" 
					placeholder="Enter your password" 
					required
				>
			</div>
			<div class="form-actions">
				<button type="submit" name="submit" class="btn btn-primary" style="width: 100%;">
					<i class='bx bx-log-in'></i> Sign In
				</button>
			</div>
			<!--<div style="text-align: center; margin-top: 1rem;">-->
			<!--	<p style="color: var(--text-secondary); font-size: 14px;">-->
			<!--		New user? <a href="signup.php" style="color: var(--blue); text-decoration: none;">Create an account</a>-->
			<!--	</p>-->
			<!--</div>-->
		</form>
	</div>
</div>
</body>
</html>

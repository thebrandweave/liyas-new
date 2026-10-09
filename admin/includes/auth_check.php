<?php
require_once dirname(__DIR__, 2) . '/config/config.php';

// Import JWT classes
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$loginUrl = BASE_URL . '/admin/login.php';

if (!isset($_SESSION['admin_id'], $_SESSION['jwt_token'])) {
    header("Location: " . $loginUrl);
    exit;
}

$admin_id = $_SESSION['admin_id'];
$jwt = $_SESSION['jwt_token'];

$stmt = $pdo->prepare("SELECT * FROM admin_tokens WHERE admin_id = ? AND token = ? AND is_valid = TRUE");
$stmt->execute([$admin_id, $jwt]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    session_unset();
    session_destroy();
    header("Location: " . $loginUrl);
    exit;
}

try {
    $decoded = JWT::decode($jwt, new Key($JWT_SECRET, 'HS256'));
    if ($decoded->exp < time()) {
        $invalidate = $pdo->prepare("UPDATE admin_tokens SET is_valid = FALSE WHERE token = ?");
        $invalidate->execute([$jwt]);
        session_unset();
        session_destroy();
        header("Location: " . $loginUrl);
        exit;
    }
} catch (Exception $e) {
    $invalidate = $pdo->prepare("UPDATE admin_tokens SET is_valid = FALSE WHERE token = ?");
    $invalidate->execute([$jwt]);
    session_unset();
    session_destroy();
    header("Location: " . $loginUrl);
    exit;
}
?>

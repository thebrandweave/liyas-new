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

// Ensure session has role and name
if (!isset($_SESSION['admin_role']) || empty($_SESSION['admin_role'])) {
    $roleStmt = $pdo->prepare("SELECT role, username FROM admins WHERE admin_id = ?");
    $roleStmt->execute([$admin_id]);
    $adminData = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if ($adminData) {
        $_SESSION['admin_role'] = !empty($adminData['role']) ? strtolower($adminData['role']) : 'staff';
        $_SESSION['admin_name'] = $adminData['username'] ?? 'Admin';
    }
}

// Enforce role-based access control for staff
$userRole = strtolower($_SESSION['admin_role'] ?? 'superadmin');
if ($userRole === 'staff') {
    $currentAdminDir = basename(dirname($_SERVER['SCRIPT_FILENAME']));
    $currentAdminFile = basename($_SERVER['SCRIPT_FILENAME']);
    $allowedStaffDirs = ['vehicles', 'zones', 'orders', 'shop-rewards', 'shops'];

    if ($currentAdminDir !== 'admin') {
        if (!in_array($currentAdminDir, $allowedStaffDirs)) {
            header("Location: " . BASE_URL . "/admin/orders/index.php");
            exit;
        }
    } else {
        if (!in_array($currentAdminFile, ['logout.php', 'login.php', 'index.php'])) {
            header("Location: " . BASE_URL . "/admin/orders/index.php");
            exit;
        }
    }
}
?>

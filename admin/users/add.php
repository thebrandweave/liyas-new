<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$form_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'editor');

    if (empty($username) || empty($email) || empty($password)) {
        $form_error = "All fields are required.";
    } else {
        try {
            $colRow = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
            if ($colRow && strpos($colRow['Type'], "'staff'") === false) {
                $pdo->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('superadmin','editor','moderator','staff') NOT NULL DEFAULT 'staff'");
            }
        } catch (Exception $e) {}

        try {
            $stmt = $pdo->prepare("INSERT INTO admins (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            quickLog($pdo, 'create', 'user', $pdo->lastInsertId(), "Created admin: $username");
            header("Location: index.php?added=1");
            exit;
        } catch (PDOException $e) {
            $form_error = "Error: Username or Email already exists.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <title>Add Admin - Liyas Admin</title>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <i class='bx bx-home'></i>
                    <span>Users</span>
                    <i class='bx bx-chevron-right'></i>
                    <span>Add New User</span>
                </div>
            </div>

            <div class="content-area">
                <div class="table-card" style="padding: 2rem;">
                    <div class="form-header" style="margin-bottom: 2rem;">
                        <h2>Add New Admin User</h2>
                    </div>

                    <?php if ($form_error): ?>
                        <div class="alert alert-error"><?= $form_error ?></div>
                    <?php endif; ?>

                    <form method="POST" class="form-modern">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" class="form-input" required placeholder="Enter username">
                        </div>

                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" class="form-input" required placeholder="Enter email">
                        </div>

                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" name="password" class="form-input" required minlength="6">
                        </div>

                        <div class="form-group" style="margin-bottom: 2rem;">
                            <label>Role *</label>
                            <select name="role" class="form-select" required>
                                <option value="staff">Staff (Vehicles, Zones, Orders, Shop Rewards only)</option>
                                <option value="editor">Editor</option>
                                <option value="moderator">Moderator</option>
                                <option value="superadmin">Super Admin</option>
                            </select>
                        </div>

                        <div class="form-actions" style="display: flex; gap: 1rem;">
                            <button type="submit" name="save_admin" class="btn-action btn-add noselect">
                                <span class="text">Add</span>
                                <span class="icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                </span>
                            </button>
                            <a href="index.php" class="btn btn-secondary" style="text-decoration: none; display: flex; align-items: center; justify-content: center; padding: 0 1.5rem; border: 1px solid var(--border-light); border-radius: 8px; color: var(--text-secondary);">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
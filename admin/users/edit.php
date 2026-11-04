<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT admin_id, username, email, role FROM admins WHERE admin_id = ?");
$stmt->execute([$id]);
$admin = $stmt->fetch();

if (!$admin) { header("Location: index.php"); exit; }

$form_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'editor');

    try {
        $colRow = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($colRow && strpos($colRow['Type'], "'staff'") === false) {
            $pdo->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('superadmin','editor','moderator','staff') NOT NULL DEFAULT 'staff'");
        }
    } catch (Exception $e) {}

    try {
        if (!empty($password)) {
            $stmt = $pdo->prepare("UPDATE admins SET username = ?, email = ?, password_hash = ?, role = ? WHERE admin_id = ?");
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE admins SET username = ?, email = ?, role = ? WHERE admin_id = ?");
            $stmt->execute([$username, $email, $role, $id]);
        }
        quickLog($pdo, 'update', 'user', $id, "Updated admin: $username");
        header("Location: index.php?updated=1");
        exit;
    } catch (PDOException $e) {
        $form_error = "Error updating user information.";
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
    <title>Edit Admin - Liyas Admin</title>
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
                    <span>Edit User</span>
                </div>
            </div>

            <div class="content-area">
                <div class="table-card" style="padding: 2rem;">
                    <div class="form-header" style="margin-bottom: 2rem;">
                        <h2>Edit Admin User: <?= htmlspecialchars($admin['username']) ?></h2>
                    </div>

                    <?php if ($form_error): ?>
                        <div class="alert alert-error"><?= $form_error ?></div>
                    <?php endif; ?>

                    <form method="POST" class="form-modern">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" class="form-input" value="<?= htmlspecialchars($admin['username']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($admin['email']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Password (Leave blank to keep current)</label>
                            <input type="password" name="password" class="form-input" placeholder="Enter new password if changing">
                        </div>

                        <div class="form-group" style="margin-bottom: 2rem;">
                            <label>Role *</label>
                            <select name="role" class="form-select">
                                <option value="staff" <?= $admin['role'] == 'staff' ? 'selected' : '' ?>>Staff (Vehicles, Zones, Orders, Shop Rewards only)</option>
                                <option value="editor" <?= $admin['role'] == 'editor' ? 'selected' : '' ?>>Editor</option>
                                <option value="moderator" <?= $admin['role'] == 'moderator' ? 'selected' : '' ?>>Moderator</option>
                                <option value="superadmin" <?= $admin['role'] == 'superadmin' ? 'selected' : '' ?>>Super Admin</option>
                            </select>
                        </div>

                        <div class="form-actions" style="display: flex; gap: 1rem;">
                            <button type="submit" name="save_admin" class="btn-action btn-edit noselect">
                                <span class="text">Update</span>
                                <span class="icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M5 18.08V19h.92l9.06-9.06-.92-.92z" fill="currentColor"/><path d="M20.71 6.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
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
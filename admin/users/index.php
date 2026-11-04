<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_admin_id = $_SESSION['admin_id'] ?? 0;
$current_page = "users";
$page_title = "Admin Users";

// Success messages from redirect
if (isset($_GET['added']) && $_GET['added'] == '1') {
    $success_message = "Admin user added successfully!";
}
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success_message = "Admin user updated successfully!";
}

// Handle delete action
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $admin_id = (int)$_GET['delete'];
    if ($admin_id == $current_admin_id) {
        $error_message = "You cannot delete your own account!";
    } else {
        try {
            $checkStmt = $pdo->prepare("SELECT username FROM admins WHERE admin_id = ?");
            $checkStmt->execute([$admin_id]);
            $admin_data = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin_data) {
                $deleted_admin_name = $admin_data['username'];
                $deleteStmt = $pdo->prepare("DELETE FROM admins WHERE admin_id = ?");
                $deleteStmt->execute([$admin_id]);
                quickLog($pdo, 'delete', 'user', $admin_id, "Deleted admin user: {$deleted_admin_name}");
                $success_message = "Admin user deleted successfully!";
            }
        } catch (PDOException $e) {
            $error_message = "Error deleting admin: " . $e->getMessage();
        }
    }
}

// Search and Pagination
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Auto-migrate schema on live server if 'staff' role or 'shops' table is missing
try {
    $colRow = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if ($colRow && strpos($colRow['Type'], "'staff'") === false) {
        $pdo->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('superadmin','editor','moderator','staff') NOT NULL DEFAULT 'staff'");
    }
    // Auto-fix any blank roles for staff accounts
    $pdo->exec("UPDATE admins SET role = 'staff' WHERE (role IS NULL OR role = '' OR role NOT IN ('superadmin','editor','moderator','staff')) AND (username LIKE '%staff%' OR email LIKE '%staff%')");
    
    // Auto-ensure shops table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS shops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_name VARCHAR(255) NOT NULL,
        location VARCHAR(255) NULL,
        phone VARCHAR(20) NULL,
        zone_id INT NULL,
        vehicle_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_shop_name (shop_name),
        INDEX idx_zone_id (zone_id),
        INDEX idx_vehicle_id (vehicle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

try {
    $search_param = "%$search%";
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username LIKE ? OR email LIKE ?");
    $count_stmt->execute([$search_param, $search_param]);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);

    $stmt = $pdo->prepare("SELECT admin_id, username, email, role, created_at FROM admins WHERE username LIKE ? OR email LIKE ? ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute([$search_param, $search_param]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $admins = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <title>Admin Users - Liyas Admin</title>
    <style>
        .role-badge { display: inline-flex; padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 12px; font-weight: 500; text-transform: capitalize; }
        .role-badge.superadmin { background: #fef3c7; color: #92400e; }
        .role-badge.editor { background: #e0f2fe; color: #0369a1; }
        .role-badge.moderator { background: #e0e7ff; color: #3730a3; }
        .role-badge.staff { background: #ecfdf5; color: #065f46; font-weight: 600; }
        .current-user { background: #f0f9ff; }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb"><i class='bx bx-home'></i> <span>Users</span></div>
                <div class="header-actions">
                    <form action="index.php" method="GET" style="display: flex; gap: 0.5rem;">
                        <input type="search" name="search" placeholder="Search admins..." value="<?= htmlspecialchars($search) ?>" class="form-input">
                        <button type="submit" class="header-btn"><i class='bx bx-search'></i></button>
                    </form>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (isset($success_message)): ?><div class="alert alert-success"><?= $success_message ?></div><?php endif; ?>
                <?php if (isset($error_message)): ?><div class="alert alert-error"><?= $error_message ?></div><?php endif; ?>

                <div class="table-card">
                    <div class="table-header">
                        <div class="table-title">All Admin Users</div>
                        <div class="table-actions">
                            <a href="add.php" class="btn-action btn-add noselect">
                                <span class="text">Add</span>
                                <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg></span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="table-responsive-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $admin): ?>
                                <tr class="<?= $admin['admin_id'] == $current_admin_id ? 'current-user' : '' ?>">
                                    <td><strong><?= htmlspecialchars($admin['username']) ?></strong> <?php if ($admin['admin_id'] == $current_admin_id): ?><small>(You)</small><?php endif; ?></td>
                                    <td><?= htmlspecialchars($admin['email']) ?></td>
                                    <td><span class="role-badge <?= htmlspecialchars($admin['role']) ?>"><?= htmlspecialchars($admin['role']) ?></span></td>
                                    <td><?= date('d-m-Y', strtotime($admin['created_at'])) ?></td>
                                    <td>
                                        <a href="edit.php?id=<?= $admin['admin_id'] ?>" class="btn-action btn-edit noselect">
                                            <span class="text">Edit</span>
                                            <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></span>
                                        </a>
                                        <?php if ($admin['admin_id'] != $current_admin_id): ?>
                                        <a href="javascript:void(0);" onclick="handleUserDelete(<?= $admin['admin_id'] ?>, '<?= addslashes($admin['username']) ?>')" class="btn-action btn-delete noselect">
                                            <span class="text">Delete</span>
                                            <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M24 20.188l-8.315-8.209 8.2-8.282-3.697-3.697-8.212 8.318-8.31-8.203-3.666 3.666 8.321 8.24-8.206 8.313 3.666 3.666 8.237-8.318 8.285 8.203z"></path></svg></span>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include '../includes/delete_confirm_modal.php'; ?>
    <script src="../assets/js/delete-confirm.js"></script>
    <script>
    function handleUserDelete(id, name) {
        if (typeof window.showDeleteConfirm === 'function') {
            window.showDeleteConfirm(id, name, (id) => window.location.href = 'index.php?delete=' + id);
        } else if (confirm(`Delete admin user "${name}"?`)) {
            window.location.href = 'index.php?delete=' + id;
        }
    }
    </script>
</body>
</html>
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "zones";
$page_title = "Edit Delivery Zone";
$error = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
$stmt->execute([$id]);
$zone = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$zone) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_zone'])) {
    $name = trim($_POST['name'] ?? '');
    $custom_slug = trim($_POST['slug'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (empty($name)) {
        $error = "Zone name is required.";
    } else {
        $slug = !empty($custom_slug) ? sanitizeZoneSlug($custom_slug) : sanitizeZoneSlug($name);

        $reserved = ['admin', 'api', 'assets', 'uploads', 'config', 'vendor', 'public', 'join', 'redeem', 'error-pages', 'zone', 'cart', 'orders', 'products'];
        if (in_array($slug, $reserved)) {
            $error = "The slug '{$slug}' is a reserved system route.";
        } else {
            // Check if slug already taken by another zone
            $check = $pdo->prepare("SELECT id FROM zones WHERE slug = ? AND id != ?");
            $check->execute([$slug, $id]);
            if ($check->fetch()) {
                $error = "Another zone already uses the route '/{$slug}'. Please choose a different slug.";
            } else {
                try {
                    $updateStmt = $pdo->prepare("UPDATE zones SET name = ?, slug = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$name, $slug, $status, $id]);

                    quickLog($pdo, 'update', 'zone', $id, "Updated zone: {$name} (/{$slug})");

                    header("Location: index.php?updated=1");
                    exit;
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Delivery Zone - Liyas Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .form-card {
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 2rem;
            max-width: 650px;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }
        .form-control {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .route-preview {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            color: #475569;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .route-preview strong {
            color: #2563eb;
            font-family: monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <a href="index.php" style="color: inherit; text-decoration: none;">Delivery Zones</a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Edit Zone</span>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem; max-width: 650px;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 0.5rem; color: #111827;">Edit Delivery Zone</h2>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 1.75rem;">
                        Update the zone details and delivery portal path.
                    </p>

                    <form action="edit.php?id=<?= $id ?>" method="POST" id="zoneForm">
                        <div class="form-group">
                            <label class="form-label" for="name">Zone Name <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? $zone['name']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slug">Portal Route Slug</label>
                            <input type="text" name="slug" id="slug" class="form-control" value="<?= htmlspecialchars($_POST['slug'] ?? $zone['slug']) ?>">
                            <div class="route-preview">
                                <i class='bx bx-link'></i>
                                Portal URL: <span id="previewUrl"><strong><?= BASE_URL ?>/<?= htmlspecialchars($zone['slug']) ?></strong></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="active" <?= (($zone['status']) === 'active') ? 'selected' : '' ?>>Active (Enabled)</option>
                                <option value="inactive" <?= (($zone['status']) === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="update_zone" class="btn-primary" style="padding: 0.65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                                Save Changes
                            </button>
                            <a href="index.php" style="padding: 0.65rem 1.25rem; background: #f1f5f9; color: #475569; text-decoration: none; border-radius: 8px; font-weight: 500; display: inline-flex; align-items: center;">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const baseUrl = <?= json_encode(BASE_URL) ?>;
        const slugInput = document.getElementById('slug');
        const previewUrl = document.getElementById('previewUrl');

        slugInput.addEventListener('input', function() {
            const slugVal = this.value.trim() || '...';
            previewUrl.innerHTML = `<strong>${baseUrl}/${slugVal}</strong>`;
        });
    </script>
</body>
</html>

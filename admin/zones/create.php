<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "zones";
$page_title = "Add Delivery Zone";
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_zone'])) {
    $name = trim($_POST['name'] ?? '');
    $custom_slug = trim($_POST['slug'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    $access_code = trim($_POST['access_code'] ?? 'ZONE2026');
    if (empty($access_code)) $access_code = 'ZONE2026';

    if (empty($name)) {
        $error = "Zone name is required.";
    } else {
        // Sanitize slug
        $slug = !empty($custom_slug) ? sanitizeZoneSlug($custom_slug) : sanitizeZoneSlug($name);

        // Disallow reserved slugs
        $reserved = ['admin', 'api', 'assets', 'uploads', 'config', 'vendor', 'public', 'join', 'redeem', 'error-pages', 'zone', 'cart', 'orders', 'products'];
        if (in_array($slug, $reserved)) {
            $error = "The slug '{$slug}' is a reserved system route. Please choose another name or slug.";
        } else {
            // Check if slug exists
            $check = $pdo->prepare("SELECT id FROM zones WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) {
                $error = "A zone with portal route '/{$slug}' already exists. Please choose a different name.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO zones (name, slug, access_code, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $slug, $access_code, $status]);
                    $new_id = $pdo->lastInsertId();

                    quickLog($pdo, 'create', 'zone', $new_id, "Created new zone: {$name} (/{$slug})");

                    header("Location: index.php?added=1");
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
    <title>Add Delivery Zone - Liyas Admin</title>
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
                    <span>Add Zone</span>
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
                    <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 0.5rem; color: #111827;">Create New Delivery Zone</h2>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 1.75rem;">
                        A dedicated delivery portal route will be generated dynamically for this zone.
                    </p>

                    <form action="create.php" method="POST" id="zoneForm">
                        <div class="form-group">
                            <label class="form-label" for="name">Zone Name <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Mangalore, Vitla, Bantwal" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus>
                            <small style="color: #94a3b8; font-size: 12px;">Example: "Mangalore Zone" or "Bantwal"</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slug">Portal Route Slug</label>
                            <input type="text" name="slug" id="slug" class="form-control" placeholder="Auto-generated (e.g. bantwal)" value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>">
                            <div class="route-preview">
                                <i class='bx bx-link'></i>
                                Portal URL: <span id="previewUrl"><strong><?= BASE_URL ?>/<span>...</span></strong></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="access_code">Authorization Access Code</label>
                            <input type="text" name="access_code" id="access_code" class="form-control" placeholder="e.g. ZONE2026" value="<?= htmlspecialchars($_POST['access_code'] ?? 'ZONE2026') ?>">
                            <small style="color: #94a3b8; font-size: 12px;">Delivery drivers and staff can enter this code at <code>/zone/orders</code> to access orders.</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="active" <?= (($_POST['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active (Enabled)</option>
                                <option value="inactive" <?= (($_POST['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="save_zone" class="btn-primary" style="padding: 0.65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                                Create Zone
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
        const nameInput = document.getElementById('name');
        const slugInput = document.getElementById('slug');
        const previewUrl = document.getElementById('previewUrl');

        function generateSlug(text) {
            // Strip 'zone' word if present
            let cleaned = text.replace(/\bzone\b/gi, '').trim();
            if (!cleaned) cleaned = text;
            return cleaned.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        function updatePreview() {
            const slugVal = slugInput.value.trim() || generateSlug(nameInput.value.trim()) || '...';
            previewUrl.innerHTML = `<strong>${baseUrl}/${slugVal}</strong>`;
        }

        nameInput.addEventListener('input', function() {
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
            updatePreview();
        });

        slugInput.addEventListener('input', function() {
            this.dataset.manual = "true";
            updatePreview();
        });

        updatePreview();
    </script>
</body>
</html>

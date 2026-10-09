<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "products";
$page_title   = "Add Product";
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $product_name     = trim($_POST['product_name'] ?? '');
    $case_stock       = (int)($_POST['case_stock'] ?? 0);
    $case_price       = (float)($_POST['case_price'] ?? 0);
    $net_content      = !empty($_POST['net_content']) ? (float)$_POST['net_content'] : null;
    $net_content_unit = in_array($_POST['net_content_unit'] ?? '', ['ML', 'L']) ? $_POST['net_content_unit'] : 'ML';
    $status           = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $description      = trim($_POST['description'] ?? '');

    if ($product_name === '' || $case_price <= 0) {
        $error = "Product Name and valid Case Price are required.";
    } elseif ($case_stock < 0) {
        $error = "Case Stock cannot be negative.";
    } else {
        $url_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $product_name), '-'));

        $image_name = null;
        $upload_dir = __DIR__ . '/../uploads/products/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $file = 'product_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $file)) {
                    $image_name = $file;
                }
            }
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO products (name, product_name, url_slug, description, price, case_price, stock, case_stock, net_content, net_content_unit, status, image)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $product_name,
                $product_name,
                $url_slug,
                $description,
                $case_price,
                $case_price,
                $case_stock,
                $case_stock,
                $net_content,
                $net_content_unit,
                $status,
                $image_name
            ]);

            $product_id = $pdo->lastInsertId();
            quickLog($pdo, 'create', 'product', $product_id, "Added product: {$product_name} with {$case_stock} cases @ ₹{$case_price}");

            header("Location: index.php?added=1");
            exit;
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Warehouse Product - Liyas Admin</title>
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
            max-width: 720px;
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
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 640px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <a href="index.php" style="color: inherit; text-decoration: none;">Products</a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Add Product</span>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem; max-width: 720px;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 0.5rem; color: #111827;">Add Warehouse Product</h2>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 1.75rem;">
                        Specify product name, case stock, case pricing, and net content specifications.
                    </p>

                    <form action="add.php" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label" for="product_name">Product Name <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="product_name" id="product_name" class="form-control" placeholder="e.g. 250ml, 500ml, 1 Litre, 2 Litre" required value="<?= htmlspecialchars($_POST['product_name'] ?? '') ?>" autofocus>
                            <small style="color: #94a3b8; font-size: 12px;">Standard formats: 250ml, 500ml, 1 Litre, 2 Litre</small>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="net_content">Net Content (Numeric Value)</label>
                                <input type="number" step="any" min="0" name="net_content" id="net_content" class="form-control" placeholder="e.g. 250, 500, 1, 2" value="<?= htmlspecialchars($_POST['net_content'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="net_content_unit">Net Content Unit</label>
                                <select name="net_content_unit" id="net_content_unit" class="form-control">
                                    <option value="ML" <?= (($_POST['net_content_unit'] ?? 'ML') === 'ML') ? 'selected' : '' ?>>Millilitres (ML)</option>
                                    <option value="L" <?= (($_POST['net_content_unit'] ?? '') === 'L') ? 'selected' : '' ?>>Litres (L)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="case_stock">Case Stocks (Available Cases) <span style="color: #ef4444;">*</span></label>
                                <input type="number" min="0" name="case_stock" id="case_stock" class="form-control" placeholder="e.g. 50" required value="<?= htmlspecialchars($_POST['case_stock'] ?? '0') ?>">
                                <small style="color: #94a3b8; font-size: 12px;">Total cases stored in central warehouse</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="case_price">Case Price (₹) <span style="color: #ef4444;">*</span></label>
                                <input type="number" step="0.01" min="0" name="case_price" id="case_price" class="form-control" placeholder="e.g. 250.00" required value="<?= htmlspecialchars($_POST['case_price'] ?? '') ?>">
                                <small style="color: #94a3b8; font-size: 12px;">Selling price per case</small>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="status">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="active" <?= (($_POST['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active (Enabled for orders)</option>
                                    <option value="inactive" <?= (($_POST['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="image">Product Image (Optional)</label>
                                <input type="file" name="image" id="image" class="form-control" accept="image/*">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">Description (Optional)</label>
                            <textarea name="description" id="description" rows="3" class="form-control" placeholder="Optional details, package specs, etc."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="save_product" class="btn-primary" style="padding: 0.65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                                Save Product
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
</body>
</html>
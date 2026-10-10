<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "products";
$page_title   = "Edit Product";
$error = '';

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
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

        $image_name = $product['image'];
        $upload_dir = __DIR__ . '/../uploads/products/';

        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $file = 'product_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $file)) {
                    if (!empty($product['image']) && file_exists($upload_dir . $product['image'])) {
                        unlink($upload_dir . $product['image']);
                    }
                    $image_name = $file;
                }
            }
        }

        try {
            $updateStmt = $pdo->prepare("
                UPDATE products 
                SET name = ?, product_name = ?, url_slug = ?, description = ?, 
                    price = ?, case_price = ?, stock = ?, case_stock = ?, 
                    net_content = ?, net_content_unit = ?, status = ?, image = ?, 
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            $updateStmt->execute([
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
                $image_name,
                $product_id
            ]);

            quickLog($pdo, 'update', 'product', $product_id, "Updated product: {$product_name}");

            header("Location: index.php?updated=1");
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
    <title>Edit Warehouse Product - Liyas Admin</title>
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
                    <span>Edit Product</span>
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
                    <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 0.5rem; color: #111827;">Edit Warehouse Product</h2>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 1.75rem;">
                        Update product specifications, case pricing, and Website stock levels.
                    </p>

                    <form action="edit.php?id=<?= $product_id ?>" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label" for="product_name">Product Name <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="product_name" id="product_name" class="form-control" required value="<?= htmlspecialchars($_POST['product_name'] ?? ($product['product_name'] ?: $product['name'])) ?>">
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="net_content">Net Content (Numeric Value)</label>
                                <input type="number" step="any" min="0" name="net_content" id="net_content" class="form-control" placeholder="e.g. 250, 500, 1, 2" value="<?= htmlspecialchars($_POST['net_content'] ?? ($product['net_content'] ? (float)$product['net_content'] : '')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="net_content_unit">Net Content Unit</label>
                                <select name="net_content_unit" id="net_content_unit" class="form-control">
                                    <?php $currentUnit = $_POST['net_content_unit'] ?? ($product['net_content_unit'] ?: 'ML'); ?>
                                    <option value="ML" <?= ($currentUnit === 'ML') ? 'selected' : '' ?>>Millilitres (ML)</option>
                                    <option value="L" <?= ($currentUnit === 'L') ? 'selected' : '' ?>>Litres (L)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="case_stock">Case Stocks (Available Cases) <span style="color: #ef4444;">*</span></label>
                                <input type="number" min="0" name="case_stock" id="case_stock" class="form-control" required value="<?= htmlspecialchars($_POST['case_stock'] ?? ($product['case_stock'] ?: $product['stock'])) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="case_price">Case Price (₹) <span style="color: #ef4444;">*</span></label>
                                <input type="number" step="0.01" min="0" name="case_price" id="case_price" class="form-control" required value="<?= htmlspecialchars($_POST['case_price'] ?? ($product['case_price'] ?: $product['price'])) ?>">
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="status">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <?php $currentStatus = $_POST['status'] ?? $product['status']; ?>
                                    <option value="active" <?= ($currentStatus === 'active') ? 'selected' : '' ?>>Active (Enabled for orders)</option>
                                    <option value="inactive" <?= ($currentStatus === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="image">Update Product Image</label>
                                <input type="file" name="image" id="image" class="form-control" accept="image/*">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">Description (Optional)</label>
                            <textarea name="description" id="description" rows="3" class="form-control"><?= htmlspecialchars($_POST['description'] ?? $product['description']) ?></textarea>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="update_product" class="btn-primary" style="padding: 0.65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                                Update Product
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
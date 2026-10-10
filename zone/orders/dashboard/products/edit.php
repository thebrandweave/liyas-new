<?php
/**
 * /zone/orders/dashboard/products/edit.php
 * Zone Orders Portal - Edit Product Details
 */

require_once dirname(__DIR__, 2) . '/auth_helper.php';

// Enforce authentication
requireZoneOrdersAuth();

// Optional activity logger
$logger_file = dirname(__DIR__, 4) . '/admin/includes/activity_logger.php';
if (file_exists($logger_file)) {
    require_once $logger_file;
}

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
        $error = "Product Name and a valid Case Price (> 0) are required.";
    } elseif ($case_stock < 0) {
        $error = "Case Stock cannot be negative.";
    } else {
        $url_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $product_name), '-'));
        $image_name = $product['image'];
        $upload_dir = dirname(__DIR__, 4) . '/admin/uploads/products/';

        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $file = 'product_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $file)) {
                    if (!empty($product['image']) && file_exists($upload_dir . $product['image'])) {
                        @unlink($upload_dir . $product['image']);
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

            if (function_exists('quickLog')) {
                quickLog($pdo, 'update', 'product', $product_id, "Updated product via Zone Portal: {$product_name}");
            }

            header("Location: index.php?updated=1");
            exit;
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

$displayName = $product['product_name'] ?: $product['name'];
$caseStock = (int)($product['case_stock'] ?: $product['stock']);
$casePrice = (float)($product['case_price'] ?: $product['price']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Edit Product - Zone Orders Portal</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --shadow-subtle: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--body-bg);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 80px;
        }

        .portal-header {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        .header-inner {
            max-width: 900px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .brand-logo {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            object-fit: cover;
            border: 1.5px solid var(--border-color);
        }

        .brand-info h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
        }

        .brand-info p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .btn-header {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-sub);
            transition: all 0.15s ease;
        }

        .btn-header:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }

        .portal-container {
            max-width: 900px;
            margin: 28px auto 0;
            padding: 0 20px;
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-subtle);
        }

        .form-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-header h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-main);
        }

        .form-header p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            background: #f8fafc;
            transition: all 0.15s ease;
        }

        .form-control:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 640px) {
            .grid-2 { grid-template-columns: 1fr; }
            .form-card { padding: 20px; }
        }

        .form-helper {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .image-preview-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 8px;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: #f8fafc;
        }

        .preview-thumb {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border-color);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 22px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .btn-cancel {
            display: inline-flex;
            align-items: center;
            padding: 10px 18px;
            background: #f1f5f9;
            color: var(--text-sub);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
            color: var(--text-main);
        }

        .alert-error {
            padding: 12px 16px;
            border-radius: 10px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
    <header class="portal-header">
        <div class="header-inner">
            <a href="index.php" class="brand-block">
                <img src="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg" alt="Liyas Logo" class="brand-logo">
                <div class="brand-info">
                    <h1>Edit Product</h1>
                    <p><?= htmlspecialchars($displayName) ?></p>
                </div>
            </a>
            <div>
                <a href="index.php" class="btn-header">
                    <i class='bx bx-arrow-back'></i>
                    <span>Back to Products</span>
                </a>
            </div>
        </div>
    </header>

    <main class="portal-container">
        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-header">
                <h2>Modify Product Specifications</h2>
                <p>Update pricing, stock levels, net content, or packaging images.</p>
            </div>

            <form action="edit.php?id=<?= $product_id ?>" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label" for="product_name">Product Name <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="product_name" id="product_name" class="form-control" required value="<?= htmlspecialchars($_POST['product_name'] ?? $displayName) ?>">
                    <div class="form-helper">Standard formats: 250ml, 500ml, 1 Litre, 2 Litre, 20L Can</div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="net_content">Net Content (Numeric Value)</label>
                        <input type="number" step="any" min="0" name="net_content" id="net_content" class="form-control" value="<?= htmlspecialchars($_POST['net_content'] ?? ($product['net_content'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="net_content_unit">Net Content Unit</label>
                        <?php $selectedUnit = $_POST['net_content_unit'] ?? ($product['net_content_unit'] ?: 'ML'); ?>
                        <select name="net_content_unit" id="net_content_unit" class="form-control">
                            <option value="ML" <?= ($selectedUnit === 'ML') ? 'selected' : '' ?>>Millilitres (ML)</option>
                            <option value="L" <?= ($selectedUnit === 'L') ? 'selected' : '' ?>>Litres (L)</option>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="case_stock">Case Stock (Available Cases) <span style="color: #dc2626;">*</span></label>
                        <input type="number" min="0" name="case_stock" id="case_stock" class="form-control" required value="<?= htmlspecialchars($_POST['case_stock'] ?? $caseStock) ?>">
                        <div class="form-helper">Cases currently stored in warehouse</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="case_price">Case Price (₹) <span style="color: #dc2626;">*</span></label>
                        <input type="number" step="0.01" min="0" name="case_price" id="case_price" class="form-control" required value="<?= htmlspecialchars($_POST['case_price'] ?? $casePrice) ?>">
                        <div class="form-helper">Default selling price per case</div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="status">Catalog Status</label>
                        <?php $selectedStatus = $_POST['status'] ?? ($product['status'] ?: 'active'); ?>
                        <select name="status" id="status" class="form-control">
                            <option value="active" <?= ($selectedStatus === 'active') ? 'selected' : '' ?>>Active (Enabled for orders)</option>
                            <option value="inactive" <?= ($selectedStatus === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="image">Replace Product Image</label>
                        <input type="file" name="image" id="image" class="form-control" accept="image/*">
                        <?php 
                            $currImg = $product['image'];
                            if (!empty($currImg) && file_exists(dirname(__DIR__, 4) . '/admin/uploads/products/' . $currImg)): 
                        ?>
                            <div class="image-preview-wrap">
                                <img src="<?= BASE_URL ?>/admin/uploads/products/<?= htmlspecialchars($currImg) ?>" alt="Current Image" class="preview-thumb">
                                <div>
                                    <div style="font-size: 12px; font-weight: 700; color: var(--text-main);">Current Image Attached</div>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($currImg) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description / Notes (Optional)</label>
                    <textarea name="description" id="description" rows="3" class="form-control"><?= htmlspecialchars($_POST['description'] ?? ($product['description'] ?? '')) ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_product" class="btn-submit">
                        <i class='bx bx-check'></i> Update Product
                    </button>
                    <a href="index.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </main>
</body>
</html>

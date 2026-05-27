<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$current_page = "products";
$page_title   = "Add Product";
$error = '';

function storeProductDraft() { $_SESSION['product_draft'] = $_POST; }
function getDraft($key, $default = '') { return $_SESSION['product_draft'][$key] ?? $default; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category_only'])) {
    storeProductDraft();
    $new_category = trim($_POST['new_category'] ?? '');
    if ($new_category !== '') {
        $check = $pdo->prepare("SELECT category_id FROM categories WHERE name = ?");
        $check->execute([$new_category]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->execute([$new_category]);
            $_SESSION['selected_category'] = $pdo->lastInsertId();
        }
    }
    header("Location: add.php");
    exit;
}

$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $name        = trim($_POST['name']);
    $price       = trim($_POST['price']);
    $category_id = $_POST['category_id'] ?? null;
    $discount    = $_POST['discount'] ?? 0;
    $stock       = $_POST['stock'] ?? 0;
    $status      = $_POST['status'] ?? 'active';
    $description = trim($_POST['description']);
    $url_slug    = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $litre = (int)($_POST['litre'] ?? 0);

    if ($name === '' || $price === '' || !$category_id) {
        $error = "Please fill all required fields.";
        storeProductDraft();
    } else {
        $image_name = null;
        $upload_dir = __DIR__ . '/../uploads/products/';

        // --- DEBUGGING ---
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                die("Failed to create upload directory: " . $upload_dir);
            }
        }
        if (!is_writable($upload_dir)) {
            die("Upload directory is not writable: " . $upload_dir);
        }
        // --- END DEBUGGING ---

        if (!empty($_FILES['image']['name'])) {
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                die("File upload error: " . $_FILES['image']['error']);
            }

            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $file = 'product_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $file)) {
                $image_name = $file;
            } else {
                die("Failed to move uploaded file.");
            }
        }

        $stmt = $pdo->prepare("INSERT INTO products (name, url_slug, description, price, discount, stock, status, category_id, image,litre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,?)");
        $stmt->execute([$name, $url_slug, $description, $price, $discount, $stock, $status, $category_id, $image_name,$litre]);
        $product_id = $pdo->lastInsertId();

        // Gallery Upload
        if (!empty($_FILES['gallery']['name'][0])) {
            foreach ($_FILES['gallery']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['gallery']['error'][$key] == 0) {
                    $g_ext = pathinfo($_FILES['gallery']['name'][$key], PATHINFO_EXTENSION);
                    $g_file = 'gallery_' . time() . '_' . uniqid() . '.' . $g_ext;
                    if (move_uploaded_file($tmp_name, $upload_dir . $g_file)) {
                        $stmt_media = $pdo->prepare("INSERT INTO products_media (product_id, file_path) VALUES (?, ?)");
                        $stmt_media->execute([$product_id, $g_file]);
                    }
                }
            }
        }

        // Attributes
        if (!empty($_POST['attr_keys'])) {
            foreach ($_POST['attr_keys'] as $index => $key) {
                $val = $_POST['attr_values'][$index] ?? '';
                if (!empty(trim($key)) && !empty(trim($val))) {
                    $stmt_attr = $pdo->prepare("INSERT INTO product_attributes (product_id, attribute, value) VALUES (?, ?, ?)");
                    $stmt_attr->execute([$product_id, trim($key), trim($val)]);
                }
            }
        }

        quickLog($pdo, 'create', 'product', $product_id, "Added product: {$name}");
        unset($_SESSION['product_draft'], $_SESSION['selected_category']);
        header("Location: index.php?added=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Product</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem}
        .category-row{display:flex;align-items:center;gap:.5rem}
        .category-plus{width:42px;height:42px;border:1px dashed #ccc;background:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center}
        .category-add-inline{display:none;margin-top:0.75rem;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
        .dropzone { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 2rem; text-align: center; background: #f8fafc; cursor: pointer; transition: 0.3s; position: relative; min-height: 150px;}
        .dropzone:hover, .dropzone.dragover { border-color: #3b82f6; background: #eff6ff; }
        .preview-img { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; margin: 5px; border: 1px solid #e2e8f0; }
        .attribute-row { display: flex; gap: 1rem; margin-bottom: 0.75rem; align-items: center; }
        .btn-remove-attr { background: #fee2e2; color: #ef4444; border: none; width: 38px; height: 38px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
<div class="container">
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="content-area">
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="table-card">
                <div class="table-header"><div class="table-title">Add New Product</div></div>
                <div style="padding:2rem">
                    <form method="POST" id="mainProductForm" enctype="multipart/form-data" class="form-modern">
                        <div class="grid-3">
                            <div class="form-group"><label>Product Name *</label><input type="text" name="name" class="form-input" value="<?= htmlspecialchars(getDraft('name')) ?>" required></div>
                            <div class="form-group">
                                <label>Category *</label>
                                <div class="category-row">
                                    <select name="category_id" class="form-select" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['category_id'] ?>" <?= (($_SESSION['selected_category'] ?? getDraft('category_id')) == $cat['category_id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="category-plus" onclick="toggleCategoryAdd()"><i class='bx bx-plus'></i></button>
                                </div>
                                <div id="categoryInlineBox" class="category-add-inline">
                                    <div style="display:flex; gap:0.5rem">
                                        <input type="text" id="temp_cat_name" class="form-input" placeholder="New Category...">
                                        <button type="button" onclick="submitQuickCategory()" class="btn-action btn-add" style="padding:0 15px">Add</button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group"><label>Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                        </div>

                        <div style="display: flex; gap: 1.5rem; margin-top: 1.5rem;">
                            <div class="form-group" style="flex:1;">
                                <label><strong>Main Image</strong></label>
                                <div class="dropzone" id="mainDropzone">
                                    <i class='bx bx-image-add' style="font-size:2rem"></i>
                                    <p>Click/Drop Main Image</p>
                                    <input type="file" name="image" id="mainInput" hidden accept="image/*">
                                    <div id="mainPreview"></div>
                                </div>
                            </div>
                            <div class="form-group" style="flex:2;">
                                <label><strong>Product Gallery</strong></label>
                                <div class="dropzone" id="galleryDropzone">
                                    <i class='bx bx-images' style="font-size:2rem"></i>
                                    <p>Click/Drop Gallery Images</p>
                                    <input type="file" name="gallery[]" id="galleryInput" hidden multiple accept="image/*">
                                    <div id="galleryPreview" style="display:flex; flex-wrap:wrap; justify-content:center;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:2rem; background:#f8fafc; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0;">
                            <label><strong>Product Specifications</strong></label>
                            <div id="attributesContainer" style="margin-top:1rem;">
                                <div class="attribute-row">
                                    <input type="text" name="attr_keys[]" class="form-input" placeholder="e.g. Volume">
                                    <input type="text" name="attr_values[]" class="form-input" placeholder="e.g. 20 Liters">
                                    <button type="button" class="btn-remove-attr" onclick="this.parentElement.remove()"><i class='bx bx-trash'></i></button>
                                </div>
                            </div>
                            <button type="button" onclick="addAttributeRow()" class="btn-action" style="margin-top:0.5rem; background:#fff; border:1px solid #cbd5e1; color:#475569;"><i class='bx bx-plus'></i> Add Attribute</button>
                        </div>

                        <div class="form-group" style="margin-top:1.5rem"><label>Description</label><textarea name="description" class="form-textarea" rows="4"><?= htmlspecialchars(getDraft('description')) ?></textarea></div>
                        
                        <div class="grid-3">
                            <div class="form-group"><label>Price (₹) *</label><input type="number" step="0.01" name="price" class="form-input" value="<?= getDraft('price') ?>" required></div>
                            <div class="form-group"><label>Discount (%)</label><input type="number" step="0.01" name="discount" class="form-input" value="<?= getDraft('discount', '0') ?>"></div>
                            <div class="form-group"><label>Stock</label><input type="number" name="stock" class="form-input" value="<?= getDraft('stock', '0') ?>"></div>
                           <div class="form-group"><label>Volume (Litres)</label><input type="number" name="litre" class="form-input" value="<?= getDraft('litre', '0') ?>"></div>

                        </div>

                        <div class="form-actions" style="margin-top: 2rem;"><button type="submit" name="save_product" class="btn-action btn-add">Save Product</button></div>
                    </form>
                    <form method="POST" id="hiddenCategoryForm" style="display:none;"><input type="hidden" name="add_category_only" value="1"><input type="hidden" name="new_category" id="real_cat_input"></form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function setupDropzone(dropzoneId, inputId, previewId, isMultiple) {
    const dropzone = document.getElementById(dropzoneId);
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    
    dropzone.addEventListener('click', () => input.click());
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(e => {
        dropzone.addEventListener(e, ev => {
            ev.preventDefault();
            if (e === 'dragover') dropzone.classList.add('dragover');
            if (e === 'dragleave' || e === 'drop') dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', e => {
        input.files = e.dataTransfer.files;
        handleFiles(input.files);
    });

    input.addEventListener('change', () => handleFiles(input.files));

    function handleFiles(files) {
        if (!isMultiple) preview.innerHTML = '';
        Array.from(files).forEach(file => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'preview-img';
                    preview.appendChild(img);
                };
                reader.readAsDataURL(file);
            }
        });
    }
}
setupDropzone('mainDropzone', 'mainInput', 'mainPreview', false);
setupDropzone('galleryDropzone', 'galleryInput', 'galleryPreview', true);

function addAttributeRow() {
    const container = document.getElementById('attributesContainer');
    const row = document.createElement('div');
    row.className = 'attribute-row';
    row.innerHTML = `<input type="text" name="attr_keys[]" class="form-input" placeholder="Attribute Name"><input type="text" name="attr_values[]" class="form-input" placeholder="Value"><button type="button" class="btn-remove-attr" onclick="this.parentElement.remove()"><i class='bx bx-trash'></i></button>`;
    container.appendChild(row);
}
function toggleCategoryAdd(){ const box = document.getElementById('categoryInlineBox'); box.style.display = box.style.display === 'none' ? 'block' : 'none'; }
function submitQuickCategory() { const name = document.getElementById('temp_cat_name').value; if(!name) return alert("Enter category name"); document.getElementById('real_cat_input').value = name; document.getElementById('hiddenCategoryForm').submit(); }
</script>
</body>
</html>
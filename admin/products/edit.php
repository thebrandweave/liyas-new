<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$current_page = "products";
$page_title   = "Edit Product";
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$product_id) { header("Location: index.php"); exit; }

$upload_dir = __DIR__ . '/../uploads/products/';

// Handle Media Deletion
if (isset($_GET['delete_media'])) {
    $media_id = (int)$_GET['delete_media'];
    $stmt = $pdo->prepare("SELECT file_path FROM products_media WHERE media_id = ? AND product_id = ?");
    $stmt->execute([$media_id, $product_id]);
    $media = $stmt->fetch();
    if ($media) {
        $full_path = $upload_dir . $media['file_path'];
        if (file_exists($full_path)) unlink($full_path);
        $pdo->prepare("DELETE FROM products_media WHERE media_id = ?")->execute([$media_id]);
    }
    header("Location: edit.php?id=" . $product_id); exit;
}

$categories = $pdo->query("SELECT category_id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) { header("Location: index.php"); exit; }

$media_stmt = $pdo->prepare("SELECT * FROM products_media WHERE product_id = ?");
$media_stmt->execute([$product_id]);
$gallery_images = $media_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $name = trim($_POST['name']);
    $price = trim($_POST['price']);
    $category_id = $_POST['category_id'];
    $image_name = $product['image']; 
    $litre = (int)($_POST['litre'] ?? 0);

    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    if (!empty($_FILES['image']['name'])) {
        if ($image_name && file_exists($upload_dir . $image_name)) unlink($upload_dir . $image_name);
        $file = 'product_' . time() . '_' . uniqid() . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $file);
        $image_name = $file;
    }

    if (!empty($_FILES['gallery']['name'][0])) {
        foreach ($_FILES['gallery']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['gallery']['error'][$key] == 0) {
                $g_file = 'gallery_' . time() . '_' . uniqid() . '.' . pathinfo($_FILES['gallery']['name'][$key], PATHINFO_EXTENSION);
                if (move_uploaded_file($tmp_name, $upload_dir . $g_file)) {
                    $pdo->prepare("INSERT INTO products_media (product_id, file_path) VALUES (?, ?)")->execute([$product_id, $g_file]);
                }
            }
        }
    }

    $stmt = $pdo->prepare("UPDATE products SET name=?, price=?, category_id=?, image=?, description=?, discount=?, stock=?, status=?,litre=? WHERE product_id=?");
    $stmt->execute([$name, $price, $category_id, $image_name, $_POST['description'], $_POST['discount'], $_POST['stock'], $_POST['status'],$litre, $product_id]);

    $pdo->prepare("DELETE FROM product_attributes WHERE product_id = ?")->execute([$product_id]);
    if (!empty($_POST['attr_keys'])) {
        foreach ($_POST['attr_keys'] as $index => $key) {
            $val = $_POST['attr_values'][$index] ?? '';
            if (!empty(trim($key)) && !empty(trim($val))) {
                $stmt_attr = $pdo->prepare("INSERT INTO product_attributes (product_id, attribute, value) VALUES (?, ?, ?)");
                $stmt_attr->execute([$product_id, trim($key), trim($val)]);
            }
        }
    }
    
    quickLog($pdo, 'update', 'product', $product_id, "Updated product: {$name}");
    header("Location: index.php?updated=1"); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Product</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem}
        .gallery-grid{display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0}
        .gallery-item{position:relative;width:100px;height:100px}
        .gallery-item img{width:100%;height:100%;object-fit:cover;border-radius:8px;border:1px solid #ddd}
        .del-btn{position:absolute;top:-5px;right:-5px;background:#ff4d4d;color:white;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:12px}
        .dropzone { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 1.5rem; text-align: center; background: #ffffff; cursor: pointer; transition: 0.3s; position: relative; }
        .dropzone:hover, .dropzone.dragover { border-color: #3b82f6; background: #eff6ff; }
        .preview-img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; margin: 5px; border: 1px solid #e2e8f0; }
        .attribute-row { display: flex; gap: 1rem; margin-bottom: 0.75rem; align-items: center; }
        .btn-remove-attr { background: #fee2e2; color: #ef4444; border: none; width: 38px; height: 38px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
<div class="container">
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="content-area">
            <div class="table-card">
                <div class="table-header"><div class="table-title">Edit Product: <?= htmlspecialchars($product['name']) ?></div></div>
                <div style="padding:2rem">
                    <form method="POST" enctype="multipart/form-data" class="form-modern">
                        <div class="grid-3">
                            <div class="form-group"><label>Name</label><input type="text" name="name" class="form-input" value="<?= htmlspecialchars($product['name']) ?>" required></div>
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category_id" class="form-select" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>" <?= $cat['category_id']==$product['category_id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?= $product['status']=='active'?'selected':'' ?>>Active</option>
                                    <option value="inactive" <?= $product['status']=='inactive'?'selected':'' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:1.5rem">
                            <label><strong>Main Product Image</strong></label>
                            <div class="dropzone" id="mainDropzone" style="max-width: 300px;">
                                <div id="mainPreview">
                                    <?php if($product['image']): ?>
                                        <img src="../uploads/products/<?= $product['image'] ?>" class="preview-img">
                                    <?php endif; ?>
                                </div>
                                <p style="font-size: 0.7rem;">Click to change image</p>
                                <input type="file" name="image" id="mainInput" hidden accept="image/*">
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:2rem; background:#f8fafc; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0;">
                            <label><strong>Product Gallery</strong></label>
                            <div class="dropzone" id="galleryDropzone" style="margin: 1rem 0;">
                                <i class='bx bx-plus-circle'></i>
                                <p style="font-size:0.8rem; color:#64748b;">Drop new gallery images here</p>
                                <input type="file" name="gallery[]" id="galleryInput" hidden multiple accept="image/*">
                                <div id="galleryPreview" style="display:flex; flex-wrap:wrap; justify-content:center;"></div>
                            </div>
                            <div class="gallery-grid">
                                <?php foreach($gallery_images as $img): ?>
                                    <div class="gallery-item">
                                        <img src="../uploads/products/<?= $img['file_path'] ?>">
                                        <a href="edit.php?id=<?= $product_id ?>&delete_media=<?= $img['media_id'] ?>" class="del-btn" onclick="return confirm('Delete this image?')">×</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:2rem; background:#f8fafc; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0;">
                            <label><strong>Product Specifications</strong></label>
                            <div id="attributesContainer" style="margin-top:1rem;">
                                <?php
                                $stmt_attr_load = $pdo->prepare("SELECT * FROM product_attributes WHERE product_id = ?");
                                $stmt_attr_load->execute([$product_id]);
                                while ($attr = $stmt_attr_load->fetch()): ?>
                                    <div class="attribute-row">
                                        <input type="text" name="attr_keys[]" class="form-input" placeholder="Attribute Name" value="<?= htmlspecialchars($attr['attribute']) ?>">
                                        <input type="text" name="attr_values[]" class="form-input" placeholder="Value" value="<?= htmlspecialchars($attr['value']) ?>">
                                        <button type="button" class="btn-remove-attr" onclick="this.parentElement.remove()"><i class='bx bx-trash'></i></button>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                            <button type="button" onclick="addAttributeRow()" class="btn-action" style="margin-top:0.5rem; background:#fff; border:1px solid #cbd5e1; color:#475569;">
                                <i class='bx bx-plus'></i> Add Attribute
                            </button>
                        </div>

                        <div class="form-group" style="margin-top:1.5rem">
                            <label>Description</label>
                            <textarea name="description" class="form-textarea" rows="3"><?= htmlspecialchars($product['description']) ?></textarea>
                        </div>

                        <div class="grid-3">
                            <div class="form-group"><label>Price (₹)</label><input type="number" step="0.01" name="price" class="form-input" value="<?= $product['price'] ?>"></div>
                            <div class="form-group"><label>Discount (%)</label><input type="number" step="0.01" name="discount" class="form-input" value="<?= $product['discount'] ?>"></div>
                            <div class="form-group"><label>Stock</label><input type="number" name="stock" class="form-input" value="<?= $product['stock'] ?>"></div>
                            <div class="form-group"><label>Volume (Litres)</label><input type="number" name="litre" class="form-input" value="<?= $product['litre'] ?>"></div>

                        </div>

                        <div class="form-actions" style="margin-top:2rem">
                            <button type="submit" name="update_product" class="btn-action btn-add">Update Product</button>
                            <a href="index.php" class="btn-action" style="background:#6c757d; color:white; text-decoration:none; padding:10px 20px; border-radius:8px">Cancel</a>
                        </div>
                    </form>
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
    
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
    });

    dropzone.addEventListener('drop', (e) => { 
        input.files = e.dataTransfer.files; 
        handleFiles(input.files); 
    });

    input.addEventListener('change', () => handleFiles(input.files));

    function handleFiles(files) {
        if (!isMultiple) preview.innerHTML = '';
        Array.from(files).forEach(file => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
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
    row.innerHTML = `
        <input type="text" name="attr_keys[]" class="form-input" placeholder="Attribute Name">
        <input type="text" name="attr_values[]" class="form-input" placeholder="Value">
        <button type="button" class="btn-remove-attr" onclick="this.parentElement.remove()"><i class='bx bx-trash'></i></button>
    `;
    container.appendChild(row);
}
</script>
</body>
</html>
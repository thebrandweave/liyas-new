
<style>
    textarea {
    width: 100%;
    min-height: 120px;
    padding: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.5); /* Glass effect */
    backdrop-filter: blur(5px);
    resize: vertical; /* Allows user to adjust height only */
    font-family: inherit;
    transition: border-color 0.3s ease;
}

textarea:focus {
    outline: none;
    border-color: #3b82f6; /* Liyas Blue */
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
</style>
<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';

$db = getCampaignDB();
$id = $_GET['id'] ?? null;

if (!$id) { header("Location: index.php"); exit(); }

// 1. Fetch Existing Campaign Data
$stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
$stmt->execute([$id]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) { die("Campaign not found."); }

// 2. Fetch Existing Questions
$stmtQ = $db->prepare("SELECT * FROM campaign_questions WHERE campaign_id = ? ORDER BY sort_order ASC");
$stmtQ->execute([$id]);
$questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Poster Asset
$stmtA = $db->prepare("SELECT * FROM campaign_assets WHERE campaign_id = ? LIMIT 1");
$stmtA->execute([$id]);
$asset = $stmtA->fetch(PDO::FETCH_ASSOC);

// --- UPDATE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $stmtUpdate = $db->prepare("UPDATE campaigns SET title = ?, description = ?, slug = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmtUpdate->execute([
            $_POST['title'],  $_POST['description'], $_POST['slug'], $_POST['status'], $_POST['start_date'],
            !empty($_POST['end_date']) ? $_POST['end_date'] : null, $id
        ]);

        if (!empty($_FILES['poster']['name'])) {
            $target_dir = "../../uploads/campaigns/";
            $file_ext = strtolower(pathinfo($_FILES["poster"]["name"], PATHINFO_EXTENSION));
            $rel_path = "uploads/campaigns/poster_" . $id . "_" . time() . "." . $file_ext;
            
            if (move_uploaded_file($_FILES["poster"]["tmp_name"], "../../" . $rel_path)) {
                $db->prepare("DELETE FROM campaign_assets WHERE campaign_id = ?")->execute([$id]);
                $db->prepare("INSERT INTO campaign_assets (campaign_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)")
                   ->execute([$id, $_FILES['poster']['name'], $rel_path, ($file_ext=='pdf'?'pdf':'image')]);
            }
        }

// Delete old questions and re-insert
$db->prepare("DELETE FROM campaign_questions WHERE campaign_id = ?")->execute([$id]);

if (!empty($_POST['questions'])) {

    // 🔒 allowed field types
    $allowedTypes = [
        'text',
        'number',
        'dropdown',
        'image_upload',
        'video_upload'
    ];

    $stmtInsertQ = $db->prepare("
        INSERT INTO campaign_questions
        (campaign_id, question_label, field_type, is_required, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($_POST['questions'] as $i => $q) {

        // ✅ safe field type handling
        $type = isset($q['type']) && in_array($q['type'], $allowedTypes)
            ? $q['type']
            : 'text';

        $stmtInsertQ->execute([
            $id,
            trim($q['label']),
            $type,
            isset($q['required']) ? 1 : 0,
            $i
        ]);
    }
}


        $db->commit();
        header("Location: index.php?updated=1"); exit();
    } catch (Exception $e) { if($db->inTransaction()) $db->rollBack(); $error = $e->getMessage(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Campaign - Liyas Admin</title>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        .form-card { background: #fff; padding: 2rem; border-radius: 20px; border: 1px solid var(--border-light); }
        .input-group { margin-bottom: 1.5rem; }
        .input-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 14px; }
        .input-group input, .input-group select { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd; }
        .question-row { background: #f8fafc; padding: 1.2rem; border-radius: 12px; margin-bottom: 1rem; border: 1px solid #edf2f7; }
        .current-asset { margin-top: 10px; font-size: 12px; color: #2563eb; }
        .trash-btn { color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.2rem; transition: 0.2s; }
        .trash-btn:hover { color: #b91c1c; }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <div class="header"><div class="breadcrumb"><span>Campaigns / Edit / <?= htmlspecialchars($campaign['title']) ?></span></div></div>
            <div class="content-area">
                <?php if(isset($error)): ?>
                    <div style="color:red; margin-bottom: 1rem;"><?= $error ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="form-card">
                    <h2 style="margin-bottom: 1.5rem;">Edit Campaign Details</h2>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="input-group">
                            <label>Title</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($campaign['title']) ?>" required>
                        </div>
                        <div class="input-group">   
                            <label>Poster (Leave blank to keep current)</label>
                            <input type="file" name="poster" accept=".jpg,.jpeg,.png,.pdf">
                            <?php if($asset): ?>
                                <div class="current-asset"><i class='bx bx-link'></i> Current: <a href="../../<?= $asset['file_path'] ?>" target="_blank">View File</a></div>
                            <?php endif; ?>
                        </div>
<div class="input-group">
    <label>Description</label>
    <textarea name="description" required><?= htmlspecialchars($campaign['description']) ?></textarea>
</div>
                        <div class="input-group"><label>Slug</label><input type="text" name="slug" value="<?= htmlspecialchars($campaign['slug']) ?>" required></div>
                        <div class="input-group"><label>Start Date</label><input type="date" name="start_date" value="<?= $campaign['start_date'] ?>" required></div>
                        <div class="input-group"><label>End Date</label><input type="date" name="end_date" value="<?= $campaign['end_date'] ?>"></div>
                        <div class="input-group"><label>Status</label>
                            <select name="status">
                                <option value="active" <?= $campaign['status']=='active'?'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $campaign['status']=='inactive'?'selected':'' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <hr style="margin:2rem 0; opacity:0.1;">
                    <h2>Form Questions</h2>
                    <div id="q-container">
                        <?php foreach($questions as $index => $q): ?>
                            <div class="question-row" id="row_<?= $index ?>">
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="text" name="questions[<?= $index ?>][label]" value="<?= htmlspecialchars($q['question_label']) ?>" required style="flex:2; padding:8px; border-radius:6px; border:1px solid #ddd;">
                                    <select name="questions[<?= $index ?>][type]"
        style="flex:1; padding:8px; border-radius:6px; border:1px solid #ddd;">

    <option value="text"
        <?= $q['field_type'] === 'text' ? 'selected' : '' ?>>
        Text Input
    </option>

    <option value="number"
        <?= $q['field_type'] === 'number' ? 'selected' : '' ?>>
        Number
    </option>

    <option value="dropdown"
        <?= $q['field_type'] === 'dropdown' ? 'selected' : '' ?>>
        Dropdown Menu
    </option>

    <option value="image_upload"
        <?= $q['field_type'] === 'image_upload' ? 'selected' : '' ?>>
        Image (Drag & Drop)
    </option>



</select>

                                    <label style="font-size:12px;"><input type="checkbox" name="questions[<?= $index ?>][required]" <?= $q['is_required']?'checked':'' ?>> Required</label>
                                    <button type="button" class="trash-btn" onclick="document.getElementById('row_<?= $index ?>').remove()"><i class='bx bx-trash'></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="button" class="table-btn" onclick="addQ()" style="background:#f1f5f9; color:#475569;">+ Add Field</button>
                    
                    <div style="text-align:right; margin-top:2rem;">
                        <button type="submit" class="table-btn" style="background:var(--blue); color:white;">Update Campaign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Use a high starting index to avoid conflict with existing items
        let count = <?= count($questions) ?>; 
        
        function addQ() {
            const html = `
            <div class="question-row" id="row_${count}">
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" name="questions[${count}][label]" placeholder="Field Name (e.g. Upload your Receipt)" required style="flex:2; padding:8px; border-radius:6px; border:1px solid #ddd;">
                    <select name="questions[${count}][type]" style="flex:1; padding:8px; border-radius:6px; border:1px solid #ddd;">
                        <option value="text">Text Input</option>
                        <option value="number">Number</option>
                        <option value="dropdown">Dropdown Menu</option>
                        <option value="image_upload">Image (Drag & Drop)</option>
                        <option value="video_upload">Video (Drag & Drop)</option>
                    </select>
                    <label style="font-size:12px;"><input type="checkbox" name="questions[${count}][required]"> Required</label>
                    <button type="button" class="trash-btn" onclick="document.getElementById('row_${count}').remove()"><i class='bx bx-trash'></i></button>
                </div>
            </div>`;
            document.getElementById('q-container').insertAdjacentHTML('beforeend', html); 
            count++;
        }
    </script>
</body>
</html>
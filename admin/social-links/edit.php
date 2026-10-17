<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$current_page = "social-links";

/* VALIDATE ID */
$social_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$social_id) {
    header("Location: index.php");
    exit;
}

/* FETCH SOCIAL LINK */
$stmt = $pdo->prepare("SELECT * FROM social_links WHERE social_id=?");
$stmt->execute([$social_id]);
$social = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$social) {
    header("Location: index.php");
    exit;
}

/* PLATFORM CONFIG */
$platforms = [
    'Instagram' => [
        'icon' => 'bx bxl-instagram',
        'base' => 'https://instagram.com/',
        'color'=> 'linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4)'
    ],
    'Facebook' => [
        'icon' => 'bx bxl-facebook',
        'base' => 'https://facebook.com/',
        'color'=> '#1877F2'
    ],
    'Twitter' => [
        'icon' => 'bx bxl-twitter',
        'base' => 'https://twitter.com/',
        'color'=> '#1DA1F2'
    ],
    'LinkedIn' => [
        'icon' => 'bx bxl-linkedin',
        'base' => 'https://linkedin.com/in/',
        'color'=> '#0A66C2'
    ],
    'YouTube' => [
        'icon' => 'bx bxl-youtube',
        'base' => 'https://youtube.com/',
        'color'=> '#FF0000'
    ],
    'WhatsApp' => [
        'icon' => 'bx bxl-whatsapp',
        'base' => 'https://wa.me/',
        'color'=> '#25D366'
    ]
];

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $platform = $_POST['platform'];
    $status   = $_POST['status'];
    $inputUrl = trim($_POST['url']);
    $sort     = $_POST['sort_order'] !== '' ? (int)$_POST['sort_order'] : 999;

    $icon = $platforms[$platform]['icon'];
    $base = $platforms[$platform]['base'];

    $url = preg_match('#^https?://#', $inputUrl)
         ? $inputUrl
         : rtrim($base,'/') . '/' . ltrim($inputUrl,'/');

    $stmt = $pdo->prepare("
        UPDATE social_links SET
            platform = ?,
            icon_class = ?,
            url = ?,
            sort_order = ?,
            status = ?
        WHERE social_id = ?
    ");
    $stmt->execute([
        $platform,
        $icon,
        $url,
        $sort,
        $status,
        $social_id
    ]);

    quickLog($pdo,'update','social_link',$social_id,"Updated $platform");
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Social Media</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link rel="stylesheet" href="../assets/css/prody-admin.css">

<style>
.icon-preview{
    width:48px;height:48px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:22px;
}
</style>
</head>

<body>
<div class="container">

<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<div class="content-area">

<div class="table-card">
<div class="table-header">
    <div class="table-title">Edit Social Media</div>
</div>

<div style="padding:2rem">

<form method="POST" class="form-modern">

<!-- PLATFORM / STATUS / SORT -->
<div class="grid-3">

<div class="form-group">
<label>Platform</label>
<select name="platform" id="platform" class="form-select" onchange="updatePreview()" required>
<?php foreach ($platforms as $name=>$data): ?>
<option value="<?= $name ?>" <?= $social['platform']===$name?'selected':'' ?>>
    <?= $name ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="form-group">
<label>Status</label>
<select name="status" class="form-select">
<option value="active" <?= $social['status']=='active'?'selected':'' ?>>Active</option>
<option value="inactive" <?= $social['status']=='inactive'?'selected':'' ?>>Inactive</option>
</select>
</div>

<div class="form-group">
<label>Sort Order</label>
<input type="text"
       name="sort_order"
       class="form-input"
       value="<?= $social['sort_order']==999?'':$social['sort_order'] ?>"
       placeholder="Auto">
</div>

</div>

<!-- URL -->
<div class="form-group">
<label>Username or Full URL</label>
<input type="text"
       name="url"
       id="urlInput"
       class="form-input"
       value="<?= htmlspecialchars($social['url']) ?>"
       required>
<small class="text-muted-custom">
Changing platform auto-updates base URL.
</small>
</div>

<!-- PREVIEW -->
<div class="form-group">
<label>Preview</label>
<div style="display:flex;gap:12px;align-items:center">
<div id="iconPreview" class="icon-preview"></div>
<strong id="textPreview"></strong>
</div>
</div>

<!-- ACTIONS -->
<div class="form-actions" style="margin-top:2rem">
<button class="btn-action btn-add">Update Social Media</button>
<a href="index.php"
   class="btn-action"
   style="background:#6c757d;color:#fff;text-decoration:none">
   Cancel
</a>
</div>

</form>

</div>
</div>

</div>
</div>
</div>

<script>
const platforms = <?= json_encode($platforms) ?>;

function updatePreview(){
    const p = document.getElementById('platform').value;
    const iconBox = document.getElementById('iconPreview');
    const urlInput = document.getElementById('urlInput');

    iconBox.innerHTML = `<i class="${platforms[p].icon}"></i>`;
    iconBox.style.background = platforms[p].color;
    document.getElementById('textPreview').innerText = p;

    urlInput.value = platforms[p].base;
}

document.addEventListener('DOMContentLoaded', updatePreview);
</script>

</body>
</html>

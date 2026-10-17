<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$current_page = "social-links";
$error = '';

$platforms = [
    'Instagram' => [
        'icon'=>'bx bxl-instagram',
        'base'=>'https://instagram.com/',
        'color'=>'linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4)'
    ],
    'Facebook'  => ['icon'=>'bx bxl-facebook','base'=>'https://facebook.com/','color'=>'#1877F2'],
    'Twitter'   => ['icon'=>'bx bxl-twitter','base'=>'https://twitter.com/','color'=>'#1DA1F2'],
    'LinkedIn'  => ['icon'=>'bx bxl-linkedin','base'=>'https://linkedin.com/in/','color'=>'#0A66C2'],
    'YouTube'   => ['icon'=>'bx bxl-youtube','base'=>'https://youtube.com/','color'=>'#FF0000'],
    'WhatsApp'  => ['icon'=>'bx bxl-whatsapp','base'=>'https://wa.me/','color'=>'#25D366']
];

if ($_SERVER['REQUEST_METHOD']==='POST') {

    $platform = $_POST['platform'] ?? '';
    $status   = $_POST['status'] ?? 'active';
    $input    = trim($_POST['url'] ?? '');
    $sort     = ($_POST['sort_order'] === '') ? 999 : (int)$_POST['sort_order'];

    if (!$platform || !$input) {
        $error = "Please fill all required fields.";
    } else {
        $icon = $platforms[$platform]['icon'];
        $base = $platforms[$platform]['base'];

        $url = preg_match('#^https?://#',$input)
             ? $input
             : rtrim($base,'/').'/'.ltrim($input,'/');

        $pdo->prepare("
            INSERT INTO social_links (platform, icon_class, url, sort_order, status)
            VALUES (?,?,?,?,?)
        ")->execute([$platform,$icon,$url,$sort,$status]);

        quickLog($pdo,'create','social_link',$pdo->lastInsertId(),"Added $platform");
        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Add Social Media</title>

<link rel="stylesheet" href="../assets/css/prody-admin.css">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
.social-preview{
    display:flex;
    align-items:center;
    gap:16px;
    margin-top:10px;
}
.social-icon-lg{
    width:64px;height:64px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:30px;
}
</style>
</head>
<body>

<div class="container">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<div class="content-area">

<?php if($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

<div class="table-card">
<div class="table-header"><div class="table-title">Add Social Media</div></div>

<div style="padding:2rem">
<form method="POST" class="form-modern">

<div class="grid-3">
<div class="form-group">
<label>Platform *</label>
<select name="platform" id="platform" class="form-select" onchange="updatePreview()" required>
<?php foreach($platforms as $k=>$v): ?>
<option value="<?= $k ?>"><?= $k ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="form-group">
<label>Status</label>
<select name="status" class="form-select">
<option value="active">Active</option>
<option value="inactive">Inactive</option>
</select>
</div>

<div class="form-group">
<label>Sort Order</label>
<input type="text" name="sort_order" class="form-input" placeholder="Auto">
</div>
</div>

<div class="form-group">
<label>Username or URL *</label>
<input type="text" id="urlInput" name="url" class="form-input" required>
<small class="text-muted-custom">Type only username. URL auto builds.</small>
</div>

<div class="form-group">
<label>Preview</label>
<div class="social-preview">
<div id="iconBox" class="social-icon-lg"></div>
<strong id="namePreview"></strong>
</div>
</div>

<div class="form-actions">
<button class="btn-action btn-add">Save Social</button>
<a href="index.php" class="btn-action" style="background:#6b7280;color:#fff">Cancel</a>
</div>

</form>
</div>
</div>
</div>
</div>

<script>
const platforms = <?= json_encode($platforms) ?>;

function updatePreview(){
    const p = document.getElementById('platform').value;
    document.getElementById('iconBox').innerHTML =
        `<i class="${platforms[p].icon}"></i>`;
    document.getElementById('iconBox').style.background = platforms[p].color;
    document.getElementById('namePreview').innerText = p;
    document.getElementById('urlInput').value = platforms[p].base;
}
document.addEventListener('DOMContentLoaded',updatePreview);
</script>

</body>
</html>

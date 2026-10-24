<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);

$db = getCampaignDB();
$today = date('Y-m-d');

$stmt = $db->prepare("
    SELECT c.*, ca.file_path
    FROM campaigns c
    LEFT JOIN campaign_assets ca ON ca.campaign_id = c.id
    WHERE c.status='active'
      AND c.start_date <= ?
      AND (c.end_date IS NULL OR c.end_date >= ?)
    ORDER BY c.id DESC
    LIMIT 1
");
$stmt->execute([$today, $today]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$campaign) die('No active campaign');

$q = $db->prepare("SELECT * FROM campaign_questions WHERE campaign_id=? ORDER BY sort_order");
$q->execute([$campaign['id']]);
$questions = $q->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($campaign['title']) ?> | LIYAS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200;300;400&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
/* Keeping your original CSS exactly as it was */
:root{
    --aqua-1:#ecfbff;
    --aqua-2:#d7f3ff;
    --aqua-3:#bfe9ff;
    --aqua-4:#8fd3f4;
    --accent:#1fb6e9;
    --navy:#0b1f3b;
    --navy-soft:#123b6d;
    --text-muted:#5f7d95;
}

*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:'Outfit',sans-serif;
    background:linear-gradient(180deg,var(--aqua-1),#fff);
    overflow-x:hidden;
}
.container{display:flex;min-height:100vh}

/* ================= LEFT ================= */
.left{
    flex:1;
    padding:34px;
    background:
        radial-gradient(circle at 20% 10%, rgba(255,255,255,.45), transparent 40%),
        linear-gradient(180deg,var(--aqua-3),var(--aqua-4));
    position:relative;
    overflow:hidden;
}

/* BUBBLES */
.bubble-layer{position:absolute;inset:0;pointer-events:none;z-index:1}
.bubble-layer span{
    position:absolute;bottom:-120px;border-radius:50%;
    background:radial-gradient(circle at 30% 30%,
        rgba(255,255,255,.9),
        rgba(255,255,255,.4),
        rgba(255,255,255,.1)
    );
    opacity:.35;
    animation:floatBubble linear infinite;
}
.bubble-layer span:nth-child(1){left:12%;width:22px;height:22px;animation-duration:18s}
.bubble-layer span:nth-child(2){left:28%;width:36px;height:36px;animation-duration:24s}
.bubble-layer span:nth-child(3){left:50%;width:18px;height:18px;animation-duration:16s}
.bubble-layer span:nth-child(4){left:70%;width:28px;height:28px;animation-duration:22s}
.bubble-layer span:nth-child(5){left:85%;width:20px;height:20px;animation-duration:19s}

@keyframes floatBubble{
    from{transform:translateY(0);opacity:0}
    20%{opacity:.35}
    to{transform:translateY(-120vh);opacity:0}
}

.logo{
    position:absolute;top:30px;left:30px;width:75px;
    filter:brightness(0);z-index:3
}

.left-header{
    text-align:center;
    margin-top:40px;
    z-index:3;
    position:relative;
}

.campaign-title span{
    font-family:'Outfit',sans-serif;
    font-size:3.1rem;
    font-weight:600;
    letter-spacing:1.6px;
    color:var(--navy);
    display:inline-block;
    background:linear-gradient(90deg, var(--navy) 50%, rgba(11,31,59,0) 50%);
    background-size:200% 100%;
    background-position:100% 0;
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
    animation:strokeReveal 2.3s ease forwards;
}

@keyframes strokeReveal{ to{background-position:0% 0} }

.left-header p{
    max-width:420px;
    margin:0 auto;
    opacity:.85;
    color:var(--navy);
    font-weight:300;
}

.product{
    margin:46px auto 0;
    width:238vh;
    padding:10px;
    border-radius:18px;
    text-align:left;
    backdrop-filter:blur(12px);
    position:relative;
    z-index:3;
}
.product img{width:100%; border-radius:18px;}

.product-slogan {
    text-align: left;
    font-size: 18px;
    font-weight: 500;
    color: #253a41;
    text-shadow: 0 0 8px rgba(31, 182, 233, 0.3);
    z-index: 3;
    position: relative;
    line-height: 1.6em;
}

.waves{
    position:absolute;bottom:0;left:0;width:100%;height:200px;z-index:2
}
.parallax>use{animation:move-forever 25s cubic-bezier(.55,.5,.45,.5) infinite}
.parallax>use:nth-child(1){animation-delay:-2s;animation-duration:7s}
.parallax>use:nth-child(2){animation-delay:-3s;animation-duration:10s}
.parallax>use:nth-child(3){animation-delay:-4s;animation-duration:13s}
.parallax>use:nth-child(4){animation-delay:-5s;animation-duration:20s}
@keyframes move-forever{from{transform:translateX(-90px)}to{transform:translateX(85px)}}

.right{
    flex:1;padding:80px;
    display:flex;align-items:center;justify-content:center;
    background:
        radial-gradient(circle at 100% 0%, rgba(143,211,244,.35), transparent 55%),
        radial-gradient(circle at 0% 100%, rgba(191,233,255,.45), transparent 60%),
        linear-gradient(180deg,var(--aqua-1),#fff);
}

.form{
    max-width:500px;width:100%;
    background:rgba(255,255,255,.65);
    backdrop-filter:blur(18px);
    border-radius:26px;
    padding:24px 55px;
    box-shadow:0 30px 70px rgba(0,80,120,.18);
}

.step{font-size:1.1rem;letter-spacing:3px;color:var(--accent);font-weight:700;margin-bottom:14px}
.form h2{font-size:2.1rem;color:var(--navy);margin-bottom:42px}

.input{margin-bottom:48px}
.input input{
    width:100%;border:none;
    border-bottom:2px solid rgba(0,0,0,.08);
    padding:16px 4px;font-size:1.1rem;background:transparent;
}
.input input:focus{outline:none;border-color:var(--accent)}

.actions{display:flex;gap:18px}
.btn{
    padding:18px 54px;
    background:linear-gradient(135deg,var(--navy),var(--navy-soft));
    color:#fff;border:none;border-radius:18px;
    font-weight:700;cursor:pointer;
    box-shadow:0 18px 40px rgba(11,31,59,.35);
}
.back{background:none;border:none;color:var(--text-muted);font-weight:600;cursor:pointer}

.step-card{display:none}
.step-card.active{display:block;animation:fade .6s ease}
@keyframes fade{from{opacity:0;transform:translateY(12px)}to{opacity:1}}

.upload-box {
    border: 2px dashed var(--aqua-4);
    background: rgba(236, 251, 255, 0.5);
    border-radius: 20px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.join{ width: 100%; display: flex; align-items: center; justify-content: center; }

/* NEW: Login Overlay styles to blend with your theme */
.login-lockout {
    position: fixed; inset: 0; background: rgba(11, 31, 59, 0.6);
    backdrop-filter: blur(15px); z-index: 99999;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.login-box {
    background: white; padding: 40px; border-radius: 26px; text-align: center;
    max-width: 400px; box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}

@media(max-width:1024px){
    .container{flex-direction:column}
    .left,.right{padding:40px}
    .campaign-title span{font-size:2.3rem}
}
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-lockout">
    <div class="login-box">
        <i class='bx bxs-lock-alt' style="font-size:4rem; color:var(--accent); margin-bottom:20px;"></i>
        <h2 style="color:var(--navy); margin-bottom:15px;">Sign Up Required</h2>
        <p style="color:var(--text-muted); margin-bottom:30px;">To participate in this campaign, please sign in to your account first.</p>
        <a href="../signup1/index.php" class="btn" style="text-decoration:none; display:block;">Sign Up Now</a>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div style="position:fixed; top:20px; right:20px; background:#10b981; color:white; padding:20px; border-radius:12px; z-index:9999; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
    <strong>Success!</strong> Your entry has been submitted.
</div>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    confetti({ particleCount: 200, spread: 120, origin: { y: 0.6 } });
    setTimeout(() => { window.location.href = "index.php"; }, 4000);
});
</script>
<?php endif; ?>

<div class="container">
<div class="left">
    <div class="bubble-layer"><span></span><span></span><span></span><span></span><span></span></div>
    <img src="../assets/images/logo/logo.png" class="logo">

   <div class="join">
     <div class="form">
        <form method="POST" action="submit.php" enctype="multipart/form-data">
        <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">

        <div class="step-card active">
            <div class="step">STEP-01</div>
            <h2>May we have your<br><span>full name?</span></h2>
            <div class="input"><input type="text" name="full_name" required placeholder="Your Full Name"></div>
        </div>

        <div class="step-card">
            <div class="step">STEP-02</div>
            <h2>How can we<br><span>contact you?</span></h2>
            <div class="input">
                <input type="email" name="email" required placeholder="Your Email Address">
                <input type="tel" name="phone_number" required placeholder="Your Phone Number">
            </div>
        </div>

        <?php foreach($questions as $i => $q): ?>
        <div class="step-card">
            <div class="step">STEP-<?= str_pad($i+3, 2, '0', STR_PAD_LEFT) ?></div>
            <h2><?= htmlspecialchars($q['question_label']) ?></h2>
            <div class="input">
            <?php $type = $q['field_type']; ?>

            <?php if ($type === 'text' || $type === 'number'): ?>
                <input type="<?= $type ?>" name="answers[<?= $q['id'] ?>]" <?= $q['is_required'] ? 'required' : '' ?> placeholder="Your answer here...">

            <?php elseif ($type === 'dropdown'): ?>
                <select name="answers[<?= $q['id'] ?>]" <?= $q['is_required'] ? 'required' : '' ?> style="width:100%; border:none; border-bottom:2px solid #ddd; padding:15px 0;">
                    <option value="">Select an option</option>
                    <option value="Yes">Yes</option>
                    <option value="No">No</option>
                </select>

            <?php elseif ($type === 'image_upload' || $type === 'video_upload'): ?>
                <div class="upload-box" id="drop-zone-<?= $q['id'] ?>" onclick="document.getElementById('file-<?= $q['id'] ?>').click()">
                    <div class="upload-content" id="content-<?= $q['id'] ?>">
                        <i class='bx bx-cloud-upload' style="font-size:3.5rem;color:var(--accent);display:block;margin-bottom:10px;"></i>
                        <p>Drag & Drop or <strong>Browse</strong></p>
                    </div>
                    <div class="preview-container" id="preview-<?= $q['id'] ?>" style="display:none;text-align:center;">
                        <img id="img-prev-<?= $q['id'] ?>" style="display:none;max-height:250px;border-radius:12px;">
                        <video id="vid-prev-<?= $q['id'] ?>" controls style="display:none;max-height:250px;border-radius:12px;"></video>
                        <p class="change-file" style="color:var(--accent); font-size:0.8rem; font-weight:600; margin-top:10px;">Click to change file</p>
                    </div>
                    <input type="file" name="media[<?= $q['id'] ?>]" id="file-<?= $q['id'] ?>" accept="<?= $type === 'video_upload' ? 'video/*' : 'image/*' ?>" onchange="handlePreview(this,'<?= $q['id'] ?>')" <?= $q['is_required'] ? 'required' : '' ?> hidden>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="actions">
            <button type="button" class="btn" id="nextBtn" onclick="move(1)">Continue</button>
            <button type="submit" class="btn" id="submitBtn" style="display:none">Submit</button>
            <button type="button" class="back" id="prevBtn" onclick="move(-1)" style="visibility:hidden">Back</button>
        </div>
        </form>
      </div>
   </div>

    <svg class="waves" viewBox="0 24 150 28" preserveAspectRatio="none">
        <defs><path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s58 18 88 18 58-18 88-18 58 18 88 18v44h-352z"/></defs>
        <g class="parallax">
            <use href="#gentle-wave" x="48" y="0" fill="rgba(255,255,255,.4)" />
            <use href="#gentle-wave" x="48" y="3" fill="rgba(255,255,255,.3)" />
            <use href="#gentle-wave" x="48" y="5" fill="rgba(255,255,255,.2)" />
            <use href="#gentle-wave" x="48" y="7" fill="rgba(255,255,255,.15)" />
        </g>
    </svg>
</div>
</div>

<script>
let step=0;
const cards=document.querySelectorAll('.step-card');
const nextBtn=document.getElementById('nextBtn');
const submitBtn=document.getElementById('submitBtn');
const prevBtn=document.getElementById('prevBtn');

function update(){
    cards.forEach((c,i)=>c.classList.toggle('active',i===step));
    prevBtn.style.visibility=step===0?'hidden':'visible';
    nextBtn.style.display=step===cards.length-1?'none':'inline-block';
    submitBtn.style.display=step===cards.length-1?'inline-block':'none';
}
function move(n){
    if(n===1){
        const inputs=cards[step].querySelectorAll('[required]');
        for(const i of inputs){ if(!i.checkValidity()){ i.reportValidity(); return; } }
    }
    step=Math.max(0,Math.min(step+n,cards.length-1));
    update();
}
update();

function handlePreview(input, qId) {
    const content = document.getElementById('content-' + qId);
    const preview = document.getElementById('preview-' + qId);
    const imgPrev = document.getElementById('img-prev-' + qId);
    const vidPrev = document.getElementById('vid-prev-' + qId);

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            content.style.display = 'none';
            preview.style.display = 'block';
            if (file.type.startsWith('image/')) {
                imgPrev.src = e.target.result; imgPrev.style.display = 'block'; vidPrev.style.display = 'none';
            } else {
                vidPrev.src = e.target.result; vidPrev.style.display = 'block'; imgPrev.style.display = 'none';
            }
        }
        reader.readAsDataURL(file);
    }
}
</script>
</body>
</html>
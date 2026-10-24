<?php
require_once 'config/config.php';
$page_title = 'About Us | LIYAS Mineral Water';

// Fetch social links
$social_links_stmt = $pdo->query("SELECT * FROM social_links WHERE status = 'active' ORDER BY sort_order ASC");
$social_links = $social_links_stmt->fetchAll(PDO::FETCH_ASSOC);

include 'components/public-header.php';
?>

<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<div class="social-sidebar">
    <?php foreach ($social_links as $link): ?>
        <a href="<?= htmlspecialchars($link['url']) ?>" class="social-icon" target="_blank" aria-label="<?= htmlspecialchars($link['platform']) ?>">
            <i class="<?= htmlspecialchars($link['icon_class']) ?>"></i>
        </a>
    <?php endforeach; ?>
</div>

<!-- Badge Row -->
<div class="badge-row">
    <img src="assets/images/liyas-bottle1.png" alt="ISO 14001">
    <img src="assets/images/liyas-bottle1.png" alt="ISO 9001">
    <img src="assets/images/liyas-bottle1.png" alt="ISO Certified">
    <img src="assets/images/liyas-bottle1.png" alt="ISO Quality">
    <img src="assets/images/liyas-bottle1.png" alt="ISO 22000">
</div>

<?php
include 'components/footer.php';
?>
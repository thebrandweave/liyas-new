<?php
// Detect base path (admin subfolders)
$current_dir_name = basename(dirname($_SERVER['SCRIPT_FILENAME']));

$base_path = (in_array($current_dir_name, [
    'users',
    'orders',
    'products',
    'zones',
    'dashboard',
    'qr-rewards',
    'activity-logs',
    'notifications',
    'subscriptions',
    'advertisements',
    'reviews',
    'social-links',
    'campaigns',
    'revenue'
])) ? '../' : './';

// Current page logic
$current_page = $current_page ?? '';
$current_file = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

// Badge counts
$products_count = 0;
$zones_count = 0;
$pending_orders_count = 0;
$users_count = 0;
$notifications_count = 0;
$subs_count = 0;
$ads_count = 0;
$reviews_pending_count = 0;
$social_count = 0;
$campaign_submissions_count = 0;

try {
    // --- MAIN DB COUNTS ---
    if (isset($pdo)) {
        $products_count = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
        $zones_count = (int)$pdo->query("SELECT COUNT(*) FROM zones WHERE status='active'")->fetchColumn();
        $pending_orders_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
        $users_count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        $subs_count = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_subscriptions WHERE status='subscribed'")->fetchColumn();
        $ads_count = (int)$pdo->query("SELECT COUNT(*) FROM advertisements")->fetchColumn();
        $reviews_pending_count = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn();
        $social_count = (int)$pdo->query("SELECT COUNT(*) FROM social_links")->fetchColumn();

        $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_type='admin' AND is_read=0 AND admin_id = ?");
        $stmtNotif->execute([$_SESSION['admin_id'] ?? 0]);
        $notifications_count = (int)$stmtNotif->fetchColumn();
    }

    // --- CAMPAIGN DB COUNTS ---
    if (isset($pdo_campaign)) {
        $campaign_submissions_count = (int)$pdo_campaign->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    }
} catch (PDOException $e) {
    // silent fail
}

// Check if any sub-item in the "More" dropdown is currently active
$more_dirs = ['advertisements', 'reviews', 'social-links', 'subscriptions', 'notifications', 'users', 'qr-rewards', 'activity-logs'];
$is_more_active = in_array($current_dir, $more_dirs) || in_array($current_page, $more_dirs);

// Total alert badges inside the dropdown
$more_badge_total = $ads_count + $reviews_pending_count + $social_count + $subs_count + $notifications_count + $users_count;
?>

<button id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle sidebar">
    <i class='bx bx-menu'></i>
</button>

<div class="sidebar">
    <div class="logo" style="justify-content:center; padding-bottom: 0;">
        <span class="logo-text">Liyas</span>
    </div>

    <div class="app-switcher" style="padding: 15px; margin-bottom: 5px;">
        <div style="display: flex; gap: 4px; background: rgba(0,0,0,0.05); padding: 4px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.03);">
            <a href="<?= $base_path ?>dashboard/index.php" 
               style="flex: 1; text-align: center; padding: 8px 4px; border-radius: 7px; font-size: 11px; text-decoration: none; display: flex; flex-direction: column; align-items: center; transition: 0.2s; <?= ($current_dir != 'campaigns') ? 'background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.08); color: #2563eb; font-weight: 600;' : 'color: #94a3b8;' ?>">
                <i class='bx bx-store-alt' style="font-size: 18px; margin-bottom: 2px;"></i>
                Warehouse
            </a>
            <a href="<?= $base_path ?>campaigns/index.php" 
               style="flex: 1; text-align: center; padding: 8px 4px; border-radius: 7px; font-size: 11px; text-decoration: none; display: flex; flex-direction: column; align-items: center; transition: 0.2s; <?= ($current_dir == 'campaigns') ? 'background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.08); color: #0369a1; font-weight: 600;' : 'color: #94a3b8;' ?>">
                <i class='bx bx-qr-scan' style="font-size: 18px; margin-bottom: 2px;"></i>
                Campaigns
            </a>
        </div>
    </div>

    <div class="search-box">
        <div class="search-wrapper">
            <i class='bx bx-search search-icon'></i>
            <input type="text" class="search-input" placeholder="Search...">
        </div>
    </div>

    <nav class="nav-menu">

        <?php if ($current_dir == 'campaigns'): ?>
            <div style="padding: 10px 25px; font-size: 11px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Campaign Engine</div>
            
            <a href="<?= $base_path ?>campaigns/index.php" class="nav-item <?= ($current_file=='index.php')?'active':'' ?>">
                <i class='bx bx-list-ul'></i>
                <span>Manage Contests</span>
            </a>
            <a href="<?= $base_path ?>campaigns/entries.php" class="nav-item <?= ($current_file=='entries.php')?'active':'' ?>">
                <i class='bx bx-group'></i>
                <span>Entries / Leads</span>
                <?php if ($campaign_submissions_count > 0): ?>
                    <span class="badge-count"><?= $campaign_submissions_count ?></span>
                <?php endif; ?>
            </a>

        <?php else: ?>
            <a href="<?= $base_path ?>dashboard/index.php"
               class="nav-item <?= ($current_dir=='dashboard'||($current_file=='index.php' && $current_dir=='admin'))?'active':'' ?>">
                <i class='bx bx-home'></i>
                <span>Dashboard</span>
            </a>

            <a href="<?= $base_path ?>orders/index.php"
               class="nav-item <?= ($current_dir=='orders'||$current_page==='orders')?'active':'' ?>">
                <i class='bx bx-cart'></i>
                <span>Orders</span>
                <?php if ($pending_orders_count > 0): ?>
                    <span class="badge-count" style="background:#ef4444; color:#fff; font-weight:600; border-radius:12px; padding:2px 8px; font-size:11px; display:inline-flex; align-items:center; gap:3px;">
                        🔴 <?= $pending_orders_count ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="<?= $base_path ?>zones/index.php"
               class="nav-item <?= ($current_dir=='zones'||$current_page==='zones')?'active':'' ?>">
                <i class='bx bx-map-pin'></i>
                <span>Zones</span>
                <?php if ($zones_count > 0): ?>
                    <span class="nav-badge"><?= $zones_count ?></span>
                <?php endif; ?>
            </a>

     

            <a href="<?= $base_path ?>revenue/index.php"
               class="nav-item <?= ($current_dir=='revenue'||$current_page==='revenue')?'active':'' ?>">
                <i class='bx bx-line-chart'></i>
                <span>Revenue</span>
            </a>

            <a href="<?= $base_path ?>products/index.php"
               class="nav-item <?= ($current_dir=='products'||$current_page==='products')?'active':'' ?>">
                <i class='bx bx-shopping-bag'></i>
                <span>Products</span>
                <?php if ($products_count > 0): ?>
                    <span class="nav-badge"><?= $products_count ?></span>
                <?php endif; ?>
            </a>

            <!-- Dropdown Toggle Button for Utilities / Extra Modules -->
            <button type="button" 
                    id="moreOptionsToggle" 
                    class="nav-item <?= $is_more_active ? 'active' : '' ?>" 
                    style="width: 100%; border: none; background: transparent; cursor: pointer; text-align: left; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 4px;">
                    <i class='bx bx-grid-alt'></i>
                    <span>More Options</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <?php if (!$is_more_active && $more_badge_total > 0): ?>
                        <span class="badge-count" style="font-size: 10px;"><?= $more_badge_total ?></span>
                    <?php endif; ?>
                    <i class='bx bx-chevron-down' id="moreDropdownArrow" style="transition: transform 0.2s; <?= $is_more_active ? 'transform: rotate(180deg);' : '' ?>"></i>
                </div>
            </button>

            <!-- Collapsible Sub-menu -->
            <div id="moreOptionsSubmenu" style="display: <?= $is_more_active ? 'block' : 'none' ?>; padding-left: 12px; border-left: 2px solid rgba(0,0,0,0.06); ">
                <a href="<?= $base_path ?>advertisements/index.php"
                   class="nav-item <?= ($current_dir=='advertisements'||$current_page==='advertisements')?'active':'' ?>">
                    <i class='bx bx-image'></i>
                    <span>Advertisements</span>
                    <?php if ($ads_count > 0): ?>
                        <span class="nav-badge"><?= $ads_count ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $base_path ?>reviews/index.php"
                   class="nav-item <?= ($current_dir=='reviews'||$current_page==='reviews')?'active':'' ?>">
                    <i class='bx bx-star'></i>
                    <span>Reviews</span>
                    <?php if ($reviews_pending_count > 0): ?>
                        <span class="badge-count"><?= $reviews_pending_count ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $base_path ?>social-links/index.php"
                   class="nav-item <?= ($current_dir=='social-links'||$current_page==='social-links')?'active':'' ?>">
                    <i class='bx bx-share-alt'></i>
                    <span>Social Media</span>
                    <?php if ($social_count > 0): ?>
                        <span class="nav-badge"><?= $social_count ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $base_path ?>subscriptions/index.php"
                   class="nav-item <?= ($current_dir=='subscriptions'||$current_page==='subscriptions')?'active':'' ?>">
                    <i class='bx bx-envelope'></i>
                    <span>Newsletter</span>
                    <?php if ($subs_count > 0): ?>
                        <span class="nav-badge"><?= $subs_count ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $base_path ?>notifications/index.php"
                   class="nav-item <?= ($current_dir=='notifications'||$current_page==='notifications')?'active':'' ?>">
                    <i class='bx bx-bell'></i>
                    <span>Notifications</span>
                    <?php if ($notifications_count > 0): ?>
                        <span class="badge-count"><?= $notifications_count ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $base_path ?>users/index.php"
                   class="nav-item <?= ($current_dir=='users'||$current_page==='users')?'active':'' ?>">
                    <i class='bx bx-group'></i>
                    <span>Users</span>
                    <?php if ($users_count > 0): ?>
                        <span class="nav-badge"><?= $users_count ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $base_path ?>qr-rewards/index.php"
                   class="nav-item <?= ($current_dir=='qr-rewards'||$current_page==='qr-rewards')?'active':'' ?>">
                    <i class='bx bx-qr'></i>
                    <span>QR Rewards</span>
                </a>

                <a href="<?= $base_path ?>activity-logs/index.php"
                   class="nav-item <?= ($current_dir=='activity-logs'||$current_page==='activity-logs')?'active':'' ?>">
                    <i class='bx bx-history'></i>
                    <span>Activity Logs</span>
                </a>
            </div>

        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        <a href="<?= $base_path ?>logout.php" class="nav-item">
            <i class='bx bx-log-out'></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<script>
(function () {
    // Sidebar open/collapse toggle
    const toggle = document.getElementById('sidebarToggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-open');
        });
    }

    // More Options collapsible menu toggle
    const moreBtn = document.getElementById('moreOptionsToggle');
    const submenu = document.getElementById('moreOptionsSubmenu');
    const arrow = document.getElementById('moreDropdownArrow');

    if (moreBtn && submenu) {
        moreBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const isOpen = submenu.style.display === 'block';
            submenu.style.display = isOpen ? 'none' : 'block';
            if (arrow) {
                arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
            }
        });
    }
})();
</script>
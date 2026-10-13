<?php
/**
 * components/rewards-sidebar.php
 * Interactive slide-out sidebar drawer displaying the Shop Reward Status of every shop.
 */
if (!isset($pdo)) {
    require_once dirname(__DIR__) . '/config/config.php';
}
if (!function_exists('getSystemSetting')) {
    require_once dirname(__DIR__) . '/admin/includes/functions.php';
}

$rewardThreshold = (int)getSystemSetting($pdo, 'reward_threshold', 10);
if ($rewardThreshold <= 0) $rewardThreshold = 10;

// Fetch all distinct shops with their delivered order counts, phone, and reward statuses
try {
    $shopsQuery = "
        SELECT 
            TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name)) AS shop_name,
            MAX(o.phone) AS customer_phone,
            MAX(z.name) AS zone_name,
            MAX(z.slug) AS zone_slug,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS completed_orders,
            COUNT(o.order_id) AS total_orders,
            COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN COALESCE(r.delivered_quantity, o.quantity) ELSE 0 END), 0) AS total_cases_delivered,
            MAX(rew.reward_status) AS db_reward_status,
            MAX(rew.id) AS reward_id
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN receipts r ON o.order_id = r.order_id
        LEFT JOIN rewards rew ON TRIM(rew.shop_name) = TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name))
        WHERE (o.shop_name IS NOT NULL AND TRIM(o.shop_name) != '')
           OR (o.customer_name IS NOT NULL AND TRIM(o.customer_name) != '')
        GROUP BY TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name))
        ORDER BY completed_orders DESC, shop_name ASC
    ";
    $allShopsRewards = $pdo->query($shopsQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allShopsRewards = [];
}

$totalShopsCount = count($allShopsRewards);
$eligibleCount = 0;
$inProgressCount = 0;
$claimedCount = 0;

foreach ($allShopsRewards as &$sr) {
    $comp = (int)$sr['completed_orders'];
    $dbStat = $sr['db_reward_status'];
    if ($dbStat === 'claimed') {
        $sr['calc_status'] = 'claimed';
        $claimedCount++;
    } elseif ($comp >= $rewardThreshold) {
        $sr['calc_status'] = 'eligible';
        $eligibleCount++;
    } else {
        $sr['calc_status'] = 'in_progress';
        $inProgressCount++;
    }
    $sr['remaining'] = max(0, $rewardThreshold - $comp);
    $sr['percent'] = min(100, round(($comp / $rewardThreshold) * 100));
}
unset($sr);
?>

<!-- Rewards Sidebar Backdrop -->
<div id="rewardsSidebarOverlay" class="rewards-overlay" onclick="toggleRewardsSidebar(false)"></div>

<!-- Rewards Sidebar Drawer -->
<aside id="rewardsSidebar" class="rewards-drawer" aria-label="Shop Reward Status Sidebar">
    <!-- Header -->
    <div class="rewards-drawer-header">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="rewards-header-icon">
                <i class='bx bxs-gift'></i>
            </div>
            <div>
                <h2 class="rewards-header-title">Shop Reward Status</h2>
                <p class="rewards-header-sub">Milestone: <?= $rewardThreshold ?> delivered orders</p>
            </div>
        </div>
        <button type="button" class="rewards-close-btn" onclick="toggleRewardsSidebar(false)" aria-label="Close sidebar">
            &times;
        </button>
    </div>

    <!-- Quick Metrics Counters -->
    <div class="rewards-kpi-row">
        <div class="rewards-kpi-box kpi-all" onclick="filterRewardsByTab('all')">
            <span class="rewards-kpi-num" id="rewCountAll"><?= $totalShopsCount ?></span>
            <span class="rewards-kpi-lbl">Total Shops</span>
        </div>
        <div class="rewards-kpi-box kpi-eligible" onclick="filterRewardsByTab('eligible')">
            <span class="rewards-kpi-num" id="rewCountEligible"><?= $eligibleCount ?></span>
            <span class="rewards-kpi-lbl">🎉 Eligible</span>
        </div>
        <div class="rewards-kpi-box kpi-progress" onclick="filterRewardsByTab('in_progress')">
            <span class="rewards-kpi-num" id="rewCountProgress"><?= $inProgressCount ?></span>
            <span class="rewards-kpi-lbl">⏳ In Progress</span>
        </div>
        <div class="rewards-kpi-box kpi-claimed" onclick="filterRewardsByTab('claimed')">
            <span class="rewards-kpi-num" id="rewCountClaimed"><?= $claimedCount ?></span>
            <span class="rewards-kpi-lbl">✅ Claimed</span>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="rewards-search-wrap">
        <div class="rewards-input-box">
            <i class='bx bx-search'></i>
            <input 
                type="text" 
                id="rewardsSearchInput" 
                placeholder="Search shop name or phone..." 
                oninput="handleRewardsSearch(this.value)"
            >
            <button type="button" id="rewardsClearSearch" onclick="clearRewardsSearch()" style="display: none;">&times;</button>
        </div>
        <div class="rewards-filter-tabs">
            <button type="button" class="rewards-tab-btn active" data-tab="all" onclick="filterRewardsByTab('all')">All</button>
            <button type="button" class="rewards-tab-btn" data-tab="eligible" onclick="filterRewardsByTab('eligible')">🎉 Eligible</button>
            <button type="button" class="rewards-tab-btn" data-tab="in_progress" onclick="filterRewardsByTab('in_progress')">⏳ Progress</button>
            <button type="button" class="rewards-tab-btn" data-tab="claimed" onclick="filterRewardsByTab('claimed')">✅ Claimed</button>
        </div>
    </div>

    <!-- Shops Reward List Body -->
    <div class="rewards-drawer-body" id="rewardsListBody">
        <?php if (empty($allShopsRewards)): ?>
            <div class="rewards-empty">
                <i class='bx bx-store-alt'></i>
                <p>No shop orders recorded yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($allShopsRewards as $s): 
                $status = $s['calc_status'];
                $sName = $s['shop_name'];
                $phone = $s['customer_phone'] ?? '';
                $comp = (int)$s['completed_orders'];
                $percent = (int)$s['percent'];
                $cases = (int)$s['total_cases_delivered'];
                $zoneName = $s['zone_name'] ?: 'Zone';
            ?>
                <div class="shop-reward-card" 
                     data-shop-name="<?= htmlspecialchars(strtolower($sName), ENT_QUOTES) ?>" 
                     data-phone="<?= htmlspecialchars($phone, ENT_QUOTES) ?>" 
                     data-status="<?= $status ?>"
                >
                    <div class="src-top">
                        <div class="src-shop-title">
                            <span class="src-icon">🏪</span>
                            <span class="src-name"><?= htmlspecialchars($sName) ?></span>
                        </div>
                        <?php if ($status === 'eligible'): ?>
                            <span class="src-badge badge-eligible">🎉 Eligible</span>
                        <?php elseif ($status === 'claimed'): ?>
                            <span class="src-badge badge-claimed">✅ Claimed</span>
                        <?php else: ?>
                            <span class="src-badge badge-progress">⏳ <?= $s['remaining'] ?> left</span>
                        <?php endif; ?>
                    </div>

                    <div class="src-meta">
                        <?php if (!empty($phone)): ?>
                            <a href="tel:<?= htmlspecialchars($phone) ?>" class="src-phone-link">
                                <i class='bx bx-phone'></i> <?= htmlspecialchars($phone) ?>
                            </a>
                        <?php endif; ?>
                        <span class="src-zone-tag"><?= htmlspecialchars($zoneName) ?></span>
                        <span class="src-cases-tag">📦 <?= number_format($cases) ?> cases</span>
                    </div>

                    <!-- Progress Bar -->
                    <div class="src-progress-wrap">
                        <div class="src-progress-header">
                            <span>Completed Orders</span>
                            <strong><?= $comp ?> / <?= $rewardThreshold ?> (<?= $percent ?>%)</strong>
                        </div>
                        <div class="src-progress-track">
                            <div class="src-progress-bar <?= ($status === 'eligible') ? 'bar-eligible' : (($status === 'claimed') ? 'bar-claimed' : 'bar-normal') ?>" style="width: <?= $percent ?>%;"></div>
                        </div>
                    </div>

                    <!-- Card Actions -->
                    <div class="src-actions">
                        <span class="src-total-orders">Total: <?= (int)$s['total_orders'] ?> orders placed</span>
                        <?php if ($status === 'eligible'): ?>
                            <button type="button" class="btn-claim-reward" onclick="claimShopReward('<?= htmlspecialchars(addslashes($sName), ENT_QUOTES) ?>', this)">
                                <i class='bx bx-check-circle'></i> Mark Claimed
                            </button>
                        <?php elseif ($status === 'claimed'): ?>
                            <button type="button" class="btn-unclaim-reward" onclick="unclaimShopReward('<?= htmlspecialchars(addslashes($sName), ENT_QUOTES) ?>', this)">
                                <i class='bx bx-undo'></i> Reset Status
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</aside>

<!-- Styles for Rewards Sidebar Drawer -->
<style>
.rewards-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 9998;
    transition: opacity 0.25s ease;
}
.rewards-overlay.active {
    display: block;
}

.rewards-drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 440px;
    max-width: 92vw;
    background: #ffffff;
    box-shadow: -10px 0 35px rgba(0, 0, 0, 0.18);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: inherit;
}
.rewards-drawer.open {
    transform: translateX(0);
}

.rewards-drawer-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
    color: #ffffff;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.rewards-header-icon {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #fde047;
}
.rewards-header-title {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: #ffffff;
    line-height: 1.2;
}
.rewards-header-sub {
    font-size: 11px;
    color: #bfdbfe;
    margin: 2px 0 0 0;
}
.rewards-close-btn {
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: #ffffff;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
}
.rewards-close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

.rewards-kpi-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    padding: 12px 16px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.rewards-kpi-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 4px;
    text-align: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.rewards-kpi-box:hover {
    transform: translateY(-1px);
    border-color: #2563eb;
}
.rewards-kpi-num {
    display: block;
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
}
.rewards-kpi-lbl {
    font-size: 10px;
    color: #64748b;
    font-weight: 600;
}
.kpi-eligible .rewards-kpi-num { color: #059669; }
.kpi-progress .rewards-kpi-num { color: #2563eb; }
.kpi-claimed .rewards-kpi-num { color: #7c3aed; }

.rewards-search-wrap {
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
    background: #ffffff;
}
.rewards-input-box {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}
.rewards-input-box i {
    position: absolute;
    left: 10px;
    font-size: 16px;
    color: #94a3b8;
}
.rewards-input-box input {
    width: 100%;
    padding: 8px 30px 8px 32px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    outline: none;
    box-sizing: border-box;
}
.rewards-input-box input:focus {
    border-color: #2563eb;
}
#rewardsClearSearch {
    position: absolute;
    right: 8px;
    background: none;
    border: none;
    font-size: 16px;
    color: #94a3b8;
    cursor: pointer;
}

.rewards-filter-tabs {
    display: flex;
    gap: 6px;
}
.rewards-tab-btn {
    flex: 1;
    padding: 4px 6px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s ease;
}
.rewards-tab-btn.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.rewards-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 16px;
    background: #f8fafc;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.shop-reward-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: all 0.15s ease;
}
.shop-reward-card:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
}
.src-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.src-shop-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
    font-size: 14px;
    color: #0f172a;
}
.src-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    white-space: nowrap;
}
.badge-eligible {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}
.badge-claimed {
    background: #f3e8ff;
    color: #7e22ce;
    border: 1px solid #d8b4fe;
}
.badge-progress {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.src-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    font-size: 11px;
    color: #64748b;
    margin-bottom: 10px;
}
.src-phone-link {
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 2px;
}
.src-zone-tag {
    background: #f1f5f9;
    padding: 1px 6px;
    border-radius: 4px;
}
.src-cases-tag {
    background: #ecfdf5;
    color: #047857;
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
}

.src-progress-wrap {
    margin-bottom: 10px;
}
.src-progress-header {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #64748b;
    margin-bottom: 4px;
}
.src-progress-track {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}
.src-progress-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
}
.bar-normal { background: linear-gradient(90deg, #3b82f6, #2563eb); }
.bar-eligible { background: linear-gradient(90deg, #10b981, #059669); }
.bar-claimed { background: linear-gradient(90deg, #8b5cf6, #7c3aed); }

.src-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: #94a3b8;
    padding-top: 6px;
    border-top: 1px dashed #f1f5f9;
}
.btn-claim-reward {
    background: #059669;
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-claim-reward:hover { background: #047857; }
.btn-unclaim-reward {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 10px;
    cursor: pointer;
}
.rewards-empty {
    text-align: center;
    padding: 50px 20px;
    color: #94a3b8;
}
.rewards-empty i { font-size: 40px; margin-bottom: 8px; }
</style>

<!-- JavaScript Engine for Rewards Sidebar -->
<script>
function toggleRewardsSidebar(open) {
    const drawer = document.getElementById('rewardsSidebar');
    const overlay = document.getElementById('rewardsSidebarOverlay');
    if (!drawer || !overlay) return;

    if (open === undefined) {
        open = !drawer.classList.contains('open');
    }

    if (open) {
        drawer.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    } else {
        drawer.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

let activeRewardTab = 'all';
let currentRewardSearch = '';

function filterRewardsByTab(tab) {
    activeRewardTab = tab;
    document.querySelectorAll('.rewards-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
    });
    applyRewardsFilters();
}

function handleRewardsSearch(query) {
    currentRewardSearch = query.trim().toLowerCase();
    const clearBtn = document.getElementById('rewardsClearSearch');
    if (clearBtn) {
        clearBtn.style.display = currentRewardSearch.length > 0 ? 'block' : 'none';
    }
    applyRewardsFilters();
}

function clearRewardsSearch() {
    const input = document.getElementById('rewardsSearchInput');
    if (input) input.value = '';
    handleRewardsSearch('');
}

function applyRewardsFilters() {
    const cards = document.querySelectorAll('.shop-reward-card');
    cards.forEach(card => {
        const name = card.getAttribute('data-shop-name') || '';
        const phone = card.getAttribute('data-phone') || '';
        const status = card.getAttribute('data-status') || '';

        const matchesTab = (activeRewardTab === 'all') || (status === activeRewardTab);
        const matchesSearch = (currentRewardSearch === '') || name.includes(currentRewardSearch) || phone.includes(currentRewardSearch);

        card.style.display = (matchesTab && matchesSearch) ? 'block' : 'none';
    });
}

function claimShopReward(shopName, btn) {
    if (!confirm('Mark reward as Claimed for shop: ' + shopName + '?')) return;
    updateShopRewardStatusAjax(shopName, 'claimed', btn);
}

function unclaimShopReward(shopName, btn) {
    if (!confirm('Reset reward status for shop: ' + shopName + '?')) return;
    updateShopRewardStatusAjax(shopName, 'eligible', btn);
}

function updateShopRewardStatusAjax(shopName, status, btn) {
    if (btn) btn.disabled = true;
    const form = new FormData();
    form.append('action', 'update_shop_reward_status');
    form.append('shop_name', shopName);
    form.append('reward_status', status);

    const targetUrl = '<?= BASE_URL ?>/admin/qr-rewards/shop-rewards.php';
    fetch(targetUrl, {
        method: 'POST',
        body: form
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Failed to update reward status.');
            if (btn) btn.disabled = false;
        }
    })
    .catch(err => {
        // Fallback reload
        location.reload();
    });
}
</script>

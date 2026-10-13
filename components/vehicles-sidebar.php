<?php
/**
 * components/vehicles-sidebar.php
 * Interactive slide-out sidebar drawer for Zone Vehicle Loading & Dispatch Management.
 * Accessible from /zone/orders/dashboard and admin portals.
 */
if (defined('VEHICLES_SIDEBAR_INCLUDED')) {
    return;
}
define('VEHICLES_SIDEBAR_INCLUDED', true);

if (!isset($pdo)) {
    require_once dirname(__DIR__) . '/config/config.php';
}
if (!function_exists('getAllVehiclesWithLoads')) {
    require_once dirname(__DIR__) . '/admin/includes/functions.php';
}

// Fetch active zones and distinct shop names
try {
    $zonesStmt = $pdo->query("SELECT id, name, slug FROM zones WHERE status = 'active' ORDER BY name ASC");
    $vehiclesSidebarZones = $zonesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $vehiclesSidebarShops = function_exists('getAllShopNamesWithZones') ? getAllShopNamesWithZones($pdo) : [];
    $vehiclesSidebarProducts = $pdo->query("SELECT product_id, name, product_name FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $vehiclesSidebarZones = [];
    $vehiclesSidebarShops = [];
    $vehiclesSidebarProducts = [];
}
?>
<style>
/* =========================================================
   VEHICLES SLIDE-OUT SIDEBAR DRAWER STYLES
   ========================================================= */
.vehicles-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 9998;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.3s;
}
.vehicles-overlay.active {
    opacity: 1;
    visibility: visible;
}

.vehicles-drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    max-width: 480px;
    background: #ffffff;
    box-shadow: -8px 0 35px -5px rgba(15, 23, 42, 0.25);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    color: #0f172a;
}
.vehicles-drawer.open {
    transform: translateX(0);
}

/* Header */
.vehicles-drawer-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    flex-shrink: 0;
}
.vehicles-header-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(37, 99, 235, 0.25);
    border: 1px solid rgba(96, 165, 250, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #60a5fa;
    flex-shrink: 0;
}
.vehicles-header-title {
    font-size: 16px;
    font-weight: 800;
    line-height: 1.2;
    color: #ffffff;
}
.vehicles-header-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 2px;
}
.vehicles-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #ffffff;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    cursor: pointer;
    transition: background 0.15s ease;
}
.vehicles-close-btn:hover {
    background: rgba(255, 255, 255, 0.25);
}

/* Toolbar & Zone Filter */
.vehicles-toolbar {
    padding: 12px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 10px;
    flex-shrink: 0;
}
.vehicles-top-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.btn-toggle-add-vehicle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
}
.btn-toggle-add-vehicle:hover {
    background: #1d4ed8;
}
.btn-refresh-vehicles {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 7px 10px;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.btn-refresh-vehicles:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.vehicles-zone-select-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.vehicles-zone-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    white-space: nowrap;
}
.vehicles-zone-select {
    flex-grow: 1;
    padding: 6px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    cursor: pointer;
}
.vehicles-zone-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
}

/* Add Vehicle Form Box (Accordion) */
.add-vehicle-collapse {
    display: none;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 14px;
    margin-top: 4px;
}
.add-vehicle-collapse.open {
    display: block;
    animation: fadeInDown 0.2s ease-out;
}
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.add-vehicle-title {
    font-size: 13px;
    font-weight: 800;
    color: #1e3a8a;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.add-v-field {
    margin-bottom: 8px;
}
.add-v-field label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: #3b82f6;
    margin-bottom: 3px;
}
.add-v-input, .add-v-select {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid #93c5fd;
    border-radius: 6px;
    font-size: 12px;
    background: #ffffff;
    outline: none;
    font-family: inherit;
    box-sizing: border-box;
}
.add-v-input:focus, .add-v-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
}
.add-v-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.add-v-btns {
    display: flex;
    gap: 8px;
    margin-top: 10px;
}
.btn-save-vehicle {
    flex-grow: 1;
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
.btn-save-vehicle:hover { background: #1d4ed8; }
.btn-cancel-add-v {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
}

/* KPI Summary Cards Bar */
.vehicles-kpi-bar {
    padding: 10px 18px;
    display: grid;
    grid-template-columns: 1fr 1.2fr 1fr;
    gap: 8px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    flex-shrink: 0;
}
.v-kpi-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 8px 10px;
    text-align: center;
}
.v-kpi-card.highlight {
    background: #ecfdf5;
    border-color: #a7f3d0;
}
.v-kpi-card.highlight-blue {
    background: #eff6ff;
    border-color: #bfdbfe;
}
.v-kpi-val {
    font-size: 16px;
    font-weight: 800;
    line-height: 1.1;
    color: #0f172a;
}
.v-kpi-lbl {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    margin-top: 2px;
}
.v-kpi-card.highlight .v-kpi-val { color: #047857; }
.v-kpi-card.highlight .v-kpi-lbl { color: #065f46; }
.v-kpi-card.highlight-blue .v-kpi-val { color: #1d4ed8; }
.v-kpi-card.highlight-blue .v-kpi-lbl { color: #1e40af; }

/* Vehicle Feed List */
.vehicles-feed-body {
    flex-grow: 1;
    overflow-y: auto;
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #f8fafc;
}

/* Individual Vehicle Card */
.vehicle-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    position: relative;
}
.vehicle-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px -3px rgba(0, 0, 0, 0.08);
}
.vehicle-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
}
.vehicle-plate-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #0f172a;
    color: #f8fafc;
    font-weight: 800;
    font-size: 13px;
    padding: 4px 10px;
    border-radius: 8px;
    letter-spacing: 0.03em;
}
.vehicle-name-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
    margin-top: 3px;
}

.vehicle-status-pill {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    padding: 3px 8px;
    border-radius: 6px;
}
.v-stat-active { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.v-stat-loading { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; animation: pulseLoading 2s infinite; }
.v-stat-dispatched { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.v-stat-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

@keyframes pulseLoading {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}

.vehicle-driver-info {
    font-size: 12px;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.vehicle-driver-info a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

/* Loading Metrics Grid inside Card */
.vehicle-load-metrics {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    background: #f1f5f9;
    padding: 10px 12px;
    border-radius: 10px;
    margin-bottom: 10px;
}
.v-metric-item {
    display: flex;
    flex-direction: column;
}
.v-metric-item.accent {
    border-left: 2px solid #2563eb;
    padding-left: 8px;
}
.v-metric-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
}
.v-metric-value {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    margin-top: 1px;
}
.v-metric-sub {
    font-size: 10px;
    color: #64748b;
}

/* Progress Bar */
.v-progress-container {
    height: 8px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 10px;
}
.v-progress-bar {
    height: 100%;
    border-radius: 999px;
    transition: width 0.4s ease;
}
.v-prog-green { background: linear-gradient(90deg, #10b981, #059669); }
.v-prog-amber { background: linear-gradient(90deg, #f59e0b, #d97706); }
.v-prog-red { background: linear-gradient(90deg, #ef4444, #dc2626); }

/* Zone Breakdown Badges */
.v-zone-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
}
.v-zone-chip {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 6px;
    background: #e0e7ff;
    color: #3730a3;
    border: 1px solid #c7d2fe;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Actions Row */
.vehicle-card-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    border-top: 1px solid #f1f5f9;
    padding-top: 10px;
}
.btn-v-action {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 10px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    transition: all 0.15s ease;
}
.btn-v-action:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.btn-v-load {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}
.btn-v-load:hover {
    background: #1d4ed8;
    color: #ffffff;
}
.btn-v-dispatch {
    background: #ecfdf5;
    color: #047857;
    border-color: #a7f3d0;
}
.btn-v-dispatch:hover {
    background: #d1fae5;
}
.btn-v-reset {
    color: #dc2626;
    border-color: #fecaca;
    background: #fef2f2;
}
.btn-v-reset:hover {
    background: #fee2e2;
}

/* Quick Load Modal Inside Sidebar */
.v-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    z-index: 10005;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.v-modal-overlay.open {
    display: flex;
}
.v-modal-box {
    background: #ffffff;
    border-radius: 16px;
    max-width: 420px;
    width: 100%;
    padding: 20px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25);
}
</style>

<!-- Slide-Out Backdrop -->
<div id="vehiclesSidebarOverlay" class="vehicles-overlay" onclick="toggleVehiclesSidebar(false)"></div>

<!-- Slide-Out Drawer -->
<aside id="vehiclesSidebar" class="vehicles-drawer" aria-label="Vehicle Loading and Dispatch Sidebar">
    <!-- Header -->
    <div class="vehicles-drawer-header">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="vehicles-header-icon">
                <i class='bx bxs-truck'></i>
            </div>
            <div>
                <h2 class="vehicles-header-title">Vehicles & Loading Hub</h2>
                <p class="vehicles-header-sub">Track Loaded Cases by Zone & Grand Totals</p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="<?= BASE_URL ?>/admin/vehicles/index.php" style="color: #93c5fd; font-size: 11px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.2); padding: 5px 9px; border-radius: 6px; transition: background 0.15s ease;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'" title="Open Full Page Vehicles Management">
                <i class='bx bx-fullscreen'></i> Full Page
            </a>
            <button type="button" class="vehicles-close-btn" onclick="toggleVehiclesSidebar(false)" aria-label="Close vehicles sidebar">
                &times;
            </button>
        </div>
    </div>

    <!-- Toolbar: Zone Filter & Add Vehicle Toggle -->
    <div class="vehicles-toolbar">
        <div class="vehicles-top-actions">
            <button type="button" class="btn-toggle-add-vehicle" onclick="toggleAddVehicleCollapse()">
                <i class='bx bx-plus-circle'></i> <span id="addVehicleBtnText">+ Add New Vehicle</span>
            </button>
            <button type="button" class="btn-refresh-vehicles" onclick="refreshVehiclesData()" title="Reload Vehicle Data">
                <i class='bx bx-refresh' id="vRefreshIcon"></i>
            </button>
        </div>

        <!-- Add Vehicle Collapsible Form -->
        <div id="addVehicleCollapse" class="add-vehicle-collapse">
            <div class="add-vehicle-title">
                <i class='bx bxs-truck'></i> Register Vehicle to Fleet
            </div>
            <form id="formAddVehicle" onsubmit="submitAddVehicle(event)">
                <div class="add-v-field">
                    <label>Vehicle Number / Plate *</label>
                    <input type="text" name="vehicle_number" class="add-v-input" placeholder="e.g. KA 19 AB 1234 / TN 38 XY 5678" required style="text-transform: uppercase;">
                </div>
                <div class="add-v-field">
                    <label>Vehicle / Model</label>
                    <input type="text" name="vehicle_name" class="add-v-input" placeholder="e.g. Tata Ace / Bolero">
                </div>
                <div class="add-v-row-2">
                    <div class="add-v-field">
                        <label>Driver Name</label>
                        <input type="text" name="driver_name" class="add-v-input" placeholder="e.g. Rajesh">
                    </div>
                    <div class="add-v-field">
                        <label>Driver Mobile</label>
                        <input type="tel" name="driver_phone" class="add-v-input" placeholder="10-digit phone">
                    </div>
                </div>
                <div class="add-v-field">
                    <label>Base / Home Zone</label>
                    <select name="zone_id" class="add-v-select">
                        <option value="">-- All / Central Warehouse --</option>
                        <?php foreach ($vehiclesSidebarZones as $z): ?>
                            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="add-v-btns">
                    <button type="submit" class="btn-save-vehicle" id="btnSaveVehicle">
                        <i class='bx bx-check'></i> Register Vehicle
                    </button>
                    <button type="button" class="btn-cancel-add-v" onclick="toggleAddVehicleCollapse(false)">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- Particular Zone Filter Selector -->
        <div class="vehicles-zone-select-row">
            <span class="vehicles-zone-label"><i class='bx bx-map-pin'></i> Loading Zone:</span>
            <select id="vFilterZoneSelect" class="vehicles-zone-select" onchange="handleVehicleZoneChange(this.value)">
                <option value="all">All Zones Combined</option>
                <?php foreach ($vehiclesSidebarZones as $z): ?>
                    <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- KPI Summary Bar -->
    <div class="vehicles-kpi-bar">
        <div class="v-kpi-card">
            <div class="v-kpi-val" id="vKpiTotalVehicles">0</div>
            <div class="v-kpi-lbl">Vehicles</div>
        </div>
        <div class="v-kpi-card highlight">
            <div class="v-kpi-val" id="vKpiCurrentZoneCases">0 cs</div>
            <div class="v-kpi-lbl" id="vKpiCurrentZoneLabel">Zone Cases</div>
        </div>
        <div class="v-kpi-card highlight-blue">
            <div class="v-kpi-val" id="vKpiGrandTotalCases">0 cs</div>
            <div class="v-kpi-lbl">Grand Total</div>
        </div>
    </div>

    <!-- Vehicles List Feed -->
    <div class="vehicles-feed-body" id="vehiclesFeedBody">
        <div style="text-align: center; padding: 40px 0; color: #64748b;">
            <i class='bx bx-loader-alt bx-spin' style="font-size: 28px; color: #2563eb;"></i>
            <div style="font-size: 13px; margin-top: 8px;">Loading vehicle fleet & case loads...</div>
        </div>
    </div>
</aside>

<!-- Quick Load Cases Modal -->
<div id="quickLoadModal" class="v-modal-overlay">
    <div class="v-modal-box">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a;" id="qlModalTitle">Load Cases to Vehicle</h3>
                <p style="font-size: 12px; color: #64748b;" id="qlModalSubtitle">Select zone and number of cases</p>
            </div>
            <button type="button" class="vehicles-close-btn" style="color: #64748b; background: #f1f5f9;" onclick="closeQuickLoadModal()">&times;</button>
        </div>
        <form id="formQuickLoad" onsubmit="submitQuickLoad(event)">
            <input type="hidden" name="vehicle_id" id="qlVehicleId" value="">
            <div class="add-v-field">
                <label>Source / Dispatch Zone *</label>
                <select name="zone_id" id="qlZoneId" class="add-v-select" required>
                    <?php foreach ($vehiclesSidebarZones as $z): ?>
                        <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="add-v-field">
                <label><i class='bx bx-package'></i> Product (For Vehicle Storage / Specific Buffer)</label>
                <select name="product_id" id="qlProductId" class="add-v-select">
                    <option value="">-- Generic Buffer / Storage --</option>
                    <?php foreach ($vehiclesSidebarProducts as $p): ?>
                        <option value="<?= (int)$p['product_id'] ?>"><?= htmlspecialchars($p['product_name'] ?: $p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="add-v-field">
                <label>Number of Cases to Load *</label>
                <input type="number" name="cases" id="qlCasesInput" class="add-v-input" placeholder="e.g. 50" min="1" required autofocus>
            </div>
            <div class="add-v-field">
                <label><i class='bx bx-store'></i> Shop Name (Fetched from Orders)</label>
                <input type="text" name="shop_name" id="qlShopNameInput" list="qlShopList" class="add-v-input" placeholder="Select or type shop name..." autocomplete="off">
                <datalist id="qlShopList">
                    <?php foreach ($vehiclesSidebarShops as $s): ?>
                        <option value="<?= htmlspecialchars($s['shop_name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="add-v-field">
                <label>Loading Notes (Optional)</label>
                <input type="text" name="notes" class="add-v-input" placeholder="e.g. Route 1 Dispatch / Morning Batch">
            </div>
            <div class="add-v-btns">
                <button type="submit" class="btn-save-vehicle" id="btnSubmitQuickLoad">
                    <i class='bx bx-check'></i> Confirm Load Cases
                </button>
                <button type="button" class="btn-cancel-add-v" onclick="closeQuickLoadModal()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * Vehicles Sidebar JavaScript Engine
 */
const VEH_API_URL = '<?= rtrim(BASE_URL, "/") ?>'.replace(/^https?:\/\/[^\/]+/i, window.location.origin) + '/zone/orders/dashboard/vehicles_api.php';
let currentVehiclesFilterZone = 'all';
let cachedVehiclesData = null;

function toggleVehiclesSidebar(open) {
    const drawer = document.getElementById('vehiclesSidebar');
    const overlay = document.getElementById('vehiclesSidebarOverlay');
    if (!drawer || !overlay) return;

    if (open) {
        drawer.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        refreshVehiclesData();
    } else {
        drawer.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function openVehiclesSidebar(preferredZone = null) {
    if (preferredZone !== null && preferredZone !== undefined && preferredZone !== '') {
        currentVehiclesFilterZone = preferredZone;
        const sel = document.getElementById('vFilterZoneSelect');
        if (sel) {
            sel.value = preferredZone;
        }
    }
    toggleVehiclesSidebar(true);
}

function toggleAddVehicleCollapse(force) {
    const box = document.getElementById('addVehicleCollapse');
    const text = document.getElementById('addVehicleBtnText');
    if (!box) return;

    const willOpen = (force !== undefined) ? force : !box.classList.contains('open');
    if (willOpen) {
        box.classList.add('open');
        if (text) text.textContent = '▲ Close Form';
    } else {
        box.classList.remove('open');
        if (text) text.textContent = '+ Add New Vehicle';
    }
}

function handleVehicleZoneChange(zoneVal) {
    currentVehiclesFilterZone = zoneVal;
    refreshVehiclesData();
}

function refreshVehiclesData() {
    const icon = document.getElementById('vRefreshIcon');
    if (icon) icon.classList.add('bx-spin');

    const apiUrl = VEH_API_URL + '?action=get_vehicles&zone=' + encodeURIComponent(currentVehiclesFilterZone);

    fetch(apiUrl)
        .then(r => r.json())
        .then(data => {
            if (icon) icon.classList.remove('bx-spin');
            if (data.success) {
                cachedVehiclesData = data;
                renderVehicles(data);
            } else {
                renderVehiclesError(data.error || 'Failed to load vehicle data.');
            }
        })
        .catch(err => {
            if (icon) icon.classList.remove('bx-spin');
            renderVehiclesError('Network error connecting to vehicle hub.');
        });
}

function renderVehicles(data) {
    const body = document.getElementById('vehiclesFeedBody');
    const kpiVehicles = document.getElementById('vKpiTotalVehicles');
    const kpiCurrentZone = document.getElementById('vKpiCurrentZoneCases');
    const kpiGrandTotal = document.getElementById('vKpiGrandTotalCases');
    const kpiZoneLabel = document.getElementById('vKpiCurrentZoneLabel');

    if (kpiVehicles) kpiVehicles.textContent = data.summary.total_vehicles || 0;
    if (kpiCurrentZone) kpiCurrentZone.textContent = (data.summary.current_zone_cases_total || 0) + ' cs';
    if (kpiGrandTotal) kpiGrandTotal.textContent = (data.summary.grand_total_cases || 0) + ' cs';

    if (kpiZoneLabel) {
        if (currentVehiclesFilterZone === 'all') {
            kpiZoneLabel.textContent = 'All Zones';
        } else {
            const sel = document.getElementById('vFilterZoneSelect');
            const zoneText = sel && sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : 'Zone Cases';
            kpiZoneLabel.textContent = zoneText;
        }
    }

    if (!body) return;

    if (!data.vehicles || data.vehicles.length === 0) {
        body.innerHTML = `
            <div style="text-align: center; padding: 40px 10px; color: #64748b;">
                <i class='bx bxs-truck' style="font-size: 38px; color: #cbd5e1; margin-bottom: 8px;"></i>
                <h4 style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">No Vehicles Registered Yet</h4>
                <p style="font-size: 12px; margin-bottom: 12px;">Add your first vehicle above to track cases loaded and dispatches.</p>
                <button type="button" class="btn-toggle-add-vehicle" onclick="toggleAddVehicleCollapse(true)">
                    <i class='bx bx-plus-circle'></i> Add First Vehicle
                </button>
            </div>
        `;
        return;
    }

    let html = '';
    data.vehicles.forEach(v => {
        const vId = v.id;
        const vNum = v.vehicle_number || 'VEHICLE';
        const vName = v.vehicle_name || 'Delivery Truck';
        const driver = v.driver_name || 'Driver Unassigned';
        const phone = v.driver_phone || '';
        const status = v.status || 'active';
        const totalCases = parseInt(v.total_cases_loaded || 0);
        const zoneCases = parseInt(v.current_zone_cases || 0);
        const ordersCount = parseInt(v.assigned_orders_count || 0);

        let statusClass = 'v-stat-active';
        let statusText = 'Ready';
        if (status === 'loading') { statusClass = 'v-stat-loading'; statusText = 'Loading'; }
        else if (status === 'dispatched') { statusClass = 'v-stat-dispatched'; statusText = 'Dispatched'; }
        else if (status === 'inactive') { statusClass = 'v-stat-inactive'; statusText = 'Inactive'; }

        // Cargo and Storage breakdown
        let cargoHtml = '';
        const storageItems = Array.isArray(v.storage_items) ? v.storage_items : [];
        const orderItems = Array.isArray(v.order_items) ? v.order_items : [];

        if (storageItems.length > 0 || orderItems.length > 0) {
            cargoHtml += '<div style="margin: 8px 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px;">';
            
            if (storageItems.length > 0) {
                cargoHtml += `
                    <div style="margin-bottom: ${orderItems.length > 0 ? '6px' : '0'};">
                        <div style="font-size: 10px; font-weight: 800; text-transform: uppercase; color: #b45309; display: flex; align-items: center; gap: 4px;">
                            <i class='bx bx-archive' style="font-size: 13px;"></i> In Vehicle Storage (${v.storage_cases || 0} cs):
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px;">
                            ${storageItems.map(si => `
                                <span style="font-size: 11px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-weight: 700; padding: 2px 7px; border-radius: 6px;">
                                    ${si.product_name}: <strong>${si.cases} cs</strong>
                                </span>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            if (orderItems.length > 0) {
                cargoHtml += `
                    <div>
                        <div style="font-size: 10px; font-weight: 800; text-transform: uppercase; color: #2563eb; display: flex; align-items: center; gap: 4px;">
                            <i class='bx bx-package' style="font-size: 13px;"></i> For Active Orders:
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px;">
                            ${orderItems.map(oi => `
                                <span style="font-size: 11px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; font-weight: 700; padding: 2px 7px; border-radius: 6px;">
                                    ${oi.product_name}: <strong>${oi.cases} cs</strong>
                                </span>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            cargoHtml += '</div>';
        }

        // Breakdown pills
        let breakdownPills = '';
        if (Array.isArray(v.zone_breakdown) && v.zone_breakdown.length > 0) {
            v.zone_breakdown.forEach(zb => {
                breakdownPills += `
                    <span class="v-zone-chip" title="${zb.zone_name}">
                        <i class='bx bx-map-pin'></i> ${zb.zone_name}: <strong>${zb.cases} cs</strong>
                    </span>
                `;
            });
        } else {
            breakdownPills = `<span style="font-size: 11px; color: #94a3b8;">No zone cases loaded yet</span>`;
        }

        html += `
            <div class="vehicle-card" id="vCard_${vId}">
                <div class="vehicle-card-header">
                    <div>
                        <div class="vehicle-plate-badge">
                            <i class='bx bxs-truck'></i> ${vNum}
                        </div>
                        <div class="vehicle-name-label">${vName}</div>
                    </div>
                    <span class="vehicle-status-pill ${statusClass}">${statusText}</span>
                </div>

                <div class="vehicle-driver-info">
                    <span><i class='bx bx-user'></i> ${driver}</span>
                    ${phone ? `<a href="tel:${phone}"><i class='bx bx-phone'></i> ${phone}</a>` : ''}
                </div>

                <!-- Prominent Loaded Metrics (No Capacity Ceiling) -->
                <div class="vehicle-load-metrics">
                    <div class="v-metric-item accent">
                        <span class="v-metric-title">From This Zone</span>
                        <span class="v-metric-value" style="color: #2563eb;">${zoneCases} Cases</span>
                        <span class="v-metric-sub">${currentVehiclesFilterZone === 'all' ? 'All zones combined' : 'Selected zone'}</span>
                    </div>
                    <div class="v-metric-item">
                        <span class="v-metric-title">Total Vehicle Load</span>
                        <span class="v-metric-value">${totalCases} Cases</span>
                        <span class="v-metric-sub">${ordersCount} Assigned Orders</span>
                    </div>
                </div>

                <!-- Vehicle Cargo & Storage Inventory Breakdown -->
                ${cargoHtml}

                <!-- Lifetime Persistent Stats (Never Reset) -->
                <div style="display: flex; gap: 6px; margin-bottom: 10px;">
                    <div style="flex: 1; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 7px; padding: 6px 8px; text-align: center;">
                        <div style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: #065f46;">Deliveries Done</div>
                        <div style="font-size: 14px; font-weight: 800; color: #047857;">${v.deliveries_done || 0}</div>
                    </div>
                    <div style="flex: 1; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 7px; padding: 6px 8px; text-align: center;">
                        <div style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: #1e40af;">Loads Delivered</div>
                        <div style="font-size: 14px; font-weight: 800; color: #1d4ed8;">${v.loads_taken_to_delivery || 0} cs</div>
                    </div>
                </div>

                <!-- Zone Breakdown -->
                <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Zone Breakdown:</div>
                <div class="v-zone-chips">
                    ${breakdownPills}
                </div>

                <!-- Action Controls -->
                <div class="vehicle-card-actions">
                    <button type="button" class="btn-v-action btn-v-load" onclick="openQuickLoadModal(${vId}, '${vNum}')" title="Load cases onto this vehicle">
                        <i class='bx bx-plus'></i> Load Cases
                    </button>
                    ${status !== 'dispatched' ? `
                        <button type="button" class="btn-v-action btn-v-dispatch" onclick="setVehicleStatus(${vId}, 'dispatched')" title="Mark vehicle as dispatched">
                            <i class='bx bx-paper-plane'></i> Dispatch
                        </button>
                    ` : `
                        <button type="button" class="btn-v-action" onclick="setVehicleStatus(${vId}, 'active')" title="Mark vehicle back in yard">
                            <i class='bx bx-check'></i> In Yard
                        </button>
                    `}
                    <button type="button" class="btn-v-action btn-v-reset" onclick="resetVehicleLoad(${vId}, '${vNum}')" title="Clear vehicle load">
                        <i class='bx bx-reset'></i> Reset
                    </button>
                </div>
            </div>
        `;
    });

    body.innerHTML = html;
}

function renderVehiclesError(msg) {
    const body = document.getElementById('vehiclesFeedBody');
    if (body) {
        body.innerHTML = `
            <div style="text-align: center; padding: 30px 10px; color: #dc2626;">
                <i class='bx bx-error-circle' style="font-size: 32px; margin-bottom: 6px;"></i>
                <div style="font-size: 13px; font-weight: 600;">${msg}</div>
                <button type="button" class="btn-v-action" onclick="refreshVehiclesData()" style="margin-top: 10px;">Retry</button>
            </div>
        `;
    }
}

function submitAddVehicle(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSaveVehicle');
    if (btn) btn.disabled = true;

    const fd = new FormData(form);
    fd.append('action', 'add_vehicle');

    fetch(VEH_API_URL, {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (btn) btn.disabled = false;
        if (data.success) {
            form.reset();
            toggleAddVehicleCollapse(false);
            refreshVehiclesData();
        } else {
            alert(data.error || 'Failed to add vehicle.');
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('Error adding vehicle.');
    });
}

function openQuickLoadModal(vehicleId, vehicleNumber) {
    const modal = document.getElementById('quickLoadModal');
    const inputVid = document.getElementById('qlVehicleId');
    const title = document.getElementById('qlModalTitle');
    const subtitle = document.getElementById('qlModalSubtitle');
    const casesInput = document.getElementById('qlCasesInput');

    if (inputVid) inputVid.value = vehicleId;
    if (title) title.textContent = 'Load Cases: ' + vehicleNumber;
    if (subtitle) subtitle.textContent = 'Enter cases loaded from particular zone into ' + vehicleNumber;

    // Set zone dropdown to current zone if available
    const qlZone = document.getElementById('qlZoneId');
    if (qlZone && currentVehiclesFilterZone !== 'all') {
        qlZone.value = currentVehiclesFilterZone;
    }

    if (casesInput) {
        casesInput.value = '';
        setTimeout(() => casesInput.focus(), 100);
    }

    const shopIn = document.getElementById('qlShopNameInput');
    if (shopIn) shopIn.value = '';

    if (modal) modal.classList.add('open');
}

function closeQuickLoadModal() {
    const modal = document.getElementById('quickLoadModal');
    if (modal) modal.classList.remove('open');
}

function submitQuickLoad(e) {
    e.preventDefault();
    const form = e.target;
    const btn = document.getElementById('btnSubmitQuickLoad');
    const vId = parseInt(document.getElementById('qlVehicleId')?.value || 0);
    const cases = parseInt(document.getElementById('qlCasesInput')?.value || 0);

    if (!vId || vId <= 0) {
        alert('Invalid or missing vehicle. Please re-open the load modal.');
        return;
    }
    if (!cases || cases <= 0) {
        alert('Please enter a positive number of cases (at least 1 case).');
        document.getElementById('qlCasesInput')?.focus();
        return;
    }

    if (btn) btn.disabled = true;

    const fd = new FormData(form);
    fd.append('action', 'load_cases');

    fetch(VEH_API_URL, {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (btn) btn.disabled = false;
        if (data.success) {
            closeQuickLoadModal();
            refreshVehiclesData();
        } else {
            alert(data.error || 'Failed to record load.');
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('Error saving load.');
    });
}

function setVehicleStatus(vehicleId, status) {
    const fd = new FormData();
    fd.append('action', 'update_vehicle_status');
    fd.append('vehicle_id', vehicleId);
    fd.append('status', status);

    fetch(VEH_API_URL, {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            refreshVehiclesData();
        } else {
            alert(data.error || 'Failed to update status.');
        }
    });
}

function resetVehicleLoad(vehicleId, vehicleNumber) {
    if (!confirm('Are you sure you want to reset all loaded cases and orders for vehicle ' + vehicleNumber + '?')) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'reset_vehicle_load');
    fd.append('vehicle_id', vehicleId);

    fetch(VEH_API_URL, {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            refreshVehiclesData();
        } else {
            alert(data.error || 'Failed to reset load.');
        }
    });
}

// Quick assign / unassign order to vehicle helper function for order cards
if (typeof window.assignOrderToVehicleFromCard !== 'function') {
    window.assignOrderToVehicleFromCard = function(orderId, vehicleId) {
        const parsedId = parseInt(orderId || 0);
        if (!parsedId || parsedId <= 0) {
            alert('Invalid order ID.');
            return;
        }
        const selectElem = document.getElementById('orderVehSelect_' + parsedId);
        if (selectElem) selectElem.disabled = true;

        const fd = new FormData();
        fd.append('order_id', parsedId);
        if (!vehicleId) {
            fd.append('action', 'unassign_order');
        } else {
            fd.append('action', 'assign_order');
            fd.append('vehicle_id', vehicleId);
        }

        fetch(VEH_API_URL, {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (selectElem) selectElem.disabled = false;
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast('Vehicle Updated', data.message || 'Vehicle assignment updated.');
                    if (typeof refreshVehiclesData === 'function') refreshVehiclesData();
                    if (typeof pollOrders === 'function') setTimeout(pollOrders, 300);
                } else {
                    location.reload();
                }
            } else {
                alert(data.error || 'Failed to update vehicle assignment.');
                if (typeof pollOrders === 'function') setTimeout(pollOrders, 300);
            }
        })
        .catch(err => {
            if (selectElem) selectElem.disabled = false;
            console.error('Vehicle assign error:', err);
        });
    };
}
</script>

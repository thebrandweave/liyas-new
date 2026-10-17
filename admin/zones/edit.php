<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "zones";
$page_title = "Edit Delivery Zone";
$error = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
$stmt->execute([$id]);
$zone = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$zone) {
    header("Location: index.php");
    exit;
}

// Handle unassigning vehicle from this zone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unassign_vehicle'])) {
    $unassignVehId = (int)($_POST['unassign_vehicle_id'] ?? 0);
    if ($unassignVehId > 0) {
        $upd = $pdo->prepare("UPDATE vehicles SET zone_id = NULL, updated_at = NOW() WHERE id = ? AND zone_id = ?");
        $upd->execute([$unassignVehId, $id]);
        quickLog($pdo, 'update', 'vehicle', $unassignVehId, "Unassigned vehicle #{$unassignVehId} from zone {$zone['name']} (#{$id})");
        $_SESSION['success_message'] = "Vehicle unassigned from zone successfully.";
        header("Location: edit.php?id=" . $id);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_zone'])) {
    $name = trim($_POST['name'] ?? '');
    $custom_slug = trim($_POST['slug'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    $access_code = trim($_POST['access_code'] ?? 'ZONE2026');
    if (empty($access_code)) $access_code = 'ZONE2026';

    if (empty($name)) {
        $error = "Zone name is required.";
    } else {
        $slug = !empty($custom_slug) ? sanitizeZoneSlug($custom_slug) : sanitizeZoneSlug($name);

        $reserved = ['admin', 'api', 'assets', 'uploads', 'config', 'vendor', 'public', 'join', 'redeem', 'error-pages', 'zone', 'cart', 'orders', 'products'];
        if (in_array($slug, $reserved)) {
            $error = "The slug '{$slug}' is a reserved system route.";
        } else {
            // Check if slug already taken by another zone
            $check = $pdo->prepare("SELECT id FROM zones WHERE slug = ? AND id != ?");
            $check->execute([$slug, $id]);
            if ($check->fetch()) {
                $error = "Another zone already uses the route '/{$slug}'. Please choose a different slug.";
            } else {
                try {
                    $updateStmt = $pdo->prepare("UPDATE zones SET name = ?, slug = ?, access_code = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$name, $slug, $access_code, $status, $id]);

                    quickLog($pdo, 'update', 'zone', $id, "Updated zone: {$name} (/{$slug})");

                    // Assign / Fix vehicle to this zone
                    $existing_veh_id = (int)($_POST['existing_vehicle_id'] ?? 0);
                    $v_number = strtoupper(trim($_POST['vehicle_number'] ?? ''));
                    $v_name   = trim($_POST['vehicle_name'] ?? '');
                    $d_name   = trim($_POST['driver_name'] ?? '');
                    $d_phone  = trim($_POST['driver_phone'] ?? '');

                    if ($existing_veh_id > 0) {
                        $updVeh = $pdo->prepare("
                            UPDATE vehicles 
                            SET zone_id = ?, 
                                vehicle_name = COALESCE(NULLIF(?, ''), vehicle_name),
                                driver_name = COALESCE(NULLIF(?, ''), driver_name),
                                driver_phone = COALESCE(NULLIF(?, ''), driver_phone),
                                updated_at = NOW() 
                            WHERE id = ?
                        ");
                        $updVeh->execute([$id, $v_name, $d_name, $d_phone, $existing_veh_id]);
                        quickLog($pdo, 'update', 'vehicle', $existing_veh_id, "Fixed vehicle #{$existing_veh_id} to zone {$name} (#{$id})");
                    } elseif (!empty($v_number)) {
                        $chkStmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? LIMIT 1");
                        $chkStmt->execute([$v_number]);
                        $matchedVehId = (int)$chkStmt->fetchColumn();

                        if ($matchedVehId > 0) {
                            $updVeh = $pdo->prepare("
                                UPDATE vehicles 
                                SET zone_id = ?, 
                                    vehicle_name = COALESCE(NULLIF(?, ''), vehicle_name),
                                    driver_name = COALESCE(NULLIF(?, ''), driver_name),
                                    driver_phone = COALESCE(NULLIF(?, ''), driver_phone),
                                    updated_at = NOW() 
                                WHERE id = ?
                            ");
                            $updVeh->execute([$id, $v_name, $d_name, $d_phone, $matchedVehId]);
                            quickLog($pdo, 'update', 'vehicle', $matchedVehId, "Fixed vehicle {$v_number} to zone {$name} (#{$id})");
                        } else {
                            $vehRes = addVehicleRecord($pdo, [
                                'vehicle_number' => $v_number,
                                'vehicle_name'   => $v_name,
                                'driver_name'    => $d_name,
                                'driver_phone'   => $d_phone,
                                'zone_id'        => $id,
                                'capacity_cases' => 100,
                                'status'         => 'active'
                            ]);
                            if ($vehRes['success']) {
                                quickLog($pdo, 'create', 'vehicle', $vehRes['vehicle_id'], "Registered vehicle {$v_number} fixed to zone {$name} (#{$id})");
                            }
                        }
                    }

                    header("Location: index.php?updated=1");
                    exit;
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch vehicles currently assigned to this zone
$assignedVehicles = [];
$otherVehicles = [];
try {
    $assignedStmt = $pdo->prepare("
        SELECT * FROM vehicles 
        WHERE zone_id = ? 
        ORDER BY vehicle_number ASC
    ");
    $assignedStmt->execute([$id]);
    $assignedVehicles = $assignedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fetch other vehicles available for assignment
    $otherStmt = $pdo->prepare("
        SELECT v.id, v.vehicle_number, v.vehicle_name, v.driver_name, v.driver_phone, v.zone_id, z.name as zone_name
        FROM vehicles v
        LEFT JOIN zones z ON v.zone_id = z.id
        WHERE v.status != 'inactive' AND (v.zone_id != ? OR v.zone_id IS NULL)
        ORDER BY v.vehicle_number ASC
    ");
    $otherStmt->execute([$id]);
    $otherVehicles = $otherStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $assignedVehicles = [];
    $otherVehicles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Delivery Zone - Liyas Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .form-card {
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 2rem;
            max-width: 650px;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }
        .form-control {
            width: 100%;
            padding: 0.65rem 0.15rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .route-preview {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            color: #475569;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .route-preview strong {
            color: #2563eb;
            font-family: monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <a href="index.php" style="color: inherit; text-decoration: none;">Delivery Zones</a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Edit Zone</span>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($_SESSION['success_message'])): ?>
                    <div class="alert alert-success" style="padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 1.5rem; max-width: 650px;">
                        <?= htmlspecialchars($_SESSION['success_message']) ?>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem; max-width: 650px;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 0.5rem; color: #111827;">Edit Delivery Zone</h2>
                  

                    <form action="edit.php?id=<?= $id ?>" method="POST" id="zoneForm">
                        <div class="form-group">
                            <label class="form-label" for="name">Route Name <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? $zone['name']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slug">Vehicle Name</label>
                            <input type="text" name="slug" id="slug" class="form-control" value="<?= htmlspecialchars($_POST['slug'] ?? $zone['slug']) ?>">
                            <div class="route-preview">
                                <i class='bx bx-link'></i>
                                Portal URL: <span id="previewUrl"><strong><?= BASE_URL ?>/<?= htmlspecialchars($zone['slug']) ?></strong></span>
                            </div>
                        </div>

                        <!-- ASSIGNED VEHICLES SECTION -->
                        <div class="vehicle-assign-section" style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-top: 1.5rem; margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                 
                                    <div>
                                     
                                    </div>
                                </div>
                            
                            </div>

                            <!-- List of currently assigned vehicles -->
                            <?php if (!empty($assignedVehicles)): ?>
                                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                                    <?php foreach ($assignedVehicles as $av): ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px;">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <i class='bx bxs-truck' style="color: #2563eb; font-size: 20px;"></i>
                                                <div>
                                                    <span style="font-weight: 700; font-family: monospace; font-size: 13px; color: #0f172a;">
                                                        <?= htmlspecialchars($av['vehicle_number']) ?>
                                                    </span>
                                                    <?php if (!empty($av['vehicle_name'])): ?>
                                                        <span style="font-size: 12px; color: #475569; margin-left: 4px;">&bull; <?= htmlspecialchars($av['vehicle_name']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($av['driver_name'])): ?>
                                                        <div style="font-size: 11px; color: #64748b;">
                                                            Driver: <?= htmlspecialchars($av['driver_name']) ?> <?= !empty($av['driver_phone']) ? ' (' . htmlspecialchars($av['driver_phone']) . ')' : '' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <button type="submit" name="unassign_vehicle" value="1" 
                                                    onclick="return confirmUnassign(<?= (int)$av['id'] ?>, '<?= htmlspecialchars(addslashes($av['vehicle_number'])) ?>')" 
                                                    style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class='bx bx-unlink'></i> Unassign
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 12px; text-align: center; color: #64748b; font-size: 13px; margin-bottom: 16px;">
                                    No vehicle currently assigned to this zone. Assign or enter one below.
                                </div>
                            <?php endif; ?>

                            <!-- Section to add or assign another vehicle -->
                            <div style="border-top: 1px dashed #cbd5e1; padding-top: 12px;">
                        

                                <div class="form-group" style="margin-bottom: 1rem;">
                                    <label class="form-label" for="existing_vehicle_select">Select From Vehicless</label>
                                    <select name="existing_vehicle_id" id="existing_vehicle_select" class="form-control" onchange="handleExistingVehicleSelect(this)">
                                        <option value="">-- Or enter a new vehicle below --</option>
                                        <?php foreach ($otherVehicles as $ov): 
                                            $zInfo = !empty($ov['zone_name']) ? (' (Currently: ' . htmlspecialchars($ov['zone_name']) . ')') : ' (Unassigned)';
                                        ?>
                                            <option value="<?= $ov['id'] ?>" 
                                                data-plate="<?= htmlspecialchars($ov['vehicle_number']) ?>"
                                                data-name="<?= htmlspecialchars($ov['vehicle_name'] ?? '') ?>"
                                                data-driver="<?= htmlspecialchars($ov['driver_name'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($ov['driver_phone'] ?? '') ?>">
                                                <?= htmlspecialchars($ov['vehicle_number']) ?> <?= !empty($ov['vehicle_name']) ? ' &bull; ' . htmlspecialchars($ov['vehicle_name']) : '' ?><?= $zInfo ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div class="form-group" style="margin-bottom: 0.75rem;">
                                        <label class="form-label" for="vehicle_number">Vehicle Plate Number</label>
                                        <input type="text" name="vehicle_number" id="vehicle_number" class="form-control" placeholder="e.g. KA20MP5673" style="text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">
                                        <small style="color: #94a3b8; font-size: 11px;">Plate number will be fixed to this zone</small>
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0.75rem;">
                                        <label class="form-label" for="vehicle_name">Vehicle Name / Model</label>
                                        <input type="text" name="vehicle_name" id="vehicle_name" class="form-control" placeholder="e.g. Ashok Leyland, Tata Ace">
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 4px;">
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label class="form-label" for="driver_name">Driver Name</label>
                                        <input type="text" name="driver_name" id="driver_name" class="form-control" placeholder="e.g. Abbas">
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label class="form-label" for="driver_phone">Driver Phone</label>
                                        <input type="tel" name="driver_phone" id="driver_phone" class="form-control" placeholder="e.g. 9876543210">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Hidden input for unassign action -->
                        <input type="hidden" name="unassign_vehicle_id" id="unassign_vehicle_id" value="0">

                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="active" <?= (($zone['status']) === 'active') ? 'selected' : '' ?>>Active (Enabled)</option>
                                <option value="inactive" <?= (($zone['status']) === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="update_zone" class="btn-primary" style="padding: 0.65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                                Save Changes
                            </button>
                            <a href="index.php" style="padding: 0.65rem 1.25rem; background: #f1f5f9; color: #475569; text-decoration: none; border-radius: 8px; font-weight: 500; display: inline-flex; align-items: center;">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const baseUrl = <?= json_encode(BASE_URL) ?>;
        const slugInput = document.getElementById('slug');
        const previewUrl = document.getElementById('previewUrl');

        slugInput.addEventListener('input', function() {
            const slugVal = this.value.trim() || '...';
            previewUrl.innerHTML = `<strong>${baseUrl}/${slugVal}</strong>`;
        });

        function confirmUnassign(vehId, plate) {
            if (confirm('Are you sure you want to unassign vehicle ' + plate + ' from this zone?')) {
                document.getElementById('unassign_vehicle_id').value = vehId;
                return true;
            }
            return false;
        }

        function handleExistingVehicleSelect(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            if (opt && opt.value) {
                document.getElementById('vehicle_number').value = opt.getAttribute('data-plate') || '';
                document.getElementById('vehicle_name').value = opt.getAttribute('data-name') || '';
                document.getElementById('driver_name').value = opt.getAttribute('data-driver') || '';
                document.getElementById('driver_phone').value = opt.getAttribute('data-phone') || '';
            }
        }
    </script>
</body>
</html>

<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "zones";
$page_title = "Add Delivery Zone";
$error = '';

// Fetch existing vehicles for optional selection
$allVehicles = [];
try {
    $existingVehiclesStmt = $pdo->query("
        SELECT v.id, v.vehicle_number, v.vehicle_name, v.driver_name, v.driver_phone, v.zone_id, z.name as zone_name
        FROM vehicles v
        LEFT JOIN zones z ON v.zone_id = z.id
        WHERE v.status != 'inactive'
        ORDER BY v.vehicle_number ASC
    ");
    $allVehicles = $existingVehiclesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $allVehicles = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_zone'])) {
    $name = trim($_POST['name'] ?? '');
    $custom_slug = trim($_POST['slug'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    $access_code = trim($_POST['access_code'] ?? 'ZONE2026');
    if (empty($access_code)) $access_code = 'ZONE2026';

    if (empty($name)) {
        $error = "Zone name is required.";
    } else {
        // Sanitize slug
        $slug = !empty($custom_slug) ? sanitizeZoneSlug($custom_slug) : sanitizeZoneSlug($name);

        // Disallow reserved slugs
        $reserved = ['admin', 'api', 'assets', 'uploads', 'config', 'vendor', 'public', 'join', 'redeem', 'error-pages', 'zone', 'cart', 'orders', 'products'];
        if (in_array($slug, $reserved)) {
            $error = "The slug '{$slug}' is a reserved system route. Please choose another name or slug.";
        } else {
            // Check if slug exists
            $check = $pdo->prepare("SELECT id FROM zones WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) {
                $error = "A zone with portal route '/{$slug}' already exists. Please choose a different name.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO zones (name, slug, access_code, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $slug, $access_code, $status]);
                    $new_id = (int)$pdo->lastInsertId();

                    quickLog($pdo, 'create', 'zone', $new_id, "Created new zone: {$name} (/{$slug})");

                    // Fix / Assign vehicle to this newly created zone
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
                        $updVeh->execute([$new_id, $v_name, $d_name, $d_phone, $existing_veh_id]);
                        quickLog($pdo, 'update', 'vehicle', $existing_veh_id, "Fixed vehicle #{$existing_veh_id} to zone {$name} (#{$new_id})");
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
                            $updVeh->execute([$new_id, $v_name, $d_name, $d_phone, $matchedVehId]);
                            quickLog($pdo, 'update', 'vehicle', $matchedVehId, "Fixed existing vehicle {$v_number} to zone {$name} (#{$new_id})");
                        } else {
                            $vehRes = addVehicleRecord($pdo, [
                                'vehicle_number' => $v_number,
                                'vehicle_name'   => $v_name,
                                'driver_name'    => $d_name,
                                'driver_phone'   => $d_phone,
                                'zone_id'        => $new_id,
                                'capacity_cases' => 100,
                                'status'         => 'active'
                            ]);
                            if ($vehRes['success']) {
                                quickLog($pdo, 'create', 'vehicle', $vehRes['vehicle_id'], "Registered vehicle {$v_number} fixed to zone {$name} (#{$new_id})");
                            }
                        }
                    }

                    header("Location: index.php?added=1");
                    exit;
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Delivery Zone - Liyas Admin</title>
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
            padding: 0.65rem 0.85rem;
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
                    <span>Add Zone</span>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem; max-width: 650px;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 0.5rem; color: #111827;">Create New Delivery Zone</h2>
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 1.75rem;">
                        A dedicated delivery portal route will be generated dynamically for this zone.
                    </p>

                    <form action="create.php" method="POST" id="zoneForm">
                        <div class="form-group">
                            <label class="form-label" for="name">Zone Name <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Mangalore, Vitla, Bantwal" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus>
                            <small style="color: #94a3b8; font-size: 12px;">Example: "Mangalore Zone" or "Bantwal"</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slug">Portal Route Slug</label>
                            <input type="text" name="slug" id="slug" class="form-control" placeholder="Auto-generated (e.g. bantwal)" value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>">
                            <div class="route-preview">
                                <i class='bx bx-link'></i>
                                Portal URL: <span id="previewUrl"><strong><?= BASE_URL ?>/<span>...</span></strong></span>
                            </div>
                        </div>

                        <!-- VEHICLE ASSIGNMENT / REGISTRATION SECTION -->
                        <div class="vehicle-assign-section" style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-top: 1.5rem; margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 18px;">
                                        <i class='bx bxs-truck'></i>
                                    </span>
                                    <div>
                                        <strong style="color: #0f172a; font-size: 14px;">Assign Delivery Vehicle</strong>
                                        <div style="font-size: 12px; color: #64748b;">Fix a vehicle plate number to this delivery zone</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label class="form-label" for="existing_vehicle_select">Select From Existing Fleet Vehicles (Optional)</label>
                                <select name="existing_vehicle_id" id="existing_vehicle_select" class="form-control" onchange="handleExistingVehicleSelect(this)">
                                    <option value="">-- Or enter a new vehicle below --</option>
                                    <?php foreach ($allVehicles as $av): 
                                        $zoneText = !empty($av['zone_name']) ? (' (Assigned: ' . htmlspecialchars($av['zone_name']) . ')') : ' (Unassigned)';
                                    ?>
                                        <option value="<?= $av['id'] ?>" 
                                            data-plate="<?= htmlspecialchars($av['vehicle_number']) ?>"
                                            data-name="<?= htmlspecialchars($av['vehicle_name'] ?? '') ?>"
                                            data-driver="<?= htmlspecialchars($av['driver_name'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($av['driver_phone'] ?? '') ?>"
                                            <?= ((int)($_POST['existing_vehicle_id'] ?? 0) === (int)$av['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($av['vehicle_number']) ?> <?= !empty($av['vehicle_name']) ? ' &bull; ' . htmlspecialchars($av['vehicle_name']) : '' ?><?= $zoneText ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" for="vehicle_number">Vehicle Plate Number</label>
                                    <input type="text" name="vehicle_number" id="vehicle_number" class="form-control" placeholder="e.g. KA20MP5673" style="text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;" value="<?= htmlspecialchars($_POST['vehicle_number'] ?? '') ?>">
                                    <small style="color: #94a3b8; font-size: 11px;">Plate number will be fixed to this zone</small>
                                </div>

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" for="vehicle_name">Vehicle Name / Model</label>
                                    <input type="text" name="vehicle_name" id="vehicle_name" class="form-control" placeholder="e.g. Ashok Leyland, Tata Ace" value="<?= htmlspecialchars($_POST['vehicle_name'] ?? '') ?>">
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 4px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" for="driver_name">Driver Name</label>
                                    <input type="text" name="driver_name" id="driver_name" class="form-control" placeholder="e.g. Abbas" value="<?= htmlspecialchars($_POST['driver_name'] ?? '') ?>">
                                </div>

                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" for="driver_phone">Driver Phone</label>
                                    <input type="tel" name="driver_phone" id="driver_phone" class="form-control" placeholder="e.g. 9876543210" value="<?= htmlspecialchars($_POST['driver_phone'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="active" <?= (($_POST['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active (Enabled)</option>
                                <option value="inactive" <?= (($_POST['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive (Disabled)</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="save_zone" class="btn-primary" style="padding: 0.65rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                                Create Zone
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
        const nameInput = document.getElementById('name');
        const slugInput = document.getElementById('slug');
        const previewUrl = document.getElementById('previewUrl');

        function generateSlug(text) {
            // Strip 'zone' word if present
            let cleaned = text.replace(/\bzone\b/gi, '').trim();
            if (!cleaned) cleaned = text;
            return cleaned.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        function updatePreview() {
            const slugVal = slugInput.value.trim() || generateSlug(nameInput.value.trim()) || '...';
            previewUrl.innerHTML = `<strong>${baseUrl}/${slugVal}</strong>`;
        }

        nameInput.addEventListener('input', function() {
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
            updatePreview();
        });

        slugInput.addEventListener('input', function() {
            this.dataset.manual = "true";
            updatePreview();
        });

        function handleExistingVehicleSelect(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            if (opt && opt.value) {
                document.getElementById('vehicle_number').value = opt.getAttribute('data-plate') || '';
                document.getElementById('vehicle_name').value = opt.getAttribute('data-name') || '';
                document.getElementById('driver_name').value = opt.getAttribute('data-driver') || '';
                document.getElementById('driver_phone').value = opt.getAttribute('data-phone') || '';
            }
        }

        updatePreview();
    </script>
</body>
</html>

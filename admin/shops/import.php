<?php
/**
 * admin/shops/import.php
 * Smart CSV & Excel Shop Importer with Route Auto-Detection & Preview Table
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

date_default_timezone_set('Asia/Kolkata');
@ini_set('max_input_vars', '50000');
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '256M');

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "shops";
$page_title = "Import Shops & Assign Routes";

// Fetch active vehicles with zones
$vehiclesList = [];
try {
    $vStmt = $pdo->query("
        SELECT v.id as vehicle_id, v.vehicle_number, v.vehicle_name, v.driver_name, v.zone_id, z.name as zone_name 
        FROM vehicles v 
        LEFT JOIN zones z ON v.zone_id = z.id 
        WHERE v.status != 'inactive' 
        ORDER BY v.vehicle_name ASC, v.vehicle_number ASC
    ");
    $vehiclesList = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Download Sample CSV Handler
if (isset($_GET['action']) && $_GET['action'] === 'sample_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sample_shops_import.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Shop Name', 'Location', 'Phone', 'Assigned Vehicle']);
    fputcsv($output, ['Madeena Super Bazar', 'Yenepoya', '9876543210', 'Ace']);
    fputcsv($output, ['Thokkottu Sweets', 'Thokkotu', '', 'Ace']);
    fputcsv($output, ['Al-Rayan Bakery', 'Mudipu', '9845123456', 'Intra']);
    fputcsv($output, ['City Fresh Supermart', 'B C Road', '', 'Pickup']);
    fclose($output);
    exit;
}

// Smart Route Detector function based on location text
function detectVehicleAndZone(string $location, string $shopName, array $vehiclesList, ?int $forceVehicleId = null): array {
    if ($forceVehicleId && $forceVehicleId > 0) {
        foreach ($vehiclesList as $v) {
            if ((int)$v['vehicle_id'] === $forceVehicleId) {
                return ['vehicle_id' => (int)$v['vehicle_id'], 'zone_id' => !empty($v['zone_id']) ? (int)$v['zone_id'] : null, 'match_reason' => 'Manually Assigned'];
            }
        }
    }

    $combinedText = strtolower($location . ' ' . $shopName);

    // Keywords mapping
    // Zone 21 / Ace
    $aceKeywords = [
        'kanachur', 'yenepoya', 'deralakatte', 'derlakatte', 'konaje', 'thokkotu', 'thokkottu', 
        'tokkottu', 'ullal', 'kc road', 'k.c road', 'k.c. road', 'someshwara', 'kotekar', 
        'natekal', 'kumpala', 'beeri', 'manjanady', 'kallapu', 'jeppinamogaru', 'mangalore uni', 'hakeem'
    ];

    // Zone 22 / Intra
    $intraKeywords = [
        'mudipu', 'vitla', 'vittal', 'salethur', 'uppinangady', 'kambalapadpu', 'kurnad', 
        'boliyar', 'punacha', 'kanyana', 'kolnad', 'mani', 'kabaka', 'puttur', 'peruvai', 
        'bayar', 'kanyana', 'pajir', 'tiroor', 'peraje'
    ];

    // Zone 20 / Pickup
    $pickupKeywords = [
        'bc road', 'b.c road', 'b c road', 'bantwal', 'adyar', 'farangipete', 'melkar', 
        'mangalore', 'bunder', 'kasaba bengere', 'bengre', 'bajpe', 'surathkal', 'kavoor', 
        'kadri', 'car street', 'state bank', 'pumpwell', 'kankanady', 'kankanadi', 'falnir'
    ];

    // Find Ace vehicle ID
    $aceVehicle = null;
    $intraVehicle = null;
    $pickupVehicle = null;
    foreach ($vehiclesList as $v) {
        $vName = strtolower($v['vehicle_name']);
        if (strpos($vName, 'ace') !== false) $aceVehicle = $v;
        elseif (strpos($vName, 'intra') !== false) $intraVehicle = $v;
        elseif (strpos($vName, 'pickup') !== false) $pickupVehicle = $v;
    }

    foreach ($aceKeywords as $kw) {
        if (strpos($combinedText, $kw) !== false && $aceVehicle) {
            return ['vehicle_id' => (int)$aceVehicle['vehicle_id'], 'zone_id' => (int)$aceVehicle['zone_id'], 'match_reason' => "Matched '{$kw}' (Ace Route)"];
        }
    }

    foreach ($intraKeywords as $kw) {
        if (strpos($combinedText, $kw) !== false && $intraVehicle) {
            return ['vehicle_id' => (int)$intraVehicle['vehicle_id'], 'zone_id' => (int)$intraVehicle['zone_id'], 'match_reason' => "Matched '{$kw}' (Intra Route)"];
        }
    }

    foreach ($pickupKeywords as $kw) {
        if (strpos($combinedText, $kw) !== false && $pickupVehicle) {
            return ['vehicle_id' => (int)$pickupVehicle['vehicle_id'], 'zone_id' => (int)$pickupVehicle['zone_id'], 'match_reason' => "Matched '{$kw}' (Pickup Route)"];
        }
    }

    return ['vehicle_id' => null, 'zone_id' => null, 'match_reason' => 'Unknown location'];
}

// Helper to detect if a column contains Date, Month name, or Serial number
function isDateOrMonthOrSerial($val): bool {
    $v = strtolower(trim($val));
    if (empty($v)) return false;
    $months = [
        'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
        'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december'
    ];
    foreach ($months as $m) {
        if ($v === $m || strpos($v, $m) !== false) {
            return true;
        }
    }
    // Serial number (e.g. 1, 2, 3... 9999)
    if (preg_match('/^\d{1,4}$/', $v)) {
        return true;
    }
    // Standard dates (e.g. 2024-01-01, 01/02/2024, 01-02-24, 01.02.2024)
    if (preg_match('/^\d{1,4}[-\/\.]\d{1,2}([-\/\.]\d{1,4})?$/', $v)) {
        return true;
    }
    // Parseable date string
    if (strtotime($v) !== false && !is_numeric($v)) {
        return true;
    }
    return false;
}

// Fetch existing shop names from DB for duplicate checking
$existingShopsMap = [];
try {
    $eStmt = $pdo->query("SELECT LOWER(TRIM(shop_name)) as s_name, location FROM shops");
    while ($r = $eStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingShopsMap[$r['s_name']] = $r['location'];
    }
} catch (Exception $e) {}

$previewRows = [];
$step = 1; // 1 = Upload Form, 2 = Preview Screen
$errorMessage = '';
$successMessage = '';

// --- STEP 3: FINAL IMPORT EXECUTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_import') {
    $items = [];
    if (!empty($_POST['payload_json'])) {
        $decoded = json_decode($_POST['payload_json'], true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }
    if (empty($items) && isset($_POST['items']) && is_array($_POST['items'])) {
        $items = $_POST['items'];
    }

    $clearExisting = !empty($_POST['clear_existing']);
    if ($clearExisting) {
        try {
            $pdo->exec("TRUNCATE TABLE shops");
        } catch (Exception $e) {}
    }

    $importedCount = 0;
    $skippedCount = 0;

    if (!empty($items) && is_array($items)) {
        $insStmt = $pdo->prepare("
            INSERT INTO shops (shop_name, location, phone, zone_id, vehicle_id) 
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            if (empty($item['include'])) {
                $skippedCount++;
                continue;
            }

            $sName = trim($item['shop_name'] ?? '');
            $sLoc = trim($item['location'] ?? '');
            $sPhone = trim($item['phone'] ?? '');
            $vId = !empty($item['vehicle_id']) ? (int)$item['vehicle_id'] : null;

            if (empty($sName)) {
                $skippedCount++;
                continue;
            }

            // Derive zone from vehicle
            $zId = null;
            if ($vId) {
                foreach ($vehiclesList as $v) {
                    if ((int)$v['vehicle_id'] === $vId) {
                        $zId = !empty($v['zone_id']) ? (int)$v['zone_id'] : null;
                        break;
                    }
                }
            }

            try {
                $insStmt->execute([$sName, $sLoc, $sPhone, $zId, $vId]);
                $importedCount++;
            } catch (Exception $e) {
                $skippedCount++;
            }
        }

        quickLog($pdo, 'import', 'shop', 0, "Bulk imported {$importedCount} shops from CSV/Excel.");
        $_SESSION['success_message'] = "Successfully imported {$importedCount} shops! " . ($skippedCount > 0 ? "({$skippedCount} skipped)" : "");
        header("Location: index.php");
        exit;
    } else {
        $errorMessage = "No shops were selected for import.";
    }
}

// --- STEP 2: PARSE FILE OR TEXT FOR PREVIEW ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'parse_file') {
    $forceVehicle = !empty($_POST['force_vehicle_id']) ? (int)$_POST['force_vehicle_id'] : null;
    $rawText = trim($_POST['pasted_text'] ?? '');
    $lines = [];

    // Check if file uploaded
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $filePath = $_FILES['csv_file']['tmp_name'];
        $content = file_get_contents($filePath);
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
    } elseif (!empty($rawText)) {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $lines = explode("\n", $rawText);
    }

    if (empty($lines)) {
        $errorMessage = "Please upload a CSV file or paste shop text.";
    } else {
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Detect separator (tab, comma, or semicolon)
            if (strpos($line, "\t") !== false) {
                $cols = explode("\t", $line);
            } elseif (strpos($line, ",") !== false) {
                $cols = str_getcsv($line);
            } else {
                $cols = preg_split('/\s{2,}/', $line); // 2 or more spaces
            }

            $cols = array_map('trim', $cols);
            if (empty($cols) || count($cols) < 2) continue;

            // Skip header rows (e.g. DATE | SHOP NAME | LOCATION)
            $col0Lower = strtolower($cols[0]);
            $col1Lower = strtolower($cols[1] ?? '');
            $col2Lower = strtolower($cols[2] ?? '');

            if (in_array($col0Lower, ['date', 'month', 'sl no', 'sl.no', '#', 's.no', 'sr no', 'sno', 'page']) && 
                in_array($col1Lower, ['shop name', 'shop', 'customer name', 'customer', 'name', 'shops'])) {
                continue;
            }
            if ($col0Lower === 'shop name' || $col0Lower === 'customer name' || $col1Lower === 'shop name' || $col1Lower === 'customer name') {
                continue;
            }

            // Detect column layout:
            // Layout 1: DATE/MONTH | SHOP NAME | LOCATION (e.g. "Jun | Ana sagar kitchen | Kanachur")
            // Layout 2: SHOP NAME | LOCATION | PHONE
            // Layout 3: SHOP NAME | LOCATION
            $shopName = '';
            $location = '';
            $phone = '';

            if (count($cols) >= 3) {
                // If 1st column is a Date, Month, or Serial number
                if (isDateOrMonthOrSerial($cols[0])) {
                    $shopName = $cols[1];
                    $location = $cols[2];
                    $phone    = $cols[3] ?? '';
                } elseif (preg_match('/^[0-9+\s\-]{7,15}$/', $cols[2])) {
                    // Column 2 is phone number: SHOP | LOCATION | PHONE
                    $shopName = $cols[0];
                    $location = $cols[1];
                    $phone    = $cols[2];
                } else {
                    // 3 columns without phone: Col 0 is Date/Month/S.No, Col 1 is Shop, Col 2 is Location
                    $shopName = $cols[1];
                    $location = $cols[2];
                    $phone    = $cols[3] ?? '';
                }
            } else {
                // 2 columns: SHOP NAME | LOCATION
                $shopName = $cols[0];
                $location = $cols[1] ?? '';
                $phone    = '';
            }

            // Cleanup text
            $shopName = preg_replace('/^\d+[\.\)\-]\s*/', '', $shopName);
            $shopName = trim($shopName, " \t\n\r\0\x0B\"'");
            $location = trim($location, " \t\n\r\0\x0B\"'");
            $phone    = trim($phone, " \t\n\r\0\x0B\"'");

            if (empty($shopName)) continue;

            // Route detection
            $detection = detectVehicleAndZone($location, $shopName, $vehiclesList, $forceVehicle);

            // Check duplicates
            $isDuplicate = isset($existingShopsMap[strtolower($shopName)]);

            $previewRows[] = [
                'shop_name' => $shopName,
                'location' => $location,
                'phone' => $phone,
                'vehicle_id' => $detection['vehicle_id'],
                'zone_id' => $detection['zone_id'],
                'match_reason' => $detection['match_reason'],
                'is_duplicate' => $isDuplicate,
                'include' => !$isDuplicate
            ];
        }

        if (!empty($previewRows)) {
            $step = 2; // Move to preview screen
        } else {
            $errorMessage = "Could not parse any valid shop records from your input. Please check the file format.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Admin Panel</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        .import-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .hero-banner {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .hero-banner h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hero-banner p {
            font-size: 13px;
            color: #64748b;
            margin: 4px 0 0 0;
        }

        .step-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            background: #f1f5f9;
            color: #64748b;
        }
        .step-pill.active {
            background: #2563eb;
            color: #ffffff;
        }
        .step-pill .step-circle {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }

        .upload-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }

        .dropzone-box {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 36px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .dropzone-box:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }
        .dropzone-box input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .btn-submit {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s ease;
        }
        .btn-submit:hover { background: #1d4ed8; }

        .btn-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; }

        /* Preview Table */
        .preview-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }
        .preview-header {
            padding: 16px 22px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .preview-table th {
            background: #f1f5f9;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        .preview-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .preview-table tr.row-dup {
            background: #fffdf5;
        }
        .preview-table tr:hover td {
            background: #f8fafc;
        }

        .inp-clean {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            box-sizing: border-box;
            background: #ffffff;
        }
        .inp-clean:focus {
            border-color: #2563eb;
            outline: none;
        }

        .badge-reason {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 4px;
        }
        .badge-reason-ace { background: #eff6ff; color: #1d4ed8; }
        .badge-reason-intra { background: #faf5ff; color: #7e22ce; }
        .badge-reason-pickup { background: #ecfdf5; color: #047857; }
        .badge-reason-unknown { background: #fef3c7; color: #92400e; }
        .badge-dup { background: #fee2e2; color: #991b1b; font-weight: 700; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <a href="../dashboard/index.php" style="color: inherit; text-decoration: none;">Admin</a>
                    <i class='bx bx-chevron-right'></i>
                    <a href="index.php" style="color: inherit; text-decoration: none;">Shops</a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Smart CSV Importer</span>
                </div>
            </div>

            <div class="content-area">
                <div class="import-container">
                    
                    <!-- Top Banner -->
                    <div class="hero-banner">
                        <div>
                            <h1><i class='bx bx-cloud-upload' style="color: #2563eb;"></i> Import Shops & Assign Routes</h1>
                            <p>Upload your shop list. The system auto-detects vehicles (Ace, Intra, Pickup) based on locations and allows instant preview.</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <!-- <a href="import.php?action=sample_csv" class="btn-secondary" title="Download formatted template">
                                <i class='bx bx-download'></i> Download Sample CSV
                            </a> -->
                            <a href="index.php" class="btn-secondary">
                                <i class='bx bx-arrow-back'></i> Back to Directory
                            </a>
                        </div>
                    </div>

                    <!-- Step Tracker -->
                    <div class="step-pills">
                        <div class="step-pill <?= $step === 1 ? 'active' : '' ?>">
                            <span class="step-circle">1</span> Upload CSV or Paste Text
                        </div>
                        <i class='bx bx-chevron-right' style="color: #94a3b8; font-size: 18px;"></i>
                        <div class="step-pill <?= $step === 2 ? 'active' : '' ?>">
                            <span class="step-circle">2</span> Auto-Detect & Preview
                        </div>
                        <i class='bx bx-chevron-right' style="color: #94a3b8; font-size: 18px;"></i>
                        <div class="step-pill">
                            <span class="step-circle">3</span> Confirmed & Ready
                        </div>
                    </div>

                    <?php if (!empty($errorMessage)): ?>
                        <div style="background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600;">
                            <i class='bx bxs-error-circle' style="vertical-align: middle;"></i> <?= htmlspecialchars($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($step === 1): ?>
                        <!-- STEP 1: UPLOAD FORM -->
                        <form method="POST" action="import.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="parse_file">

                            <div class="upload-card">
                                <h3 style="margin-top: 0; font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 6px;">
                                    Option 1: Upload a File (.csv, .txt, .xlsx)
                                </h3>
                                <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                                    Your file should contain at least two columns: <strong>Shop Name</strong> and <strong>Location</strong> (or Date, Shop Name, Location).
                                </p>

                                <div class="dropzone-box" id="dropzone">
                                    <input type="file" name="csv_file" id="csvFileInput" accept=".csv, .txt" onchange="updateFileName(this)">
                                    <i class='bx bxs-file-import' style="font-size: 44px; color: #2563eb; margin-bottom: 10px;"></i>
                                    <div style="font-size: 14px; font-weight: 700; color: #1e293b;" id="fileNameDisplay">
                                        Click or drag your CSV file here
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                        Supports .csv, .txt (Comma or Tab separated)
                                    </div>
                                </div>

                                <!-- <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                                    <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 6px;">
                                        Option 2: Or Paste Text Directly
                                    </h3>
                                    <p style="font-size: 12px; color: #64748b; margin-bottom: 10px;">
                                        Copy and paste directly from Excel or a notepad table:
                                    </p>
                                    <textarea name="pasted_text" rows="5" class="inp-clean" placeholder="Example:
Madeena Super Bazar	Yenepoya
Thokkottu Sweets	Thokkotu
Al-Rayan Bakery	Mudipu
City Mart	B C Road"></textarea>
                                </div> -->

                                <!-- <div style="margin-top: 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px;">
                                    <label style="display: block; font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 6px;">
                                        Territory / Route Assignment Mode:
                                    </label>
                                    <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                                        <label style="font-size: 13px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                            <input type="radio" name="route_mode" value="auto" checked onclick="toggleForceSelect(false)">
                                            <strong> Auto-Detect by Location</strong> (Recommended)
                                        </label>
                                   
                                        <select name="force_vehicle_id" id="forceVehicleSelect" class="inp-clean" style="width: auto; display: none;" disabled>
                                            <?php foreach ($vehiclesList as $v): ?>
                                                <option value="<?= $v['vehicle_id'] ?>">
                                                    <?= htmlspecialchars($v['vehicle_name']) ?> (<?= htmlspecialchars($v['vehicle_number']) ?>) - <?= htmlspecialchars($v['zone_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div> -->

                                <div style="margin-top: 24px; display: flex; justify-content: flex-end;">
                                    <button type="submit" class="btn-submit">
                                        Continue to Preview <i class='bx bx-right-arrow-alt'></i>
                                    </button>
                                </div>
                            </div>
                        </form>

                    <?php elseif ($step === 2): ?>
                        <!-- STEP 2: INTERACTIVE PREVIEW & REASSIGN -->
                        <form method="POST" action="import.php" id="importConfirmForm">
                            <input type="hidden" name="action" value="confirm_import">
                            <input type="hidden" name="payload_json" id="payloadJson">

                            <div class="preview-box">
                                <div class="preview-header">
                                    <div>
                                        <strong style="font-size: 15px; color: #0f172a;">Review <?= count($previewRows) ?> Extracted Shops</strong>
                                        <div style="font-size: 12px; color: #64748b;">
                                            Verify or modify the detected vehicles before saving into the database.
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                   
                                        <button type="button" class="btn-secondary" onclick="toggleAllCheckboxes(true)">Select All</button>
                                        <button type="button" class="btn-secondary" onclick="toggleAllCheckboxes(false)">Deselect All</button>
                                        <button type="submit" class="btn-submit" id="btnConfirmSave">
                                            <i class='bx bx-check-double'></i> Confirm & Save All (<span id="selectedCount"><?= count($previewRows) ?></span>)
                                        </button>
                                    </div>
                                </div>

                                <div style="overflow-x: auto;">
                                    <table class="preview-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px; text-align: center;">
                                                    <input type="checkbox" id="masterCheckbox" checked onchange="toggleAllCheckboxes(this.checked)">
                                                </th>
                                                <th style="width: 40px;">#</th>
                                                <th style="min-width: 220px;">Shop Name</th>
                                                <th style="min-width: 160px;">Location / Area</th>
                                                <th style="min-width: 110px;">Phone</th>
                                                <th style="min-width: 250px;">Assigned Vehicle & Route</th>
                                                <th>Match Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($previewRows as $i => $row): 
                                                $reasonClass = 'badge-reason-unknown';
                                                if (stripos($row['match_reason'], 'ace') !== false) {
                                                    $reasonClass = 'badge-reason-ace';
                                                } elseif (stripos($row['match_reason'], 'intra') !== false) {
                                                    $reasonClass = 'badge-reason-intra';
                                                } elseif (stripos($row['match_reason'], 'pickup') !== false) {
                                                    $reasonClass = 'badge-reason-pickup';
                                                }
                                            ?>
                                                <tr class="<?= $row['is_duplicate'] ? 'row-dup' : '' ?>">
                                                    <td style="text-align: center;">
                                                        <input type="checkbox" value="1" <?= $row['include'] ? 'checked' : '' ?> class="row-chk" onchange="updateSelectedCount()">
                                                    </td>
                                                    <td style="color: #94a3b8; font-weight: 600;"><?= $i + 1 ?></td>
                                                    <td>
                                                        <input type="text" value="<?= htmlspecialchars($row['shop_name']) ?>" class="inp-clean inp-shop-name" required>
                                                        <?php if ($row['is_duplicate']): ?>
                                                            <div style="margin-top: 3px;">
                                                                <span class="badge-dup"><i class='bx bx-info-circle'></i> Already in Database</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <input type="text" value="<?= htmlspecialchars($row['location']) ?>" class="inp-clean inp-location">
                                                    </td>
                                                    <td>
                                                        <input type="text" value="<?= htmlspecialchars($row['phone']) ?>" class="inp-clean inp-phone" placeholder="Optional">
                                                    </td>
                                                    <td>
                                                        <select class="inp-clean sel-vehicle">
                                                            <option value="">-- Unassigned --</option>
                                                            <?php foreach ($vehiclesList as $v): ?>
                                                                <option value="<?= $v['vehicle_id'] ?>" <?= $row['vehicle_id'] == $v['vehicle_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($v['vehicle_name']) ?> (<?= htmlspecialchars($v['vehicle_number']) ?>) - <?= htmlspecialchars($v['zone_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <span class="badge-reason <?= $reasonClass ?>">
                                                            <?= htmlspecialchars($row['match_reason']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div style="padding: 18px 22px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                                    <a href="import.php" class="btn-secondary">
                                        <i class='bx bx-chevron-left'></i> Start Over
                                    </a>
                                    <button type="submit" class="btn-submit">
                                        <i class='bx bx-check-double'></i> Confirm & Save All
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script>
        function updateFileName(input) {
            const display = document.getElementById('fileNameDisplay');
            if (input.files && input.files[0]) {
                display.innerHTML = `<span style="color: #2563eb; font-weight: 800;">📄 ${input.files[0].name}</span> (${(input.files[0].size / 1024).toFixed(1)} KB)`;
            }
        }
        function toggleForceSelect(show) {
            const select = document.getElementById('forceVehicleSelect');
            if (select) {
                select.style.display = show ? 'inline-block' : 'none';
                select.disabled = !show;
            }
        }
        function toggleAllCheckboxes(checked) {
            document.querySelectorAll('.row-chk').forEach(chk => {
                chk.checked = checked;
            });
            const master = document.getElementById('masterCheckbox');
            if (master) master.checked = checked;
            updateSelectedCount();
        }
        function updateSelectedCount() {
            let count = 0;
            document.querySelectorAll('.row-chk').forEach(chk => { if (chk.checked) count++; });
            const disp = document.getElementById('selectedCount');
            if (disp) disp.textContent = count;
        }

        // Gather all rows into single JSON payload to effortlessly bypass PHP max_input_vars limit
        document.getElementById('importConfirmForm')?.addEventListener('submit', function(e) {
            const rows = [];
            document.querySelectorAll('.preview-table tbody tr').forEach(tr => {
                const chk = tr.querySelector('.row-chk');
                if (chk && chk.checked) {
                    const sName = (tr.querySelector('.inp-shop-name')?.value || '').trim();
                    const sLoc = (tr.querySelector('.inp-location')?.value || '').trim();
                    const sPhone = (tr.querySelector('.inp-phone')?.value || '').trim();
                    const vId = tr.querySelector('.sel-vehicle')?.value || '';
                    if (sName) {
                        rows.push({
                            include: 1,
                            shop_name: sName,
                            location: sLoc,
                            phone: sPhone,
                            vehicle_id: vId
                        });
                    }
                }
            });

            if (rows.length === 0) {
                e.preventDefault();
                alert('Please select at least one shop to import.');
                return false;
            }

            document.getElementById('payloadJson').value = JSON.stringify(rows);
            const saveBtn = document.getElementById('btnConfirmSave');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving " + rows.length + " shops...";
            }
        });
    </script>
</body>
</html>

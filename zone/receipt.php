<?php
require_once __DIR__ . '/zone_context.php';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("
    SELECT 
        o.*,
        r.id as receipt_id,
        r.bill_number,
        r.delivered_quantity,
        r.total_amount as receipt_total,
        r.cash_amount as receipt_cash,
        r.credited_amount as receipt_credit,
        r.due_amount as receipt_due,
        r.payment_type as receipt_pay_type,
        r.notes as receipt_notes,
        r.created_at as receipt_created_at,
        p.product_name,
        p.name as fallback_product_name,
        p.case_price,
        p.price,
        p.net_content,
        p.net_content_unit,
        z.name as zone_name
    FROM orders o
    JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN zones z ON o.zone_id = z.id
    WHERE o.order_id = ?
");
$stmt->execute([$order_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    http_response_code(404);
    echo renderZoneNotFound("Receipt for this order has not been generated yet.");
    exit;
}

$remainingShopDues = getShopPendingDueDetails($pdo, $data['shop_name'] ?: ($data['customer_name'] ?? ''), $data['phone'] ?? '', $order_id);

// Company Details
$gstin   = getSystemSetting($pdo, 'company_gstin', '29ABCDE1234F1Z5');
$phone   = getSystemSetting($pdo, 'company_phone', '+91 63663 78967');
$website = getSystemSetting($pdo, 'company_website', 'liyasinternational.com');

$billNumber = $data['bill_number'];
$receiptDate = date('d-m-Y', strtotime($data['receipt_created_at'] ?: 'now'));
$receiptTime = date('h:i A', strtotime($data['receipt_created_at'] ?: 'now'));

$shopName    = htmlspecialchars($data['shop_name'] ?: ($data['customer_name'] ?: 'Valued Customer'));
$place       = htmlspecialchars($data['location'] ?: 'Mangalore');
$customerTel = htmlspecialchars($data['phone'] ?: '');
$contactName = htmlspecialchars($data['customer_name'] ?: '');

$prodName = htmlspecialchars($data['product_name'] ?: ($data['fallback_product_name'] ?: 'Liyas Water'));
$deliveredQty = (int)$data['delivered_quantity'];
$rate = (float)($data['unit_price'] ?: ($data['case_price'] ?: $data['price']));
$itemAmount = $deliveredQty * $rate;
$totalPayable = (float)$data['receipt_total'];
$cashReceived = (float)$data['receipt_cash'];
$creditAmount = (float)$data['receipt_credit'];
$dueAmount = (float)$data['receipt_due'];
$payType = htmlspecialchars($data['receipt_pay_type'] ?: 'Cash');

// Fetch all order items
$all_order_items = getOrderItems($pdo, $order_id);

// Fetch all delivered & billed order items
$order_items = [];
foreach ($all_order_items as $oi) {
    if ((int)$oi['quantity'] > 0) {
        $order_items[] = $oi;
    }
}
if (empty($order_items)) {
    $order_items = $all_order_items;
}

// Fallback for legacy single-item orders where order_items table was empty
if (empty($order_items)) {
    $order_items = [[
        'order_item_id' => 0,
        'order_id' => $order_id,
        'product_id' => (int)($data['product_id'] ?? 0),
        'quantity' => $deliveredQty,
        'price_at_purchase' => $rate,
        'line_total' => $itemAmount,
        'product_name' => $prodName,
        'name' => $prodName,
        'net_content' => $data['net_content'] ?? null,
        'net_content_unit' => $data['net_content_unit'] ?? null,
        'is_extra' => 0
    ]];
}

$totalDeliveredCases = 0;
$receiptSubtotal = 0.0;
$itemsWaLines = [];

foreach ($order_items as $oi) {
    $iName = $oi['product_name'] ?: ($oi['name'] ?: 'Liyas Water');
    $iQty = (int)$oi['quantity'];
    $totalDeliveredCases += $iQty;
    $receiptSubtotal += (float)$oi['line_total'];
    $extraTag = !empty($oi['is_extra']) ? ' (Vehicle Extra)' : '';
    $itemsWaLines[] = "• *{$iName}* ({$iQty} Cases{$extraTag})";
}

if ($totalDeliveredCases <= 0) {
    $totalDeliveredCases = $deliveredQty;
}
if ($receiptSubtotal <= 0) {
    $receiptSubtotal = $itemAmount ?: ($totalPayable + (float)$data['discount']);
}

$deliveredSummaryWa = (count($itemsWaLines) > 1) 
    ? "Delivered Items:\n" . implode("\n", $itemsWaLines) 
    : "Delivered: *" . ($order_items[0]['product_name'] ?? $prodName) . "* ({$totalDeliveredCases} Cases)";

// Build WhatsApp text
$cleanPhone = preg_replace('/[^0-9]/', '', $customerTel);
if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;
$receiptUrl = zone_url($zone_slug, 'receipt', ['id' => $order_id]);
$waMsg = "🧾 *LIYAS INTERNATIONAL - DELIVERY RECEIPT*\n"
       . "━━━━━━━━━━━━━━━━━━━━\n"
       . "Bill No: *{$billNumber}*\n"
       . "Date: {$receiptDate}\n"
       . "Customer: *{$shopName}*\n"
       . "{$deliveredSummaryWa}\n"
       . "Total Amount: *" . formatCurrency($totalPayable) . "*\n"
       . "Cash Paid: *" . formatCurrency($cashReceived) . "*\n"
       . ($dueAmount > 0 ? "⚠️ Balance Due: *" . formatCurrency($dueAmount) . "*\n" : "✅ Status: *Fully Paid*\n")
       . "━━━━━━━━━━━━━━━━━━━━\n"
       . "📄 View / Download Receipt: {$receiptUrl}\n"
       . "Thank you for choosing Liyas Water!";
$waShareUrl = !empty($cleanPhone) ? "https://wa.me/{$cleanPhone}?text=" . rawurlencode($waMsg) : "https://api.whatsapp.com/send?text=" . rawurlencode($waMsg);

// Build RawBT ESC/POS formatted text matching thermal printer layout (< 1 second)
if (!function_exists('buildThermalRawBtPayload')) {
    /**
     * Helper to generate structured RawBT ESC/POS text matching the official thermal receipt layout
     * Strictly calibrated for 30 columns (58mm Posiflow SC588) and 42 columns (80mm)
     */
    function buildThermalRawBtPayload($data, $order_items, $remainingShopDues, $gstin, $phone, $website, $width = 30) {
        $lines = [];
        $dividerDashed = str_repeat('-', $width);
        $dividerDouble = str_repeat('=', $width);
        $dividerDotted = substr(str_repeat('- ', (int)ceil($width / 2)), 0, $width);

        $center = function($text) use ($width) {
            $len = strlen($text);
            if ($len >= $width) return $text;
            return str_repeat(' ', (int)floor(($width - $len) / 2)) . $text;
        };

        // 1. Header (Brand Title, Subtitle, GSTIN)
        $lines[] = $center("LIYAS INTERNATIONAL");
        $lines[] = $center("Packaged Drinking Water");
        $lines[] = $center("GSTIN: " . $gstin);
        $lines[] = $dividerDashed;

        // 2. Bill Meta (#BillNumber, Date • Time)
        $billNumber = $data['bill_number'];
        $receiptDate = date('d-m-Y', strtotime($data['receipt_created_at'] ?: 'now'));
        $receiptTime = date('h:i A', strtotime($data['receipt_created_at'] ?: 'now'));

        $lines[] = $center("#" . $billNumber);
        $lines[] = $center($receiptDate . " / " . $receiptTime);
        $lines[] = $dividerDashed;

        // 3. Customer Info (Billed To, Attn, Delivery Location, Phone)
        $shopName    = $data['shop_name'] ?: ($data['customer_name'] ?: 'Valued Customer');
        $place       = $data['location'] ?: 'Mangalore';
        $customerTel = $data['phone'] ?: '';
        $contactName = $data['customer_name'] ?: '';

        $lines[] = "Billed To: " . $shopName;
        if (!empty($contactName) && $contactName !== $shopName) {
            $lines[] = "Attn: " . $contactName;
        }
        $lines[] = "Delivery Location: " . $place;
        $lines[] = "Phone: " . ($customerTel ?: 'N/A');
        $lines[] = $dividerDashed;

        // 4. Products Table (Item, Qty, Rate, Amt)
        if ($width == 30) {
            $wItem = 11;
            $wQty = 4;
            $wRate = 7;
            $wAmt = 8;
            $wLabel = 18;
            $wVal = 12;
        } else {
            $wItem = 18;
            $wQty = 6;
            $wRate = 9;
            $wAmt = 9;
            $wLabel = 26;
            $wVal = 16;
        }

        $tableHeader = str_pad("ITEM", $wItem) 
                     . str_pad("QTY", $wQty, " ", STR_PAD_LEFT) 
                     . str_pad("RATE", $wRate, " ", STR_PAD_LEFT) 
                     . str_pad("AMT", $wAmt, " ", STR_PAD_LEFT);
        $lines[] = $tableHeader;
        $lines[] = $dividerDashed;

        $itemCount = count($order_items);
        foreach ($order_items as $idx => $oi) {
            $iName = trim($oi['product_name'] ?: ($oi['name'] ?: 'Liyas Water'));
            $iQty = (int)$oi['quantity'];
            $iRate = (float)$oi['price_at_purchase'];
            $iAmt = (float)$oi['line_total'];

            $rateStr = (fmod($iRate, 1.0) == 0.0) ? number_format($iRate, 0) : number_format($iRate, 2);
            $amtStr  = (fmod($iAmt, 1.0) == 0.0) ? number_format($iAmt, 0) : number_format($iAmt, 2);

            $colQty  = str_pad((string)$iQty, $wQty, " ", STR_PAD_LEFT);
            $colRate = str_pad($rateStr, $wRate, " ", STR_PAD_LEFT);
            $colAmt  = str_pad($amtStr, $wAmt, " ", STR_PAD_LEFT);

            if (strlen($iName) <= $wItem) {
                $lines[] = str_pad($iName, $wItem) . $colQty . $colRate . $colAmt;
            } else {
                $lines[] = $iName;
                $lines[] = str_pad("", $wItem) . $colQty . $colRate . $colAmt;
            }

            if ($idx < $itemCount - 1) {
                $lines[] = $dividerDotted;
            }
        }
        $lines[] = $dividerDashed;

        // 5. Financial Summary Breakdown
        $receiptSubtotal = 0.0;
        foreach ($order_items as $oi) {
            $receiptSubtotal += (float)$oi['line_total'];
        }
        if ($receiptSubtotal <= 0) {
            $receiptSubtotal = (float)$data['receipt_total'] + (float)$data['discount'];
        }

        $totalPayable = (float)$data['receipt_total'];
        $cashReceived = (float)$data['receipt_cash'];
        $creditAmount = (float)$data['receipt_credit'];
        $dueAmount    = (float)$data['receipt_due'];
        $discountVal  = (float)$data['discount'];

        // Subtotal
        $subtotalStr = "Rs. " . number_format($receiptSubtotal, 0);
        $lines[] = str_pad("Subtotal:", $wLabel) . str_pad($subtotalStr, $wVal, " ", STR_PAD_LEFT);

        // Discount if any
        if ($discountVal > 0) {
            $discountStr = "-Rs. " . number_format($discountVal, 0);
            $lines[] = str_pad("Discount:", $wLabel) . str_pad($discountStr, $wVal, " ", STR_PAD_LEFT);
        }

        // TOTAL PAYABLE (framed by double border)
        $payableStr = "Rs. " . number_format($totalPayable, 0);
        $lines[] = $dividerDouble;
        $lines[] = str_pad("TOTAL PAYABLE:", $wLabel) . str_pad($payableStr, $wVal, " ", STR_PAD_LEFT);
        $lines[] = $dividerDouble;

        // Cash Received & Credited
        $cashStr = "Rs. " . number_format($cashReceived, 0);
        $lines[] = str_pad("Cash", $wLabel) . str_pad($cashStr, $wVal, " ", STR_PAD_LEFT);

        if ($creditAmount > 0) {
            $creditStr = "Rs. " . number_format($creditAmount, 0);
            $lines[] = str_pad("Credited", $wLabel) . str_pad($creditStr, $wVal, " ", STR_PAD_LEFT);
        }

        // Balance / Due (framed by dotted border)
        $dueStr = "Rs. " . number_format($dueAmount, 0);
        $lines[] = $dividerDotted;
        $lines[] = str_pad("BALANCE / DUE:", $wLabel) . str_pad($dueStr, $wVal, " ", STR_PAD_LEFT);
        $lines[] = $dividerDotted;

        // Prior Due Settled (if notes)
        if (!empty($data['receipt_notes']) && stripos($data['receipt_notes'], 'Cleared') !== false) {
            $lines[] = "Prior Due Settled: " . trim($data['receipt_notes']);
        }

        // Remaining Shop Due
        if (!empty($remainingShopDues['has_due'])) {
            $remDueLabel = "Remaining Shop Due:";
            $remDueValStr = "Rs. " . number_format($remainingShopDues['total_due'], 0);
            $labelLen = strlen($remDueLabel);
            $lines[] = str_pad($remDueLabel, $labelLen) . str_pad($remDueValStr, $width - $labelLen, " ", STR_PAD_LEFT);
        }

        // 6. Footer
        $lines[] = $dividerDashed;
        if ($width == 30) {
            $lines[] = $center($website);
            $lines[] = $center("Ph: " . $phone);
        } else {
            $lines[] = $center($website . " • " . $phone);
        }
        // $lines[] = "Thank you for doing business with Liyas International!";
        $lines[] = $center("* * * * * THANK YOU * * * * *");
        $lines[] = "\n\n\n\n";

        return implode("\n", $lines);
    }
}

// Generate Quick Print payloads for Posiflow SC588 (58mm / 30 chars) and 80mm
$rawBtText58 = buildThermalRawBtPayload($data, $order_items, $remainingShopDues, $gstin, $phone, $website, 30);
$rawBtText80 = buildThermalRawBtPayload($data, $order_items, $remainingShopDues, $gstin, $phone, $website, 42);
$rawBtText   = $rawBtText58;

// Prepare inline base64 logo for flawless canvas & PDF generation (zero CORS issues)
$logoPath = dirname(__DIR__) . '/assets/images/logo/logo.png';
if (!file_exists($logoPath)) $logoPath = dirname(__DIR__) . '/assets/images/logo/logo-bg.jpg';
$logoSrc = BASE_URL . '/assets/images/logo/logo.png';
if (file_exists($logoPath)) {
    $mime = mime_content_type($logoPath) ?: 'image/png';
    $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Receipt <?= htmlspecialchars($billNumber) ?> - Liyas International</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <!-- Local html2pdf bundle (offline & intranet ready) with CDN fallback -->
    <script src="<?= BASE_URL ?>/assets/js/html2pdf.bundle.min.js"></script>
    <script>
        if (typeof html2pdf === 'undefined') {
            document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"><\/script>');
        }
    </script>

    <style>
        :root {
            --primary: #2563eb;
            --success: #059669;
            --danger: #dc2626;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--body-bg);
            color: var(--text-main);
            padding: 16px;
            min-height: 100vh;
        }

        /* Success Alert Banner */
        .new-receipt-banner {
            max-width: 680px;
            margin: 0 auto 16px auto;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 12px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 2px 6px rgba(5, 150, 105, 0.08);
        }

        /* Screen Action Bar (Hidden on print) */
        .no-print-bar {
            max-width: 680px;
            margin: 0 auto 16px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-buttons-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn-ctrl {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 10px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: #334155;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-ctrl:hover {
            background: #f1f5f9;
            transform: translateY(-1px);
        }

        .btn-ctrl:active {
            transform: translateY(0);
        }

        .btn-ctrl:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-whatsapp-share {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.28);
        }

        .btn-whatsapp-share:hover {
            background: #047857;
            border-color: #047857;
            color: #ffffff;
        }

        .btn-pdf-download {
            background: #ffffff;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        .btn-pdf-download:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .btn-quick-print {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.25);
        }

        .btn-quick-print:hover {
            background: #1e293b;
            border-color: #1e293b;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-print {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }

        .btn-print:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #ffffff;
        }

        /* Printable Invoice Container */
        .receipt-card {
            max-width: 680px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            padding: 32px 30px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.06);
            position: relative;
        }

        /* Top Watermark / Official Tag */
        .official-tag {
            font-size: 13px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Header */
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 18px;
            margin-bottom: 20px;
            gap: 16px;
        }

        .company-block {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .company-logo-img {
            height: 45px;
            width: auto;
            /* border-radius: 6px; */
            object-fit: cover;
        }

        .company-name {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .invoice-meta {
            text-align: right;
        }

        .bill-num-display {
            font-family: 'JetBrains Mono', monospace;
            font-size: 18px;
            font-weight: 800;
            color: #d11507;
            letter-spacing: 0.02em;
        }

        .gstin-tag {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-top: 2px;
        }

        .meta-date {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            font-weight: 600;
        }

        /* Customer Box */
        .customer-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            font-size: 13px;
        }

        @media (max-width: 480px) {
            .customer-panel { grid-template-columns: 1fr; }
        }

        .cust-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            display: block;
            margin-bottom: 2px;
        }

        .cust-val {
            font-weight: 700;
            color: #0f172a;
            font-size: 14px;
        }

        /* Items Table */
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .receipt-table th {
            background: #0f172a;
            color: #ffffff;
            padding: 10px 12px;
            border: 1px solid #0f172a;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .receipt-table td {
            padding: 12px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }

        /* Financial Breakdown */
        .summary-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }

        .summary-table {
            width: 330px;
            font-size: 13px;
            border-collapse: collapse;
        }

        .summary-table td {
            padding: 6px 8px;
        }

        .summary-total-row td {
            border-top: 2px solid #0f172a;
            border-bottom: 2px solid #0f172a;
            font-size: 16px;
            font-weight: 800;
            padding: 10px 8px;
            color: #0f172a;
        }

        .summary-due-row td {
            font-size: 15px;
            font-weight: 800;
            color: #dc2626;
            background: #fef2f2;
            border-radius: 6px;
            padding: 8px;
        }

        .footer-note {
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .website-link {
            text-decoration: none;
            color: #0f172a;
            font-weight: 700;
        }

        /* Toast message */
        .toast-msg {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            z-index: 99999;
            opacity: 0;
            pointer-events: none;
            transition: all 0.25s ease;
        }

        .toast-msg.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateX(-50%) translateY(-5px);
        }

        /* Modal Overlay for WhatsApp Web / Desktop Guide */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            animation: fadeIn 0.15s ease;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 18px;
            max-width: 520px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: slideUp 0.2s ease;
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-close-btn {
            background: none;
            border: none;
            font-size: 26px;
            line-height: 1;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
        }
        .modal-close-btn:hover { color: #0f172a; }

        .modal-step-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #2563eb;
            color: #ffffff;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .btn-step-action {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .btn-step-wa {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
        }

        .btn-step-wa:hover {
            background: #047857;
            color: #ffffff;
        }

        .modal-tip-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12px;
            color: #1e40af;
            display: flex;
            gap: 10px;
            margin-top: 14px;
            line-height: 1.45;
        }

        .modal-footer {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .btn-modal-primary {
            background: #059669;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
        }

        .btn-modal-primary:hover { background: #047857; }

        .btn-modal-secondary {
            background: #f1f5f9;
            color: #334155;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-modal-secondary:hover { background: #e2e8f0; }

        /* Printer Model Selector / Badge */
        .printer-badge-group {
            display: inline-flex;
            align-items: center;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 3px 6px;
            gap: 6px;
        }

        .printer-model-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            color: #1e293b;
            padding: 2px 6px;
            border-right: 1px solid #cbd5e1;
        }

        .printer-model-badge i {
            color: #2563eb;
            font-size: 16px;
        }

        .printer-format-toggle {
            display: inline-flex;
            gap: 3px;
        }

        .btn-fmt {
            background: transparent;
            border: 1px solid transparent;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            border-radius: 7px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .btn-fmt:hover {
            color: #0f172a;
            background: #e2e8f0;
        }

        .btn-fmt.active {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.2);
        }

        .thermal-feed-spacer {
            display: none;
        }

        .print-only {
            display: none;
        }

        @media (max-width: 640px) {
            .printer-model-badge {
                display: none;
            }
        }

        /* ========================================================
           Dedicated Thermal Printer Print Styles
           Posiflow SC588 (58mm Paper / 42mm Safe Printable Width)
           ======================================================== */
        @media print {
            @page {
                size: 48mm auto;
                margin: 0mm;
            }

            *, *:before, *:after {
                box-sizing: border-box !important;
                color: #000000 !important;
                background: transparent !important;
                box-shadow: none !important;
                text-shadow: none !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                -webkit-text-size-adjust: none !important;
                text-size-adjust: none !important;
            }

            html, body {
                width: 45mm !important;
                max-width: 45mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #000000 !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
                font-size: 8px !important;
                line-height: 1.15 !important;
                overflow-x: hidden !important;
            }

            .no-print-bar,
            .new-receipt-banner,
            .modal-overlay,
            .toast-msg,
            .hide-print {
                display: none !important;
            }

            .print-only {
                display: inline !important;
            }

            .receipt-card {
                width: 45mm !important;
                max-width: 45mm !important;
                box-sizing: border-box !important;
                margin: 0 !important;
                padding: 1mm 1.5mm 8mm 1mm !important;
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 8px !important;
            }

            /* Header Section */
            .receipt-header {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                border-bottom: 1px dashed #000000 !important;
                padding-bottom: 2px !important;
                margin-bottom: 2px !important;
                gap: 1px !important;
                width: 100% !important;
            }

            .company-block {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                width: 100% !important;
                gap: 0 !important;
            }

            .company-logo-img {
                max-height: 18px !important;
                max-width: 65px !important;
                width: auto !important;
                filter: grayscale(100%) contrast(180%) !important;
                margin: 0 auto 1px auto !important;
                display: block !important;
            }

            .company-name, .thermal-brand-title {
                display: block !important;
                font-size: 10px !important;
                font-weight: 800 !important;
                letter-spacing: 0.02em !important;
                text-transform: uppercase !important;
                color: #000000 !important;
                line-height: 1.05 !important;
            }

            .thermal-subtitle {
                font-size: 8.5px !important;
                font-weight: 400 !important;
                text-transform: uppercase !important;
                color: #000000 !important;
                line-height: 1.05 !important;
                margin-top: 1px !important;
            }

            .gstin-tag {
                font-size: 8.5px !important;
                font-weight: 400 !important;
                color: #000000 !important;
                margin-top: 1px !important;
                line-height: 1.05 !important;
            }

            .invoice-meta {
                display: block !important;
                text-align: center !important;
                width: 100% !important;
                margin-top: 2px !important;
                border-top: 1px dotted #000000 !important;
                padding-top: 2px !important;
            }

            .official-tag {
                display: none !important;
            }

            .bill-num-display {
                font-size: 9.5px !important;
                font-weight: 600 !important;
                color: #000000 !important;
                letter-spacing: 0.02em !important;
                line-height: 1.05 !important;
            }

            .meta-date {
                font-size: 10.5px !important;
                font-weight: 600 !important;
                color: #000000 !important;
                line-height: 1.1 !important;
            }

            /* Customer Details */
            .customer-panel {
                background: transparent !important;
                border: none !important;
                border-bottom: 1px dashed #000000 !important;
                border-radius: 0 !important;
                padding: 2px 0 !important;
                margin-bottom: 2px !important;
                display: block !important;
                font-size: 8.5px !important;
                line-height: 1.2 !important;
                width: 100% !important;
            }

            .customer-panel div {
                margin-bottom: 1px !important;
            }

            .cust-label {
                font-size: 8.5px !important;
                font-weight: 400 !important;
                text-transform: uppercase !important;
                color: #000000 !important;
                display: inline !important;
            }

            .cust-val {
                font-size: 8.5px !important;
                font-weight: 400 !important;
                color: #000000 !important;
                display: inline !important;
                word-break: break-word !important;
            }

            .cust-sub {
                font-size: 8.5px !important;
                color: #000000 !important;
                margin-top: 1px !important;
            }

            /* Items Table */
            .receipt-table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed !important;
                margin-bottom: 2px !important;
                border-collapse: collapse !important;
                font-size: 8.5px !important;
                line-height: 1.1 !important;
                font-variant-numeric: tabular-nums !important;
            }

            .receipt-table th {
                background: transparent !important;
                color: #000000 !important;
                border: none !important;
                border-top: 1px dashed #000000 !important;
                border-bottom: 1px dashed #000000 !important;
                padding: 1.5px 0.5px !important;
                font-size: 8.5px !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                letter-spacing: 0 !important;
            }

            .receipt-table td {
                border: none !important;
                padding: 1px 0.5px !important;
                color: #000000 !important;
                font-size: 8.5px !important;
                vertical-align: top !important;
            }

            .receipt-table tbody tr {
                border-bottom: 1px dotted #888888 !important;
            }

            .receipt-table .th-product,
            .receipt-table .td-product {
                width: 35% !important;
                text-align: left !important;
                word-break: break-word !important;
                padding-right: 1px !important;
                font-weight: 600 !important;
                font-size: 10.5px !important;
            }

            .receipt-table .th-qty,
            .receipt-table .td-qty {
                width: 14% !important;
                text-align: center !important;
                   font-size: 10.5px !important;
            }

            .receipt-table .th-rate,
            .receipt-table .td-rate {
                width: 24% !important;
                text-align: right !important;
                white-space: nowrap !important;
                   font-size: 10.5px !important;
            }

            .receipt-table .th-amt,
            .receipt-table .td-amt {
                width: 27% !important;
                text-align: right !important;
                padding-right: 2mm !important;
                white-space: nowrap !important;
                   font-size: 10.5px !important;
            }

            /* Financial Summary */
            .summary-container {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                margin-bottom: 2px !important;
                padding-top: 1px !important;
            }

            .summary-table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed !important;
                font-size: 8.5px !important;
                line-height: 1.2 !important;
                border-collapse: collapse !important;
                font-variant-numeric: tabular-nums !important;
            }

            .summary-table td {
                padding: 1px 0.5px !important;
                color: #000000 !important;
                font-size: 8.5px !important;
            }

            .summary-table td:first-child {
                width: 55% !important;
                text-align: left !important;
                word-break: break-word !important;
            }

            .summary-table td:last-child {
                width: 45% !important;
                text-align: right !important;
                padding-right: 2mm !important;
                white-space: nowrap !important;
            }

            .summary-table tr td[style*="padding-top"] {
                padding-top: 1px !important;
            }

            .summary-total-row td {
                border-top: 1px dashed #000000 !important;
                border-bottom: 1px dashed #000000 !important;
                font-size: 8.5px !important;
                font-weight: 400 !important;
                padding: 2px 0.5px !important;
            }

            .summary-due-row td {
                border-top: 1px dotted #000000 !important;
                border-bottom: 1px dotted #000000 !important;
                font-size: 10.5px !important;
                font-weight: 400 !important;
                padding: 1.5px 0.5px !important;
            }

            /* Footer */
            .footer-note {
                border-top: 1px dashed #000000 !important;
                padding-top: 2px !important;
                text-align: center !important;
                font-size: 10.5px !important;
                color: #000000 !important;
                margin-top: 2px !important;
                line-height: 1.15 !important;
            }

            .footer-note a {
                color: #000000 !important;
                text-decoration: none !important;
                font-size: 10.5px !important;
            }

            .thermal-cut-guide {
                display: block !important;
                text-align: center !important;
                margin-top: 2px !important;
                font-size: 7px !important;
                color: #000000 !important;
                letter-spacing: 0.5px !important;
            }

            .thermal-feed-spacer {
                display: block !important;
                height: 10mm !important;
                width: 100% !important;
            }

            /* 80mm Overrides when .fmt-80 is toggled */
            body.fmt-80 {
                width: 78mm !important;
                max-width: 80mm !important;
                font-size: 10.5px !important;
            }
            body.fmt-80 .receipt-card {
                width: 78mm !important;
                max-width: 78mm !important;
                font-size: 10.5px !important;
                padding: 2mm 2mm 8mm 2mm !important;
            }
            body.fmt-80 .company-logo-img {
                max-height: 26px !important;
                max-width: 90px !important;
            }
            body.fmt-80 .company-name, body.fmt-80 .thermal-brand-title {
                font-size: 13px !important;
            }
            body.fmt-80 .bill-num-display {
                font-size: 11.5px !important;
            }
            body.fmt-80 .cust-val {
                font-size: 10px !important;
            }
            body.fmt-80 .receipt-table {
                font-size: 9px !important;
            }
            body.fmt-80 .receipt-table th {
                font-size: 8px !important;
                padding: 2px 1px !important;
            }
            body.fmt-80 .receipt-table td {
                font-size: 9px !important;
                padding: 1.5px 1px !important;
            }
            body.fmt-80 .summary-table {
                font-size: 9px !important;
            }
            body.fmt-80 .summary-total-row td {
                font-size: 10.5px !important;
                padding: 2.5px 1px !important;
            }
            body.fmt-80 .summary-due-row td {
                font-size: 10.5px !important;
            }
            body.fmt-80 .footer-note {
                font-size: 10.5px !important;
            }
        }
    </style>
    <style id="dynamicPrintPageStyle">@page { size: 48mm auto; margin: 0; }</style>
</head>
<body>

    <!-- On-screen Navigation Controls (Hidden in print) -->
    <div class="no-print-bar">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="<?= zone_url($zone_slug) ?>" class="btn-ctrl" title="Back to Zone">
                <i class='bx bx-arrow-back'></i>
            </a>

            <div class="action-buttons-group">
                <div class="printer-badge-group">
                    <div class="printer-model-badge" title="Target Printer: Posiflow SC588 (58mm thermal paper, 48mm printable width)">
                        <i class='bx bx-printer'></i>
                        <span>Posiflow SC588</span>
                    </div>
                
                </div>

                <button onclick="printViaRawBT()" id="btnQuickPrint" class="btn-ctrl btn-quick-print" title="⚡ Instant 1-Click High-Speed Bluetooth Print via RawBT (Under 1 second)">
                    <i class='bx bxs-zap' style="color: #f59e0b; font-size: 18px;"></i>
                    <span> Quick Print</span>
                </button>

                <button onclick="window.print()" id="btnPrintReceipt" class="btn-ctrl btn-print" title="Print receipt via Thermal Printer">
                    <i class='bx bx-printer' style="font-size: 18px;"></i>
                    <span>Print Receipt</span>
                </button>

                <button onclick="downloadReceiptPdf()" id="btnDownloadPdf" class="btn-ctrl btn-pdf-download" title="Download official PDF receipt file">
                    <i class='bx bxs-file-pdf' style="font-size: 18px; color: #dc2626;"></i>
                    <span>Download PDF</span>
                </button>

                <a href="<?= zone_url($zone_slug, 'generate-receipt', ['reorder_id' => $order_id]) ?>" class="btn-ctrl" style="background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe;" title="Place Reorder for this Shop">
                    <i class='bx bx-refresh' style="font-size: 18px;"></i>
                    <span>Reorder</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Success banner if freshly generated -->
    <?php if (isset($_GET['new']) && $_GET['new'] == '1'): ?>
        <div class="new-receipt-banner">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class='bx bx-check-circle' style="font-size: 26px; color: #059669; flex-shrink: 0;"></i>
                <div>
                    <div style="font-weight: 800; font-size: 14px; color: #065f46;">
                        Receipt Generated &bull; Bill #<?= htmlspecialchars($billNumber) ?>
                    </div>
                    <div style="font-size: 12px; color: #047857; margin-top: 1px;">
                        Delivered to <strong><?= $shopName ?></strong>.
                    </div>
                </div>
            </div>
            <button onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 20px; color: #047857; cursor: pointer;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Official Printable Delivery Receipt -->
    <div class="receipt-card" id="receiptPrintCard">
        <!-- Header -->
        <div class="receipt-header">
            <div class="company-block">
                <img src="<?= $logoSrc ?>" alt="Liyas Logo" class="company-logo-img">
                <!-- <div class="company-name thermal-brand-title">LIYAS INTERNATIONAL</div> -->
                <div class="thermal-subtitle" style="font-size: 8.5px; color: #64748b;">Packaged Drinking Water</div>
                <div class="gstin-tag">GSTIN: <?= htmlspecialchars($gstin) ?></div>
            </div>

            <div class="invoice-meta">
                <div class="official-tag">Official Bill No</div>
                <div class="bill-num-display">#<?= htmlspecialchars($billNumber) ?></div>
                <div class="meta-date"><?= htmlspecialchars($receiptDate) ?> &bull; <?= htmlspecialchars($receiptTime) ?></div>
            </div>
        </div>

        <!-- Customer Info -->
        <div class="customer-panel">
            <div>
                <span class="cust-label">Billed To:</span>
                <span class="cust-val"><?= $shopName ?></span>
                <?php if (!empty($contactName) && $contactName !== $shopName): ?>
                    <div class="cust-sub" style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                        Attn: <?= $contactName ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <span class="cust-label">Delivery Location:</span>
                <div class="cust-val"><?= $place ?></div>
                <div class="cust-sub" style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    Phone: <?= $customerTel ?: 'N/A' ?>
                </div>
            </div>
        </div>

        <!-- Product Items Table -->
        <table class="receipt-table">
            <thead>
                <tr>
                    <th class="th-product" style="text-align: left;"><span class="hide-print">Product</span><span class="print-only">Item</span></th>
                    <th class="th-qty" style="text-align: center;">Qty<span class="hide-print"> (Cases)</span></th>
                    <th class="th-rate" style="text-align: right;">Rate</th>
                    <th class="th-amt" style="text-align: right;"><span class="hide-print">Amount</span><span class="print-only">Amt</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order_items as $item): 
                    $pItemName = htmlspecialchars($item['product_name'] ?: ($item['name'] ?: 'Liyas Water'));
                    $pItemQty = (int)$item['quantity'];
                    $pItemRate = (float)$item['price_at_purchase'];
                    $pItemAmount = (float)$item['line_total'];
                ?>
                <tr>
                    <td class="td-product">
                        <?= $pItemName ?>
                    </td>
                    <td class="td-qty" style="text-align: center; font-size: 8.5px; font-weight: 400;">
                        <?= $pItemQty ?>
                    </td>
                    <td class="td-rate" style="text-align: right;">
                        <?= formatCurrency($pItemRate) ?>
                    </td>
                    <td class="td-amt" style="text-align: right; font-size: 8.5px; font-weight: 400;">
                     <?= '₹' . number_format((float)$pItemAmount, 0) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Summary & Due Calculation -->
        <div class="summary-container">
            <table class="summary-table">
                <tr>
                    <td style="color: var(--text-muted);">Subtotal:</td>
                    <td style="text-align: right; font-size: 8.5px; font-weight: 400;"><?= '₹' . number_format((float)$receiptSubtotal, 0) ?></td>
                </tr>
                <?php if ((float)$data['discount'] > 0): ?>
                <tr>
                    <td style="color: var(--text-muted);">Discount:</td>
                    <td style="text-align: right; color: #dc2626; font-size: 8.5px; font-weight: 400;">- <?= '₹' . number_format((float)$data['discount'], 0) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="summary-total-row">
                    <td>TOTAL PAYABLE:</td>
                    <td style="text-align: right; font-size: 8.5px;"><?= '₹' . number_format((float)$totalPayable, 0) ?></td>
                </tr>
                <!-- <tr>
                    <td style="color: var(--text-muted); padding-top: 10px;">Payment Mode:</td>
                    <td style="text-align: right; font-weight: 700; padding-top: 10px;"><?= $payType ?></td>
                </tr> -->
                <tr>
                    <td style="color: #059669;">Cash</td>
                    <td style="text-align: right; color: #059669; font-size:8.5px; font-weight: 400;"><?= '₹' . number_format((float)$cashReceived, 0) ?></td>
                </tr>
                <tr>
                    <td style="color: #d97706;">Credited</td>
                    <td style="text-align: right; color: #d97706; font-size: 8.5px; font-weight: 400;"><?= '₹' . number_format((float)$creditAmount, 0) ?></td>
                </tr>
                <tr class="summary-due-row">
                    <td>BALANCE / DUE:</td>
                   <td style="text-align: right; font-size: 8.5px;"><?= '₹' . number_format((float)$dueAmount, 0) ?></td>
                </tr>
                <?php if (!empty($data['receipt_notes']) && stripos($data['receipt_notes'], 'Cleared') !== false): ?>
                <tr>
                    <td style="color: #059669; font-weight: 700; padding-top: 8px;">Prior Due Settled:</td>
                    <td style="text-align: right; color: #059669; font-weight: 800; padding-top: 8px; font-size: 11px;">
                        <?= htmlspecialchars($data['receipt_notes']) ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($remainingShopDues['has_due'])): ?>
                <tr>
                    <td style="color: #dc2626; font-weight: 700; padding-top: 8px;">Remaining Shop Due:</td>
                    <td style="text-align: right; color: #dc2626; font-weight: 800; padding-top: 8px;">
                       <?= '₹' . number_format((float)$remainingShopDues['total_due'], 0) ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer-note">
            <div style="font-weight: 700; color: #0f172a; margin-bottom: 2px;">
                <a class="website-link" href="https://liyasinternational.com/"> <?= htmlspecialchars($website) ?> &bull;</a> 
            </div>
            <div>Thank you for doing business with Liyas International!</div>
            <div class="thermal-cut-guide" style="display: none;">* * * * * THANK YOU * * * * *</div>
        </div>
        <div class="thermal-feed-spacer"></div>
    </div>

    <!-- Desktop WhatsApp Helper Modal -->
    <div id="waDesktopModal" class="modal-overlay" style="display: none;">
        <div class="modal-box">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class='bx bxl-whatsapp' style="font-size: 32px; color: #059669;"></i>
                    <div>
                        <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Send Receipt via WhatsApp</h3>
                        <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Bill #<?= htmlspecialchars($billNumber) ?> &bull; <?= $shopName ?></p>
                    </div>
                </div>
                <button onclick="closeWaModal()" class="modal-close-btn" aria-label="Close modal">&times;</button>
            </div>

            <div class="modal-body">
                <div class="modal-step-card">
                    <div class="step-num">1</div>
                    <div style="flex: 1;">
                        <strong style="font-size: 13px; color: #0f172a;">PDF Receipt Downloaded</strong>
                        <div style="font-size: 12px; color: #64748b; margin-top: 1px;">
                            Saved as <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 700;">Receipt-<?= htmlspecialchars($billNumber) ?>.pdf</code>
                        </div>
                    </div>
                    <button onclick="downloadReceiptPdf()" class="btn-step-action" title="Re-download PDF">
                        <i class='bx bx-download'></i> Re-download
                    </button>
                </div>

                <div class="modal-step-card">
                    <div class="step-num">2</div>
                    <div style="flex: 1;">
                        <strong style="font-size: 13px; color: #0f172a;">Open WhatsApp Chat</strong>
                        <div style="font-size: 12px; color: #64748b; margin-top: 1px;">
                            Pre-filled with customer bill summary for <?= htmlspecialchars($customerTel ?: 'Customer') ?>.
                        </div>
                    </div>
                    <a href="<?= $waShareUrl ?>" target="_blank" class="btn-step-action btn-step-wa" id="modalOpenWaBtn">
                        <i class='bx bxl-whatsapp'></i> Open Chat
                    </a>
                </div>

                <div class="modal-tip-box">
                    <i class='bx bx-info-circle' style="font-size: 22px; color: #2563eb; flex-shrink: 0;"></i>
                    <div>
                        <strong>How to attach in WhatsApp Web:</strong> In the opened chat, click the <strong>📎 (Attach)</strong> icon &rarr; select <strong>Document</strong> &rarr; choose your downloaded <code style="font-weight: 700;">Receipt-<?= htmlspecialchars($billNumber) ?>.pdf</code>!
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button onclick="copyWaText()" class="btn-modal-secondary">
                    <i class='bx bx-copy'></i> Copy Text
                </button>
                <a href="<?= $waShareUrl ?>" target="_blank" class="btn-modal-primary">
                    <i class='bx bxl-whatsapp'></i> Continue to WhatsApp
                </a>
            </div>
        </div>
    </div>

    <!-- iOS Thermal Print Helper Modal -->
    <div id="iosPrintModal" class="modal-overlay" style="display: none;">
        <div class="modal-box">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class='bx bxl-apple' style="font-size: 32px; color: #0f172a;"></i>
                    <div>
                        <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Print Receipt on iPhone</h3>
                        <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Bill #<?= htmlspecialchars($billNumber) ?> &bull; Posiflow SC588 (58mm)</p>
                    </div>
                </div>
                <button onclick="closeIosPrintModal()" class="modal-close-btn" aria-label="Close modal">&times;</button>
            </div>

            <div class="modal-body">
                <!-- Option 1: Direct App Deep-Link -->
                <div class="modal-step-card">
                    <div class="step-num"><i class='bx bxs-zap' style="color: #fef08a;"></i></div>
                    <div style="flex: 1;">
                        <strong style="font-size: 13px; color: #0f172a;">1-Tap App Print</strong>
                        <div style="font-size: 12px; color: #64748b; margin-top: 1px;">
                            Sends 30-col layout to <em>Simple Bluetooth Printer</em>.
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; flex-direction: column; align-items: flex-end;">
                        <a href="thermalprint://print?text=<?= rawurlencode($rawBtText58) ?>" class="btn-step-action" style="background: #0f172a; color: #ffffff;" id="iosDeepLinkBtn">
                            <i class='bx bx-printer'></i> Print Now
                        </a>
                        <a href="https://apps.apple.com/app/simple-bluetooth-printer/id1457492850" target="_blank" class="btn-step-action" style="font-size: 10px; color: #64748b; border: none; padding: 2px 4px;">
                            <i class='bx bxl-apple'></i> Get App (Free)
                        </a>
                    </div>
                </div>

                <!-- Option 2: iOS Share Sheet -->
                <div class="modal-step-card">
                    <div class="step-num">2</div>
                    <div style="flex: 1;">
                        <strong style="font-size: 13px; color: #0f172a;">Share to Printer App</strong>
                        <div style="font-size: 12px; color: #64748b; margin-top: 1px;">
                            Passes receipt to <em>Thermer</em>, <em>POS-Printer</em>, or WhatsApp.
                        </div>
                    </div>
                    <button onclick="shareViaIosSheet()" class="btn-step-action btn-step-wa">
                        <i class='bx bx-share-alt'></i> Share
                    </button>
                </div>

                <!-- Option 3: Standard AirPrint -->
                <div class="modal-step-card">
                    <div class="step-num">3</div>
                    <div style="flex: 1;">
                        <strong style="font-size: 13px; color: #0f172a;">Standard AirPrint</strong>
                        <div style="font-size: 12px; color: #64748b; margin-top: 1px;">
                            For Wi-Fi / AirPrint-shared printers.
                        </div>
                    </div>
                    <button onclick="window.print()" class="btn-step-action">
                        <i class='bx bx-printer'></i> AirPrint
                    </button>
                </div>

                <!-- Tip Box: Direct Web Bluetooth via Bluefy -->
                <div class="modal-tip-box" style="background: #f0fdf4; border-color: #bbf7d0; color: #166534;">
                    <i class='bx bx-check-shield' style="font-size: 24px; color: #16a34a; flex-shrink: 0;"></i>
                    <div>
                        <strong>Want 1-Tap Direct Bluetooth Print on iPhone?</strong><br>
                        Open this receipt in the free <strong>Bluefy Browser</strong> on iOS. In Bluefy, tapping ⚡ Quick Print connects directly to your Posiflow SC588 over Bluetooth with zero intermediate apps!
                        <div style="margin-top: 6px;">
                            <a href="https://apps.apple.com/app/bluefy-web-ble-browser/id1492822055" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; background: #16a34a; color: #ffffff; padding: 4px 10px; border-radius: 6px; text-decoration: none; font-weight: 700; font-size: 11px;">
                                <i class='bx bxl-apple'></i> Install Free Bluefy
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button onclick="closeIosPrintModal()" class="btn-modal-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- Floating Toast Notification -->
    <div id="toastMsg" class="toast-msg">
        <i class='bx bx-check-circle' style="font-size: 18px; color: #34d399;"></i>
        <span id="toastText">Action completed</span>
    </div>

    <script>
        const BILL_NUMBER = <?= json_encode($billNumber) ?>;
        const WA_MESSAGE_TEXT = <?= json_encode($waMsg) ?>;
        const WA_SHARE_URL = <?= json_encode($waShareUrl) ?>;
        const RAWBT_TEXT_58 = <?= json_encode($rawBtText58) ?>;
        const RAWBT_TEXT_80 = <?= json_encode($rawBtText80) ?>;
        const RAWBT_TEXT = RAWBT_TEXT_58;
        let currentPrintFormat = '58';

        let cachedPdfBlob = null;

        // Show toast notification
        function showToast(text, duration = 3000) {
            const toast = document.getElementById('toastMsg');
            const toastText = document.getElementById('toastText');
            toastText.textContent = text;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, duration);
        }

        let bleDevice = null;
        let bleCharacteristic = null;

        // Direct Web Bluetooth Printing (works in Bluefy browser on iOS, Chrome on Android/Desktop)
        async function printViaWebBluetooth(rawText) {
            try {
                showToast('⚡ Connecting to Bluetooth printer...', 3000);
                if (!bleCharacteristic) {
                    const device = await navigator.bluetooth.requestDevice({
                        acceptAllDevices: true,
                        optionalServices: [
                            '000018f0-0000-1000-8000-00805f9b34fb',
                            '49535343-fe7d-4ae5-8fa9-9fafd205e455',
                            'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
                            '0000ff00-0000-1000-8000-00805f9b34fb',
                            '0000e0ff-0000-1000-8000-00805f9b34fb'
                        ]
                    });
                    bleDevice = device;
                    const server = await device.gatt.connect();
                    const services = await server.getPrimaryServices();
                    for (const s of services) {
                        try {
                            const chars = await s.getCharacteristics();
                            for (const c of chars) {
                                if (c.properties.write || c.properties.writeWithoutResponse) {
                                    bleCharacteristic = c;
                                    break;
                                }
                            }
                        } catch(ce){}
                        if (bleCharacteristic) break;
                    }
                }

                if (!bleCharacteristic) {
                    throw new Error("Connected, but could not detect writable print characteristic.");
                }

                // Convert text to ESC/POS bytes
                const encoder = new TextEncoder();
                const data = encoder.encode(rawText);

                // Write in chunks (safe for BLE MTU)
                const chunkSize = 64;
                for (let i = 0; i < data.length; i += chunkSize) {
                    const chunk = data.slice(i, i + chunkSize);
                    if (bleCharacteristic.writeValueWithoutResponse) {
                        await bleCharacteristic.writeValueWithoutResponse(chunk);
                    } else {
                        await bleCharacteristic.writeValue(chunk);
                    }
                    await new Promise(r => setTimeout(r, 20));
                }

                showToast('⚡ Receipt printed via Bluetooth!');
            } catch (err) {
                console.warn('Web Bluetooth error:', err);
                if (err.name !== 'NotFoundError') {
                    showToast('Bluetooth error: ' + err.message, 4000);
                    openIosPrintModal();
                }
            }
        }

        function updateIosDeepLink() {
            const rawText = (currentPrintFormat === '80') ? RAWBT_TEXT_80 : RAWBT_TEXT_58;
            const btn = document.getElementById('iosDeepLinkBtn');
            if (btn) {
                btn.href = "thermalprint://print?text=" + encodeURIComponent(rawText);
            }
        }

        function openIosPrintModal() {
            updateIosDeepLink();
            const modal = document.getElementById('iosPrintModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeIosPrintModal() {
            const modal = document.getElementById('iosPrintModal');
            if (modal) modal.style.display = 'none';
        }

        async function shareViaIosSheet() {
            const rawText = (currentPrintFormat === '80') ? RAWBT_TEXT_80 : RAWBT_TEXT_58;
            try {
                if (navigator.share) {
                    await navigator.share({
                        title: `Receipt #${BILL_NUMBER}`,
                        text: rawText
                    });
                    showToast('Receipt shared to printer app / chat');
                } else {
                    copyWaText();
                }
            } catch (err) {
                if (err.name !== 'AbortError') {
                    console.warn('Share sheet error:', err);
                }
            }
        }

        // Direct Ultra-Fast Print (Android RawBT / iOS Web Bluetooth / iOS Modal / AirPrint)
        function printViaRawBT() {
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            const isAndroid = /Android/i.test(navigator.userAgent);
            const rawText = (currentPrintFormat === '80') ? RAWBT_TEXT_80 : RAWBT_TEXT_58;

            // 1. If Web Bluetooth is available on iOS (e.g. user is in Bluefy browser)
            if (isIOS && navigator.bluetooth) {
                printViaWebBluetooth(rawText);
                return;
            }

            // 2. Standard iOS Safari (open iOS print options modal)
            if (isIOS) {
                openIosPrintModal();
                return;
            }

            // 3. Android - Ultra-Fast RawBT Intent (< 1 second)
            if (isAndroid) {
                try {
                    showToast('⚡ Sending to RawBT for instant print...', 2500);
                    const encoded = btoa(unescape(encodeURIComponent(rawText)));
                    const intentUrl = "intent:base64," + encoded + "#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dru.a402d.rawbtprinter;end;";
                    window.location.href = intentUrl;
                } catch (err) {
                    console.warn('RawBT intent error, falling back to browser print:', err);
                    window.print();
                }
                return;
            }

            // 4. Desktop / Other browsers
            window.print();
        }

        // Helper to trigger browser download from a Blob
        function downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => {
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, 200);
        }

        // Generate PDF Blob using html2pdf
        async function getReceiptPdfBlob() {
            if (cachedPdfBlob) return cachedPdfBlob;
            const cardEl = document.getElementById('receiptPrintCard');
            const opt = {
                margin: [6, 6, 6, 6],
                filename: `Receipt-${BILL_NUMBER}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                }
            };
            cachedPdfBlob = await html2pdf().set(opt).from(cardEl).output('blob');
            return cachedPdfBlob;
        }

        // Copy receipt image to clipboard for instant Ctrl+V in WhatsApp chat
        async function copyReceiptImageToClipboard() {
            try {
                const cardEl = document.getElementById('receiptPrintCard');
                if (typeof html2canvas !== 'undefined' && navigator.clipboard && window.ClipboardItem) {
                    const canvas = await html2canvas(cardEl, {
                        scale: 2,
                        useCORS: true,
                        backgroundColor: '#ffffff',
                        logging: false
                    });
                    canvas.toBlob(async (blob) => {
                        if (blob) {
                            try {
                                await navigator.clipboard.write([
                                    new ClipboardItem({ 'image/png': blob })
                                ]);
                            } catch (clipErr) {
                                console.warn('Clipboard write failed:', clipErr);
                            }
                        }
                    }, 'image/png');
                }
            } catch (e) {
                console.warn('Could not copy image to clipboard:', e);
            }
        }

        // Primary: Send via WhatsApp (Direct 1-Click like earlier + PDF Auto-Download + Image in Clipboard)
        async function shareViaWhatsApp() {
            const btn = document.getElementById('btnWaShare');
            const origHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Opening WhatsApp...";

            try {
                // 1. Copy receipt image to clipboard so user can press Ctrl+V in WhatsApp
                copyReceiptImageToClipboard();

                // 2. Auto-download official PDF receipt in background
                getReceiptPdfBlob().then(pdfBlob => {
                    downloadBlob(pdfBlob, `Receipt-${BILL_NUMBER}.pdf`);
                }).catch(e => console.warn('PDF download failed:', e));

                // 3. Directly open WhatsApp chat with customer (NO Windows OS popup!)
                window.open(WA_SHARE_URL, '_blank');

                showToast('Opening WhatsApp! Receipt PDF downloaded. (Press Ctrl+V in chat to attach image)', 5000);
            } catch (err) {
                console.error('Error in shareViaWhatsApp:', err);
                window.open(WA_SHARE_URL, '_blank');
            } finally {
                setTimeout(() => {
                    btn.innerHTML = origHTML;
                    btn.disabled = false;
                }, 1000);
            }
        }

        // Direct PDF Download
        async function downloadReceiptPdf() {
            const btn = document.getElementById('btnDownloadPdf');
            const origHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Generating...";

            try {
                const pdfBlob = await getReceiptPdfBlob();
                downloadBlob(pdfBlob, `Receipt-${BILL_NUMBER}.pdf`);
                showToast(`Downloaded Receipt-${BILL_NUMBER}.pdf`);
            } catch (err) {
                console.error('Error downloading PDF:', err);
                alert('Could not generate PDF: ' + err.message);
            } finally {
                btn.innerHTML = origHTML;
                btn.disabled = false;
            }
        }

        // Direct PNG Image Download using html2canvas
        async function downloadReceiptImage() {
            const btn = document.getElementById('btnDownloadImg');
            const origHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Capturing...";

            try {
                const cardEl = document.getElementById('receiptPrintCard');
                if (typeof html2canvas === 'undefined') {
                    throw new Error('Canvas renderer not loaded');
                }
                const canvas = await html2canvas(cardEl, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff'
                });
                canvas.toBlob((blob) => {
                    if (blob) {
                        downloadBlob(blob, `Receipt-${BILL_NUMBER}.png`);
                        showToast(`Saved Receipt-${BILL_NUMBER}.png`);
                    }
                    btn.innerHTML = origHTML;
                    btn.disabled = false;
                }, 'image/png');
            } catch (err) {
                console.error('Error downloading image:', err);
                btn.innerHTML = origHTML;
                btn.disabled = false;
                alert('Could not capture receipt image: ' + err.message);
            }
        }

        // Modal Controls
        function openWaModal() {
            document.getElementById('waDesktopModal').style.display = 'flex';
        }

        function closeWaModal() {
            document.getElementById('waDesktopModal').style.display = 'none';
        }

        // Close modal on background click
        document.getElementById('waDesktopModal').addEventListener('click', function(e) {
            if (e.target === this) closeWaModal();
        });

        // Copy WhatsApp text to clipboard
        async function copyWaText() {
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(WA_MESSAGE_TEXT);
                    showToast('Receipt message copied to clipboard!');
                } else {
                    const temp = document.createElement('textarea');
                    temp.value = WA_MESSAGE_TEXT;
                    document.body.appendChild(temp);
                    temp.select();
                    document.execCommand('copy');
                    document.body.removeChild(temp);
                    showToast('Receipt message copied to clipboard!');
                }
            } catch (e) {
                alert('Could not copy text automatically. Please copy manually.');
            }
        }

        // Thermal Printer Format Switcher (Posiflow SC588 58mm / 80mm)
        function setPrintFormat(fmt) {
            currentPrintFormat = fmt;
            updateIosDeepLink();
            const pageStyleEl = document.getElementById('dynamicPrintPageStyle');
            const btn58 = document.getElementById('btnFmt58');
            const btn80 = document.getElementById('btnFmt80');

            if (fmt === '80') {
                document.body.classList.remove('fmt-58');
                document.body.classList.add('fmt-80');
                if (btn58) btn58.classList.remove('active');
                if (btn80) btn80.classList.add('active');
                if (pageStyleEl) {
                    pageStyleEl.textContent = '@page { size: 80mm auto; margin: 0; }';
                }
                try { localStorage.setItem('liyas_receipt_printer_format', '80'); } catch(e){}
            } else {
                document.body.classList.remove('fmt-80');
                document.body.classList.add('fmt-58');
                if (btn58) btn58.classList.add('active');
                if (btn80) btn80.classList.remove('active');
                if (pageStyleEl) {
                    pageStyleEl.textContent = '@page { size: 48mm auto; margin: 0; }';
                }
                try { localStorage.setItem('liyas_receipt_printer_format', '58'); } catch(e){}
            }
        }

        // Close iOS modal on background click
        document.getElementById('iosPrintModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeIosPrintModal();
        });

        // Apply saved thermal printer preference (defaults to Posiflow SC588 58mm)
        try {
            const savedFmt = localStorage.getItem('liyas_receipt_printer_format') || '58';
            setPrintFormat(savedFmt);
        } catch(e) {
            setPrintFormat('58');
        }
    </script>

</body>
</html>

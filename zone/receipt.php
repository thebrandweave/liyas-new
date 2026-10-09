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

// Company Details
$gstin   = getSystemSetting($pdo, 'company_gstin', '29ABCDE1234F1Z5');
$phone   = getSystemSetting($pdo, 'company_phone', '+91 63663 78967');
$website = getSystemSetting($pdo, 'company_website', 'liyasinternational.com');

$billNumber = $data['bill_number'];
$receiptDate = date('d F Y', strtotime($data['receipt_created_at'] ?: 'now'));
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
$order_items = getOrderItems($pdo, $order_id);
$totalDeliveredCases = 0;
$receiptSubtotal = 0.0;
$itemsWaLines = [];

foreach ($order_items as $oi) {
    $iName = $oi['product_name'] ?: ($oi['name'] ?: 'Liyas Water');
    $iQty = (int)$oi['quantity'];
    $totalDeliveredCases += $iQty;
    $receiptSubtotal += (float)$oi['line_total'];
    $itemsWaLines[] = "• *{$iName}* ({$iQty} Cases)";
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
            height: 52px;
            width: auto;
            border-radius: 6px;
            object-fit: contain;
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
            font-size: 20px;
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

        /* Print Media Styles (A4 + Thermal Printer 80mm/58mm Support) */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print-bar,
            .new-receipt-banner,
            .modal-overlay,
            .toast-msg {
                display: none !important;
            }

            .receipt-card {
                border: none !important;
                box-shadow: none !important;
                max-width: 100% !important;
                padding: 0 !important;
                border-radius: 0 !important;
            }

            .receipt-header {
                border-bottom: 2px solid #000 !important;
            }

            .receipt-table th {
                background: #000 !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-total-row td {
                border-top: 2px solid #000 !important;
                border-bottom: 2px solid #000 !important;
            }
        }
    </style>
</head>
<body>

    <!-- On-screen Navigation Controls (Hidden in print) -->
    <div class="no-print-bar">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="<?= zone_url($zone_slug) ?>" class="btn-ctrl">
                <i class='bx bx-arrow-back'></i>
            </a>

  <div class="action-buttons-group">
            <button onclick="shareViaWhatsApp()" id="btnWaShare" class="btn-ctrl btn-whatsapp-share" title="Send Receipt via WhatsApp directly with auto PDF download and clipboard image">
                <i class='bx bxl-whatsapp' style="font-size: 15px;"></i>
                <span>Send to WhatsApp</span>
            </button>

            <button onclick="downloadReceiptPdf()" id="btnDownloadPdf" class="btn-ctrl btn-pdf-download" title="Download official PDF receipt file">
                <i class='bx bxs-file-pdf' style="font-size: 18px; color: #dc2626;"></i>
                <span>Download PDF</span>
            </button>

          

          
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
                        Delivered to <strong><?= $shopName ?></strong>. Tap <strong>"Send via WhatsApp (PDF)"</strong> to share with customer.
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
                <div class="gstin-tag">GSTIN: <?= htmlspecialchars($gstin) ?></div>
            </div>

            <div class="invoice-meta">
                <div class="official-tag">Official Bill No</div>
                <div class="bill-num-display"><?= htmlspecialchars($billNumber) ?></div>
                <div class="meta-date"><?= htmlspecialchars($receiptDate) ?> &bull; <?= htmlspecialchars($receiptTime) ?></div>
            </div>
        </div>

        <!-- Customer Info -->
        <div class="customer-panel">
            <div>
                <span class="cust-label">Billed To (Shop / Customer):</span>
                <span class="cust-val"><?= $shopName ?></span>
                <?php if (!empty($contactName) && $contactName !== $shopName): ?>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                        Attn: <?= $contactName ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <span class="cust-label">Delivery Location &amp; Phone:</span>
                <div class="cust-val"><?= $place ?></div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    Phone: <?= $customerTel ?: 'N/A' ?>
                </div>
            </div>
        </div>

        <!-- Product Items Table -->
        <table class="receipt-table">
            <thead>
                <tr>
                    <th style="text-align: left;">Product Item</th>
                    <th style="text-align: center;">Qty (Cases)</th>
                    <th style="text-align: right;">Rate / Case</th>
                    <th style="text-align: right;">Amount</th>
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
                    <td>
                        <strong><?= $pItemName ?></strong>
                        <?php if (!empty($item['net_content'])): ?>
                            <span style="font-size: 12px; color: var(--text-muted);">
                                (<?= (float)$item['net_content'] ?> <?= htmlspecialchars($item['net_content_unit'] ?? '') ?>)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-weight: 700;">
                        <?= $pItemQty ?>
                    </td>
                    <td style="text-align: right;">
                        <?= formatCurrency($pItemRate) ?>
                    </td>
                    <td style="text-align: right; font-weight: 700;">
                        <?= formatCurrency($pItemAmount) ?>
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
                    <td style="text-align: right; font-weight: 700;"><?= formatCurrency($receiptSubtotal) ?></td>
                </tr>
                <?php if ((float)$data['discount'] > 0): ?>
                <tr>
                    <td style="color: var(--text-muted);">Discount:</td>
                    <td style="text-align: right; color: #dc2626; font-weight: 700;">- <?= formatCurrency($data['discount']) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="summary-total-row">
                    <td>TOTAL PAYABLE:</td>
                    <td style="text-align: right;"><?= formatCurrency($totalPayable) ?></td>
                </tr>
                <tr>
                    <td style="color: var(--text-muted); padding-top: 10px;">Payment Mode:</td>
                    <td style="text-align: right; font-weight: 700; padding-top: 10px;"><?= $payType ?></td>
                </tr>
                <tr>
                    <td style="color: #059669;">Cash Received:</td>
                    <td style="text-align: right; color: #059669; font-weight: 700;"><?= formatCurrency($cashReceived) ?></td>
                </tr>
                <tr>
                    <td style="color: #d97706;">Credited Amount:</td>
                    <td style="text-align: right; color: #d97706; font-weight: 700;"><?= formatCurrency($creditAmount) ?></td>
                </tr>
                <tr class="summary-due-row">
                    <td>BALANCE / DUE:</td>
                    <td style="text-align: right;"><?= formatCurrency($dueAmount) ?></td>
                </tr>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer-note">
            <div style="font-weight: 700; color: #0f172a; margin-bottom: 2px;">
                <a class="website-link" href="https://liyasinternational.com/"> <?= htmlspecialchars($website) ?> &bull;</a> <?= htmlspecialchars($phone) ?>
            </div>
            <div>Thank you for doing business with Liyas International!</div>
        </div>
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

    <!-- Floating Toast Notification -->
    <div id="toastMsg" class="toast-msg">
        <i class='bx bx-check-circle' style="font-size: 18px; color: #34d399;"></i>
        <span id="toastText">Action completed</span>
    </div>

    <script>
        const BILL_NUMBER = <?= json_encode($billNumber) ?>;
        const WA_MESSAGE_TEXT = <?= json_encode($waMsg) ?>;
        const WA_SHARE_URL = <?= json_encode($waShareUrl) ?>;

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
    </script>

</body>
</html>

/**
 * Liyas Offline Delivery & Receipt Engine
 * Enables 100% offline delivery receipt creation, local thermal printing (Posiflow SC588 / RawBT / Browser Print), and auto-sync
 */

const LiyasOfflineEngine = (function () {
    const QUEUE_KEY = 'liyas_offline_receipt_queue';
    const CATALOG_KEY = 'liyas_offline_catalog';
    const ZONE_INFO_KEY = 'liyas_offline_zone_info';
    let isSyncing = false;
    let bleDevice = null;
    let bleCharacteristic = null;
    let activeOfflineRawText = '';
    let activeOfflineReceipt = null;

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // 1. Service Worker Registration
    function initServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('service-worker.js')
                .then(reg => {
                    console.log('⚡ Offline Service Worker active:', reg.scope);
                })
                .catch(err => {
                    console.warn('Service Worker registration skipped:', err);
                });
        }
    }

    // 2. Queue Operations (Local Storage)
    function getQueue() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function saveQueue(queue) {
        try {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
            updateOfflineUI();
        } catch (e) {
            console.error('Failed to save offline receipt queue:', e);
        }
    }

    function enqueueReceipt(receipt) {
        const queue = getQueue();
        queue.push(receipt);
        saveQueue(queue);
    }

    function removeReceipt(offlineId) {
        let queue = getQueue();
        queue = queue.filter(r => r.offline_id !== offlineId);
        saveQueue(queue);
    }

    // 3. Cache Context (called while online to save catalog & settings)
    function cacheContextData(catalog, zoneInfo) {
        try {
            if (catalog) localStorage.setItem(CATALOG_KEY, JSON.stringify(catalog));
            if (zoneInfo) localStorage.setItem(ZONE_INFO_KEY, JSON.stringify(zoneInfo));
        } catch (e) {}
    }

    function getCachedCatalog() {
        try {
            return JSON.parse(localStorage.getItem(CATALOG_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function getCachedZoneInfo() {
        try {
            return JSON.parse(localStorage.getItem(ZONE_INFO_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    // 4. ESC/POS 30-character Text Formatter for Posiflow SC588 (Pure Space Padding)
    function buildOfflineRawBtPayload(rec, width = 30) {
        const lines = [];
        const dividerDashed = '-'.repeat(width);
        const dividerDouble = '='.repeat(width);
        const dividerDotted = '- '.repeat(Math.ceil(width / 2)).substring(0, width);

        function center(text) {
            const str = String(text || '');
            if (str.length >= width) return str;
            const pad = Math.floor((width - str.length) / 2);
            return ' '.repeat(pad) + str;
        }

        function padLeft(str, len) {
            str = String(str || '');
            return str.length >= len ? str : ' '.repeat(len - str.length) + str;
        }

        function padRight(str, len) {
            str = String(str || '');
            return str.length >= len ? str : str + ' '.repeat(len - str.length);
        }

        const gstin = rec.company_gstin || '29AAOCP8622L1ZM';
        const phone = rec.company_phone || '+91 63663 78967';
        const website = rec.company_website || 'liyasinternational.com';

        // 1. Header
        lines.push(center("LIYAS INTERNATIONAL"));
        lines.push(center("Packaged Drinking Water"));
        lines.push(center("GSTIN: " + gstin));
        lines.push(dividerDashed);

        // 2. Bill Meta
        const billNum = rec.bill_number || ('#OFF-' + (rec.offline_id ? rec.offline_id.slice(-6) : '000000'));
        const dt = new Date(rec.created_at || Date.now());
        const dateStr = dt.toLocaleDateString('en-GB');
        const timeStr = dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

        lines.push(center(billNum));
        lines.push(center(dateStr + " / " + timeStr));
        lines.push(center("[OFFLINE DELIVERY RECEIPT]"));
        lines.push(dividerDashed);

        // 3. Customer Info
        lines.push("Billed To: " + (rec.shop_name || 'Valued Customer'));
        if (rec.customer_name && rec.customer_name !== rec.shop_name) {
            lines.push("Attn: " + rec.customer_name);
        }
        lines.push("Delivery Location: " + (rec.location || 'Mangalore'));
        lines.push("Phone: " + (rec.phone || 'N/A'));
        lines.push(dividerDashed);

        // 4. Products Table (30 cols: Item 11, Qty 4, Rate 7, Amt 8)
        const wItem = 11, wQty = 4, wRate = 7, wAmt = 8;
        const wLabel = 18, wVal = 12;

        const tableHeader = padRight("ITEM", wItem) + padLeft("QTY", wQty) + padLeft("RATE", wRate) + padLeft("AMT", wAmt);
        lines.push(tableHeader);
        lines.push(dividerDashed);

        const items = rec.items || [];
        items.forEach((oi, idx) => {
            const iName = (oi.product_name || oi.name || 'Liyas Water').trim();
            const iQty = parseInt(oi.quantity || 1, 10);
            const iRate = parseFloat(oi.rate || oi.price || 0);
            const iAmt = parseFloat(oi.line_total || (iQty * iRate));

            const rateStr = (iRate % 1 === 0) ? iRate.toFixed(0) : iRate.toFixed(2);
            const amtStr = (iAmt % 1 === 0) ? iAmt.toFixed(0) : iAmt.toFixed(2);

            const colQty = padLeft(iQty, wQty);
            const colRate = padLeft(rateStr, wRate);
            const colAmt = padLeft(amtStr, wAmt);

            if (iName.length <= wItem) {
                lines.push(padRight(iName, wItem) + colQty + colRate + colAmt);
            } else {
                lines.push(iName);
                lines.push(padRight("", wItem) + colQty + colRate + colAmt);
            }

            if (idx < items.length - 1) {
                lines.push(dividerDotted);
            }
        });
        lines.push(dividerDashed);

        // 5. Financial Summary Breakdown
        const subtotal = parseFloat(rec.total_amount || 0);
        const cash = parseFloat(rec.cash_amount || 0);
        const credit = parseFloat(rec.credited_amount || 0);
        const due = parseFloat(rec.due_amount || 0);

        lines.push(padRight("Subtotal:", wLabel) + padLeft("Rs. " + subtotal.toFixed(0), wVal));
        lines.push(dividerDouble);
        lines.push(padRight("TOTAL PAYABLE:", wLabel) + padLeft("Rs. " + subtotal.toFixed(0), wVal));
        lines.push(dividerDouble);

        lines.push(padRight("Cash", wLabel) + padLeft("Rs. " + cash.toFixed(0), wVal));
        if (credit > 0) {
            lines.push(padRight("Credited", wLabel) + padLeft("Rs. " + credit.toFixed(0), wVal));
        }

        lines.push(dividerDotted);
        lines.push(padRight("BALANCE / DUE:", wLabel) + padLeft("Rs. " + due.toFixed(0), wVal));
        lines.push(dividerDotted);

        // 6. Footer
        lines.push(dividerDashed);
        lines.push(center(website));
        lines.push(center("Ph: " + phone));
        lines.push(center("* * * * * THANK YOU * * * * *"));
        lines.push("\n\n\n\n");

        return lines.join("\n");
    }

    // 5. Mount Dedicated Thermal Printable Card for Browser Print (Posiflow SC588 58mm)
    function mountOfflinePrintableCard(rec) {
        if (!rec) return;

        let container = document.getElementById('liyasOfflinePrintContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'liyasOfflinePrintContainer';
            document.body.appendChild(container);
        }

        let styleEl = document.getElementById('liyasOfflinePrintStyles');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'liyasOfflinePrintStyles';
            styleEl.textContent = `
                @media screen {
                    #liyasOfflinePrintContainer {
                        display: none;
                    }
                }
                @media print {
                    @page {
                        size: 48mm auto;
                        margin: 0mm !important;
                    }
                    *, *:before, *:after {
                        box-sizing: border-box !important;
                        color: #000000 !important;
                        background: transparent !important;
                        box-shadow: none !important;
                        text-shadow: none !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                    }
                    html, body {
                        width: 45mm !important;
                        max-width: 45mm !important;
                        margin: 0 !important;
                        padding: 0 !important;
                        background: #ffffff !important;
                        color: #000000 !important;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace !important;
                        font-size: 8px !important;
                        line-height: 1.15 !important;
                    }
                    body > *:not(#liyasOfflinePrintContainer) {
                        display: none !important;
                    }
                    #liyasOfflineReceiptModal, #liyasOfflineQueueModal, #liyasOfflineStatusBar, #liyasOfflineToast {
                        display: none !important;
                    }
                    #liyasOfflinePrintContainer {
                        display: block !important;
                        position: absolute !important;
                        left: 0 !important;
                        top: 0 !important;
                        width: 45mm !important;
                        padding: 1mm 1.5mm 8mm 1mm !important;
                        margin: 0 !important;
                    }
                    .off-print-table {
                        width: 100% !important;
                        border-collapse: collapse !important;
                        margin: 4px 0 !important;
                        font-size: 8px !important;
                    }
                    .off-print-table th, .off-print-table td {
                        padding: 2px 0 !important;
                        border-bottom: 1px dashed #000 !important;
                    }
                    .off-print-header {
                        text-align: center !important;
                        border-bottom: 1px dashed #000 !important;
                        padding-bottom: 4px !important;
                        margin-bottom: 4px !important;
                    }
                    .off-print-footer {
                        text-align: center !important;
                        border-top: 1px dashed #000 !important;
                        margin-top: 5px !important;
                        padding-top: 4px !important;
                        font-size: 7.5px !important;
                    }
                }
            `;
            document.head.appendChild(styleEl);
        }

        const dt = new Date(rec.created_at || Date.now());
        const dateStr = dt.toLocaleDateString('en-GB');
        const timeStr = dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

        const itemsHtml = (rec.items || []).map(it => `
            <tr>
                <td style="text-align:left; font-weight:700;">${escapeHtml(it.product_name || 'Liyas Water')}</td>
                <td style="text-align:center;">${it.quantity}</td>
                <td style="text-align:right;">₹${parseFloat(it.rate || 0).toFixed(0)}</td>
                <td style="text-align:right; font-weight:700;">₹${parseFloat(it.line_total || (it.quantity * it.rate)).toFixed(0)}</td>
            </tr>
        `).join('');

        const subtotal = parseFloat(rec.total_amount || 0);
        const cash = parseFloat(rec.cash_amount || 0);
        const credit = parseFloat(rec.credited_amount || 0);
        const due = parseFloat(rec.due_amount || 0);

        container.innerHTML = `
            <div class="off-print-header">
                <div style="font-size: 11px; font-weight: 900; text-transform: uppercase;">LIYAS INTERNATIONAL</div>
                <div style="font-size: 8px; text-transform: uppercase;">Packaged Drinking Water</div>
                <div style="font-size: 8px;">GSTIN: ${escapeHtml(rec.company_gstin || '29AAOCP8622L1ZM')}</div>
                <div style="font-size: 8px; font-weight: 700; margin-top: 2px;">[OFFLINE DELIVERY RECEIPT]</div>
                <div style="font-size: 9px; font-weight: 800; font-family: monospace; margin-top: 2px;">${escapeHtml(rec.bill_number)}</div>
                <div style="font-size: 8px; color: #444;">${dateStr} &bull; ${timeStr}</div>
            </div>

            <div style="font-size: 8px; margin-bottom: 4px; line-height: 1.25;">
                <div><strong>Billed To:</strong> ${escapeHtml(rec.shop_name)}</div>
                ${rec.customer_name && rec.customer_name !== rec.shop_name ? `<div><strong>Attn:</strong> ${escapeHtml(rec.customer_name)}</div>` : ''}
                <div><strong>Location:</strong> ${escapeHtml(rec.location || 'Mangalore')}</div>
                <div><strong>Phone:</strong> ${escapeHtml(rec.phone || 'N/A')}</div>
            </div>

            <table class="off-print-table">
                <thead>
                    <tr style="border-bottom: 1px solid #000;">
                        <th style="text-align:left;">Item</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Rate</th>
                        <th style="text-align:right;">Amt</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>

            <div style="font-size: 8px; line-height: 1.35; margin-top: 4px;">
                <div style="display:flex; justify-content:space-between;">
                    <span>Subtotal:</span>
                    <span>₹${subtotal.toFixed(0)}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-weight:900; font-size: 9px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 2px 0; margin: 2px 0;">
                    <span>TOTAL PAYABLE:</span>
                    <span>₹${subtotal.toFixed(0)}</span>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span>Cash Collected:</span>
                    <span>₹${cash.toFixed(0)}</span>
                </div>
                ${credit > 0 ? `
                <div style="display:flex; justify-content:space-between;">
                    <span>Credited:</span>
                    <span>₹${credit.toFixed(0)}</span>
                </div>` : ''}
                <div style="display:flex; justify-content:space-between; font-weight:800; border-top: 1px dashed #000; padding-top: 2px; margin-top: 2px;">
                    <span>BALANCE / DUE:</span>
                    <span>₹${due.toFixed(0)}</span>
                </div>
            </div>

            <div class="off-print-footer">
                <div>${escapeHtml(rec.company_website || 'liyasinternational.com')}</div>
                <div>Ph: ${escapeHtml(rec.company_phone || '+91 63663 78967')}</div>
                <div style="font-weight: 700; margin-top: 3px;">* * * THANK YOU * * *</div>
            </div>
        `;
    }

    // 6. Thermal Browser Print Trigger
    function printOfflineBrowser(rec) {
        rec = rec || activeOfflineReceipt;
        if (rec) {
            mountOfflinePrintableCard(rec);
        }
        window.print();
        return true;
    }

    // 7. Android RawBT Intent Trigger
    function printViaRawBT(rawText) {
        rawText = rawText || activeOfflineRawText;
        if (!rawText && activeOfflineReceipt) {
            rawText = buildOfflineRawBtPayload(activeOfflineReceipt, 30);
        }
        if (!rawText) {
            showOfflineToast("No receipt data to print");
            return false;
        }

        const isAndroid = /Android/i.test(navigator.userAgent);
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

        if (isAndroid) {
            try {
                showOfflineToast('⚡ Sending to RawBT thermal printer...', 2500);
                const encoded = btoa(unescape(encodeURIComponent(rawText)));
                const intentUrl = "intent:base64," + encoded + "#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dru.a402d.rawbtprinter;end;";
                window.location.href = intentUrl;
                return true;
            } catch (err) {
                console.warn('RawBT intent error, falling back to browser print:', err);
                return printOfflineBrowser(activeOfflineReceipt);
            }
        }

        if (isIOS) {
            window.location.href = "thermalprint://print?text=" + encodeURIComponent(rawText);
            return true;
        }

        // Desktop / Other browsers -> Instant Thermal Browser Print
        return printOfflineBrowser(activeOfflineReceipt);
    }

    // 8. Direct Web Bluetooth Print for Posiflow SC588
    async function printViaBluetooth(rawText) {
        rawText = rawText || activeOfflineRawText;
        if (!rawText && activeOfflineReceipt) {
            rawText = buildOfflineRawBtPayload(activeOfflineReceipt, 30);
        }
        if (!rawText) {
            showOfflineToast("No receipt data to print");
            return false;
        }

        if (!navigator.bluetooth) {
            alert("Direct Web Bluetooth is not supported on this browser.\n\nPlease use 'Quick Print (RawBT)' or 'Thermal / Browser Print'.");
            return false;
        }

        try {
            showOfflineToast('⚡ Connecting to Bluetooth printer...', 3000);
            if (!bleCharacteristic || !bleDevice || !bleDevice.gatt || !bleDevice.gatt.connected) {
                const device = await navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: [
                        '000018f0-0000-1000-8000-00805f9b34fb',
                        '49535343-fe7d-4ae5-8fa9-9fafd205e455',
                        'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
                        '0000ff00-0000-1000-8000-00805f9b34fb',
                        '0000e0ff-0000-1000-8000-00805f9b34fb',
                        '0000ae00-0000-1000-8000-00805f9b34fb',
                        '0000fee7-0000-1000-8000-00805f9b34fb',
                        '0000fff0-0000-1000-8000-00805f9b34fb'
                    ]
                });
                bleDevice = device;
                bleDevice.addEventListener('gattserverdisconnected', () => {
                    bleCharacteristic = null;
                });
                const server = await device.gatt.connect();
                const services = await server.getPrimaryServices();
                bleCharacteristic = null;
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
                throw new Error("Could not detect writable printer characteristic.");
            }

            showOfflineToast('⚡ Printing receipt via Bluetooth...', 2500);
            const escInit = '\x1b@';
            const formattedText = escInit + rawText.replace(/\r?\n/g, '\r\n') + '\r\n\r\n\r\n';
            const encoder = new TextEncoder();
            const data = encoder.encode(formattedText);

            const chunkSize = 64;
            for (let i = 0; i < data.length; i += chunkSize) {
                const chunk = data.slice(i, i + chunkSize);
                if (bleCharacteristic.writeValueWithoutResponse) {
                    await bleCharacteristic.writeValueWithoutResponse(chunk);
                } else {
                    await bleCharacteristic.writeValue(chunk);
                }
                await new Promise(r => setTimeout(r, 25));
            }
            showOfflineToast('⚡ Receipt printed successfully!');
            return true;
        } catch (err) {
            console.warn('Bluetooth print error:', err);
            bleCharacteristic = null;
            if (err.name !== 'NotFoundError') {
                showOfflineToast('Bluetooth: ' + (err.message || 'Connection failed'), 4000);
            }
            return false;
        }
    }

    // 9. Send Receipt to WhatsApp
    function shareOfflineWhatsApp(rec, rawText) {
        rec = rec || activeOfflineReceipt;
        if (!rec) return;

        const phone = (rec.phone || '').replace(/[^0-9]/g, '');
        const itemsSummary = (rec.items || []).map(i => `• ${i.product_name} × ${i.quantity} cs = Rs. ${i.line_total}`).join('\n');
        const text = `*LIYAS INTERNATIONAL*\n*DELIVERY RECEIPT: ${rec.bill_number}*\n\n*Billed To:* ${rec.shop_name}\n*Location:* ${rec.location || 'Mangalore'}\n*Date:* ${rec.created_at || 'Today'}\n\n*Delivered Items:*\n${itemsSummary}\n\n*Total Payable:* Rs. ${parseFloat(rec.total_amount || 0).toFixed(0)}\n*Cash Paid:* Rs. ${parseFloat(rec.cash_amount || 0).toFixed(0)}\n*Balance Due:* Rs. ${parseFloat(rec.due_amount || 0).toFixed(0)}\n\nThank you for choosing Liyas Water!`;

        const waUrl = phone ? `https://api.whatsapp.com/send?phone=${phone.length === 10 ? '91' + phone : phone}&text=${encodeURIComponent(text)}` : `https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`;
        window.open(waUrl, '_blank');
    }

    // 10. Intelligent 1-Click Print Router (Auto-selects optimal device printer)
    function printOfflineAuto(rec, rawText) {
        rec = rec || activeOfflineReceipt;
        rawText = rawText || activeOfflineRawText;

        const isAndroid = /Android/i.test(navigator.userAgent);
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

        if (isAndroid) {
            return printViaRawBT(rawText);
        } else if (isIOS && navigator.bluetooth) {
            return printViaBluetooth(rawText);
        } else {
            // Desktop / Laptop / AirPrint: Direct Thermal Print dialog
            return printOfflineBrowser(rec);
        }
    }

    // 11. Show Offline Receipt Modal with Visual Paper Preview & Full Controls
    function showOfflineReceiptModal(rec, rawText) {
        activeOfflineReceipt = rec;
        activeOfflineRawText = rawText || buildOfflineRawBtPayload(rec, 30);

        // Pre-mount the hidden printable container for immediate print readiness
        mountOfflinePrintableCard(rec);

        let modal = document.getElementById('liyasOfflineReceiptModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'liyasOfflineReceiptModal';
            modal.style.cssText = `
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(15, 23, 42, 0.75);
                backdrop-filter: blur(4px);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            `;
            document.body.appendChild(modal);
        }

        const dt = new Date(rec.created_at || Date.now());
        const dateStr = dt.toLocaleDateString('en-GB');
        const timeStr = dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

        const previewItemsHtml = (rec.items || []).map(it => `
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; padding: 3px 0; border-bottom: 1px dotted #e2e8f0; font-size: 11px;">
                <span style="font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(it.product_name || 'Liyas Water')}</span>
                <span style="text-align: center; color: #475569;">${it.quantity} cs</span>
                <span style="text-align: right; color: #475569;">₹${parseFloat(it.rate || 0).toFixed(0)}</span>
                <span style="text-align: right; font-weight: 700; color: #0f172a;">₹${parseFloat(it.line_total || (it.quantity * it.rate)).toFixed(0)}</span>
            </div>
        `).join('');

        const subtotal = parseFloat(rec.total_amount || 0);
        const cash = parseFloat(rec.cash_amount || 0);
        const credit = parseFloat(rec.credited_amount || 0);
        const due = parseFloat(rec.due_amount || 0);

        modal.innerHTML = `
            <div style="background: #ffffff; border-radius: 20px; max-width: 440px; width: 100%; max-height: 92vh; overflow-y: auto; padding: 22px; box-shadow: 0 20px 45px rgba(0,0,0,0.3); text-align: center; position: relative;">
                <button onclick="document.getElementById('liyasOfflineReceiptModal').style.display='none'" style="position: absolute; right: 14px; top: 14px; background: none; border: none; font-size: 24px; color: #94a3b8; cursor: pointer;" aria-label="Close">&times;</button>

                <div style="display: inline-flex; align-items: center; gap: 6px; background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; margin-bottom: 6px;">
                    <i class='bx bxs-check-circle' style="font-size: 16px;"></i> Delivery Saved Offline
                </div>
                <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 2px 0;">Receipt Ready to Print</h3>
                <p style="font-size: 11px; color: #64748b; margin: 0 0 14px 0;">Print for customer &bull; Stored locally for auto-sync</p>

                <!-- Thermal Paper Simulation Card -->
                <div style="background: #fffdf5; border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 14px; text-align: left; font-family: monospace, sans-serif; font-size: 11px; color: #1e293b; box-shadow: inset 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 16px;">
                    <div style="text-align: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 8px; margin-bottom: 8px;">
                        <div style="font-weight: 900; font-size: 13px; color: #0f172a;">LIYAS INTERNATIONAL</div>
                        <div style="font-size: 9.5px; color: #64748b;">Packaged Drinking Water &bull; GSTIN: ${escapeHtml(rec.company_gstin || '29AAOCP8622L1ZM')}</div>
                        <div style="font-weight: 800; font-size: 12px; color: #2563eb; margin-top: 4px;">${escapeHtml(rec.bill_number)}</div>
                        <div style="font-size: 9.5px; color: #64748b;">${dateStr} &bull; ${timeStr}</div>
                    </div>

                    <div style="margin-bottom: 8px; line-height: 1.4; font-size: 11px;">
                        <div><strong>Billed To:</strong> ${escapeHtml(rec.shop_name)}</div>
                        <div><strong>Place:</strong> ${escapeHtml(rec.location || 'Mangalore')} | <strong>Ph:</strong> ${escapeHtml(rec.phone || 'N/A')}</div>
                    </div>

                    <div style="border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 4px 0; margin-bottom: 8px;">
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; font-weight: 800; color: #475569; font-size: 10px; margin-bottom: 4px;">
                            <span>ITEM</span><span style="text-align:center;">QTY</span><span style="text-align:right;">RATE</span><span style="text-align:right;">AMT</span>
                        </div>
                        ${previewItemsHtml}
                    </div>

                    <div style="line-height: 1.5; font-size: 11px;">
                        <div style="display:flex; justify-content:space-between; font-weight: 800; font-size: 13px; color: #0f172a; border-bottom: 1px dashed #cbd5e1; padding-bottom: 4px; margin-bottom: 4px;">
                            <span>TOTAL PAYABLE:</span>
                            <span style="color: #2563eb;">₹${subtotal.toFixed(0)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; color: #059669;">
                            <span>Cash Paid:</span>
                            <span style="font-weight: 700;">₹${cash.toFixed(0)}</span>
                        </div>
                        ${credit > 0 ? `
                        <div style="display:flex; justify-content:space-between; color: #d97706;">
                            <span>Credited:</span>
                            <span style="font-weight: 700;">₹${credit.toFixed(0)}</span>
                        </div>` : ''}
                        <div style="display:flex; justify-content:space-between; color: #dc2626; font-weight: 800; margin-top: 2px;">
                            <span>BALANCE DUE:</span>
                            <span>₹${due.toFixed(0)}</span>
                        </div>
                    </div>
                </div>

                <!-- Print Options Grid -->
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <button id="offModalPrimaryPrintBtn" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; background: #0f172a; color: #ffffff; border: none; padding: 13px; border-radius: 12px; font-size: 14px; font-weight: 800; cursor: pointer; box-shadow: 0 4px 12px rgba(15,23,42,0.25);">
                        <i class='bx bxs-printer' style="color: #38bdf8; font-size: 20px;"></i>
                        <span>🖨️ Print Receipt (Posiflow / 58mm)</span>
                    </button>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button id="offModalRawBtBtn" style="display: flex; align-items: center; justify-content: center; gap: 6px; background: #2563eb; color: #ffffff; border: none; padding: 10px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer;">
                            <i class='bx bxs-zap' style="color: #facc15; font-size: 16px;"></i>
                            <span>RawBT (Android)</span>
                        </button>

                        <button id="offModalBtBtn" style="display: flex; align-items: center; justify-content: center; gap: 6px; background: #f8fafc; color: #1e293b; border: 1.5px solid #cbd5e1; padding: 10px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer;">
                            <i class='bx bx-bluetooth' style="color: #2563eb; font-size: 16px;"></i>
                            <span>Bluetooth</span>
                        </button>
                    </div>

                    <button id="offModalWaBtn" style="display: flex; align-items: center; justify-content: center; gap: 6px; background: #dcfce7; color: #15803d; border: 1.5px solid #86efac; padding: 10px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class='bx bxl-whatsapp' style="font-size: 18px; color: #16a34a;"></i>
                        <span>Share on WhatsApp</span>
                    </button>

                    <button id="offModalDoneBtn" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 10px; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; margin-top: 4px;">
                        Done / Next Delivery
                    </button>
                </div>
            </div>
        `;

        modal.style.display = 'flex';

        // Event Listeners for the modal buttons
        document.getElementById('offModalPrimaryPrintBtn')?.addEventListener('click', () => {
            printOfflineAuto(rec, rawText);
        });

        document.getElementById('offModalRawBtBtn')?.addEventListener('click', () => {
            printViaRawBT(rawText);
        });

        document.getElementById('offModalBtBtn')?.addEventListener('click', () => {
            printViaBluetooth(rawText);
        });

        document.getElementById('offModalWaBtn')?.addEventListener('click', () => {
            shareOfflineWhatsApp(rec, rawText);
        });

        document.getElementById('offModalDoneBtn')?.addEventListener('click', () => {
            modal.style.display = 'none';
            window.location.reload();
        });
    }

    // 12. Show Offline Saved Receipts Drawer/Modal (Allows re-printing any offline receipt anytime!)
    function showOfflineQueueModal() {
        const queue = getQueue();
        let modal = document.getElementById('liyasOfflineQueueModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'liyasOfflineQueueModal';
            modal.style.cssText = `
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(15, 23, 42, 0.75);
                backdrop-filter: blur(4px);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            `;
            document.body.appendChild(modal);
        }

        if (queue.length === 0) {
            modal.innerHTML = `
                <div style="background: #ffffff; border-radius: 16px; max-width: 380px; width: 100%; padding: 24px; text-align: center;">
                    <i class='bx bx-check-double' style="font-size: 40px; color: #16a34a; margin-bottom: 8px;"></i>
                    <h4 style="margin: 0 0 6px 0; font-size: 16px; color: #0f172a;">All Deliveries Synchronized</h4>
                    <p style="font-size: 12px; color: #64748b; margin: 0 0 16px 0;">There are no unsaved offline receipts waiting to sync.</p>
                    <button onclick="document.getElementById('liyasOfflineQueueModal').style.display='none'" style="background: #0f172a; color: #fff; border: none; padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;">Close</button>
                </div>
            `;
            modal.style.display = 'flex';
            return;
        }

        const cardsHtml = queue.map((rec, idx) => {
            const billNum = escapeHtml(rec.bill_number || ('#OFF-' + (rec.offline_id ? rec.offline_id.slice(-6) : '000000')));
            const shopName = escapeHtml(rec.shop_name || 'Customer');
            const total = parseFloat(rec.total_amount || 0).toFixed(0);
            const due = parseFloat(rec.due_amount || 0).toFixed(0);
            return `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <span style="font-family: monospace; font-weight: 800; color: #2563eb; font-size: 13px;">${billNum}</span>
                        <span style="font-weight: 800; font-size: 13px; color: #0f172a;">₹${total}</span>
                    </div>
                    <div style="font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 2px;">${shopName}</div>
                    <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>${escapeHtml(rec.created_at || 'Just now')}</span>
                        <span style="color: ${due > 0 ? '#dc2626' : '#059669'}; font-weight: 700;">${due > 0 ? 'Due: ₹' + due : 'Paid'}</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="LiyasOfflineEngine.reprintOfflineBill('${rec.offline_id}')" style="flex: 1; background: #0f172a; color: #fff; border: none; padding: 7px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px;">
                            <i class='bx bx-printer'></i> Print Receipt
                        </button>
                        <button onclick="LiyasOfflineEngine.shareOfflineWhatsAppById('${rec.offline_id}')" style="background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 7px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                            <i class='bx bxl-whatsapp'></i>
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        modal.innerHTML = `
            <div style="background: #ffffff; border-radius: 20px; max-width: 440px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 20px; text-align: center; position: relative;">
                <button onclick="document.getElementById('liyasOfflineQueueModal').style.display='none'" style="position: absolute; right: 14px; top: 14px; background: none; border: none; font-size: 24px; color: #94a3b8; cursor: pointer;">&times;</button>
                <div style="display: flex; align-items: center; gap: 8px; justify-content: center; margin-bottom: 6px;">
                    <i class='bx bx-receipt' style="font-size: 22px; color: #2563eb;"></i>
                    <h3 style="margin: 0; font-size: 17px; color: #0f172a;">Offline Deliveries (${queue.length})</h3>
                </div>
                <p style="font-size: 11px; color: #64748b; margin: 0 0 14px 0;">Tap Print on any bill to re-print to your thermal printer</p>
                <div style="max-height: 55vh; overflow-y: auto;">
                    ${cardsHtml}
                </div>
                <div style="display: flex; gap: 8px; margin-top: 14px;">
                    ${navigator.onLine ? `<button onclick="LiyasOfflineEngine.syncOfflineReceipts(); document.getElementById('liyasOfflineQueueModal').style.display='none';" style="flex: 1; background: #2563eb; color: #fff; border: none; padding: 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer;">⚡ Sync Now to Server</button>` : ''}
                    <button onclick="document.getElementById('liyasOfflineQueueModal').style.display='none'" style="flex: 1; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer;">Close</button>
                </div>
            </div>
        `;

        modal.style.display = 'flex';
    }

    function reprintOfflineBill(offlineId) {
        const queue = getQueue();
        const rec = queue.find(r => r.offline_id === offlineId);
        if (!rec) return;
        const rawBtText = buildOfflineRawBtPayload(rec, 30);
        const qModal = document.getElementById('liyasOfflineQueueModal');
        if (qModal) qModal.style.display = 'none';
        showOfflineReceiptModal(rec, rawBtText);
    }

    function shareOfflineWhatsAppById(offlineId) {
        const queue = getQueue();
        const rec = queue.find(r => r.offline_id === offlineId);
        if (!rec) return;
        shareOfflineWhatsApp(rec);
    }

    // 13. Automatic Background Synchronization
    async function syncOfflineReceipts() {
        if (isSyncing || !navigator.onLine) return;
        const queue = getQueue();
        if (queue.length === 0) return;

        isSyncing = true;
        updateOfflineUI(true);

        try {
            console.log(`⚡ Syncing ${queue.length} offline receipts to server...`);
            const response = await fetch('api_sync_offline_receipts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ receipts: queue })
            });

            if (!response.ok) {
                throw new Error(`Sync server responded with ${response.status}`);
            }

            const data = await response.json();
            if (data && data.success && Array.isArray(data.results)) {
                let syncedCount = 0;
                data.results.forEach(res => {
                    if (res.status === 'synced' || res.status === 'already_synced') {
                        removeReceipt(res.offline_id);
                        syncedCount++;
                    }
                });

                if (syncedCount > 0) {
                    showOfflineToast(`✅ Synchronized ${syncedCount} offline delivery receipts to server!`, 4000);
                }
            }
        } catch (err) {
            console.warn('Sync failed (will auto-retry when connection stabilises):', err);
        } finally {
            isSyncing = false;
            updateOfflineUI(false);
        }
    }

    // 14. UI Status Banner & Pill (Clicking opens the offline bills viewer)
    function updateOfflineUI(syncInProgress = false) {
        const queue = getQueue();
        const isOnline = navigator.onLine;

        let banner = document.getElementById('liyasOfflineStatusBar');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'liyasOfflineStatusBar';
            banner.style.cssText = `
                position: fixed;
                bottom: 16px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 99999;
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                border-radius: 30px;
                font-family: inherit;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
                box-shadow: 0 10px 25px -5px rgba(0,0,0,0.25);
                transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            `;
            banner.title = "Click to view and print offline delivery receipts";
            banner.addEventListener('click', (e) => {
                if (e.target.tagName !== 'BUTTON') {
                    showOfflineQueueModal();
                }
            });
            document.body.appendChild(banner);
        }

        if (!isOnline) {
            banner.style.display = 'flex';
            banner.style.background = '#0f172a';
            banner.style.color = '#fef08a';
            banner.style.border = '1.5px solid #ca8a04';
            banner.innerHTML = `
                <i class='bx bx-wifi-off' style="font-size: 16px; color: #facc15;"></i>
                <span>Offline Mode</span>
                ${queue.length > 0 ? `<span style="background: #eab308; color: #000; padding: 1px 7px; border-radius: 12px; font-size: 11px; font-weight:800;">${queue.length} Bills (Tap to Print)</span>` : ''}
            `;
        } else if (queue.length > 0) {
            banner.style.display = 'flex';
            banner.style.background = '#1e293b';
            banner.style.color = '#ffffff';
            banner.style.border = '1.5px solid #3b82f6';
            banner.innerHTML = `
                <i class='bx ${syncInProgress ? 'bx-loader-alt bx-spin' : 'bx-cloud-upload'}' style="font-size: 18px; color: #60a5fa;"></i>
                <span>${syncInProgress ? 'Syncing...' : `${queue.length} Offline Bills`}</span>
                <button onclick="event.stopPropagation(); LiyasOfflineEngine.showOfflineQueueModal()" style="background: #334155; color: #fff; border: 1px solid #475569; padding: 2px 7px; border-radius: 5px; font-size: 11px; font-weight: 700; cursor: pointer;">Print</button>
                ${!syncInProgress ? `<button onclick="event.stopPropagation(); LiyasOfflineEngine.syncOfflineReceipts()" style="background: #2563eb; color: #fff; border: none; padding: 2px 8px; border-radius: 5px; font-size: 11px; font-weight: 700; cursor: pointer;">Sync</button>` : ''}
            `;
        } else {
            banner.style.display = 'none';
        }
    }

    function showOfflineToast(message, duration = 3000) {
        let toast = document.getElementById('liyasOfflineToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'liyasOfflineToast';
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #0f172a;
                color: #ffffff;
                padding: 10px 20px;
                border-radius: 30px;
                font-size: 13px;
                font-weight: 700;
                z-index: 100000;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                display: none;
                transition: opacity 0.3s ease;
            `;
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.style.display = 'block';
        toast.style.opacity = '1';
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { toast.style.display = 'none'; }, 300);
        }, duration);
    }

    // 15. Initialize Listeners
    function init() {
        initServiceWorker();
        window.addEventListener('online', () => {
            updateOfflineUI();
            showOfflineToast('⚡ Internet Restored! Synchronizing deliveries...');
            syncOfflineReceipts();
        });
        window.addEventListener('offline', () => {
            updateOfflineUI();
            showOfflineToast('⚠️ Offline Mode: Deliveries will print directly & save locally.');
        });
        updateOfflineUI();
        if (navigator.onLine && getQueue().length > 0) {
            setTimeout(syncOfflineReceipts, 1500);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        enqueueReceipt,
        getQueue,
        removeReceipt,
        cacheContextData,
        getCachedCatalog,
        getCachedZoneInfo,
        buildOfflineRawBtPayload,
        mountOfflinePrintableCard,
        printOfflineAuto,
        printOfflineBrowser,
        printViaRawBT,
        printViaBluetooth,
        shareOfflineWhatsApp,
        showOfflineReceiptModal,
        showOfflineQueueModal,
        reprintOfflineBill,
        shareOfflineWhatsAppById,
        syncOfflineReceipts,
        updateOfflineUI,
        showOfflineToast
    };
})();

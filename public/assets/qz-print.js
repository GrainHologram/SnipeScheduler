/**
 * qz-print.js
 * Client-side QZ Tray integration for receipt printing.
 * IIFE module exposing window.SnipePrint.
 *
 * Builds raw ESC/POS commands directly — no external encoder library needed.
 *
 * Supports two connection modes:
 *   - 'usb': direct raw USB printing via qz.usb.* API (vendor/product/interface/endpoint)
 *   - 'printer_name': uses the OS printer driver via qz.print() API
 */
var SnipePrint = (function () {
    'use strict';

    var _cfg = {
        connectionType: 'usb',
        printerName: '',
        usbVendorId: '',
        usbProductId: '',
        usbInterface: '0x00',
        usbEndpoint: '0x01',
        certUrl: 'ajax_qz_cert.php',
        paperWidth: 48
    };
    var _connected = false;

    // ESC/POS command constants (hex)
    var ESC = '\x1B';
    var GS  = '\x1D';
    var CMD = {
        INIT:       ESC + '@',            // Initialize printer
        BOLD_ON:    ESC + 'E' + '\x01',   // Bold on
        BOLD_OFF:   ESC + 'E' + '\x00',   // Bold off
        ALIGN_LEFT: ESC + 'a' + '\x00',   // Left align
        ALIGN_CTR:  ESC + 'a' + '\x01',   // Center align
        CUT:        GS  + 'V' + '\x41' + '\x03', // Partial cut with feed
        LF:         '\x0A'               // Line feed
    };

    function init(cfg) {
        if (cfg.connectionType) _cfg.connectionType = cfg.connectionType;
        if (cfg.printerName) _cfg.printerName = cfg.printerName;
        if (cfg.usbVendorId) _cfg.usbVendorId = cfg.usbVendorId;
        if (cfg.usbProductId) _cfg.usbProductId = cfg.usbProductId;
        if (cfg.usbInterface) _cfg.usbInterface = cfg.usbInterface;
        if (cfg.usbEndpoint) _cfg.usbEndpoint = cfg.usbEndpoint;
        if (cfg.certUrl) _cfg.certUrl = cfg.certUrl;
        if (cfg.paperWidth) _cfg.paperWidth = parseInt(cfg.paperWidth, 10) || 48;
    }

    function connect() {
        if (_connected && qz.websocket.isActive()) {
            return Promise.resolve();
        }

        qz.security.setCertificatePromise(function (resolve) {
            fetch(_cfg.certUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(resolve)
                .catch(function () { resolve(''); });
        });

        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(function (toSign) {
            return function (resolve, reject) {
                fetch('ajax_qz_sign.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ request: toSign })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.signature) {
                        resolve(data.signature);
                    } else {
                        reject(data.error || 'Signing failed');
                    }
                })
                .catch(reject);
            };
        });

        return qz.websocket.connect()
            .then(function () { _connected = true; })
            .catch(function (err) {
                _connected = false;
                var msg = (err && err.message) ? err.message : String(err);
                if (msg.indexOf('Unable to connect') !== -1 || msg.indexOf('CLOSE_EVENT') !== -1) {
                    throw new Error('Printer service not detected. Is QZ Tray running?');
                }
                throw err;
            });
    }

    function printerLabel() {
        if (_cfg.connectionType === 'usb' && _cfg.usbVendorId && _cfg.usbProductId) {
            return 'USB ' + _cfg.usbVendorId + ':' + _cfg.usbProductId;
        }
        return _cfg.printerName || '(default)';
    }

    /**
     * Build a divider line of the configured width.
     */
    function divider() {
        var s = '';
        for (var i = 0; i < _cfg.paperWidth; i++) s += '-';
        return s;
    }

    /**
     * Truncate a string to fit the paper width.
     */
    function truncate(s) {
        return s.length > _cfg.paperWidth ? s.substring(0, _cfg.paperWidth) : s;
    }

    /**
     * Build raw ESC/POS data as a single concatenated string.
     */
    function buildReceiptData(data, title) {
        var d = divider();
        var parts = [];

        parts.push(CMD.INIT);
        parts.push(CMD.ALIGN_CTR);
        parts.push(CMD.BOLD_ON);
        parts.push((data.app_name || 'SnipeScheduler') + CMD.LF);
        parts.push(title + CMD.LF);
        parts.push(CMD.BOLD_OFF);
        parts.push(CMD.ALIGN_LEFT);
        parts.push(d + CMD.LF);
        parts.push('Checkout #' + data.checkout_id + CMD.LF);
        parts.push('User: ' + (data.user_name || data.user_email || 'Unknown') + CMD.LF);
        parts.push('Date: ' + data.start_datetime + CMD.LF);
        parts.push('Return by: ' + data.end_datetime + CMD.LF);
        parts.push(d + CMD.LF);
        parts.push(CMD.BOLD_ON);
        parts.push('ITEMS' + CMD.LF);
        parts.push(CMD.BOLD_OFF);

        var items = data.items || [];
        for (var j = 0; j < items.length; j++) {
            var item = items[j];
            var tag = item.asset_tag || '';
            var model = item.model_name || '';
            parts.push(truncate(tag + ' - ' + model) + CMD.LF);
        }

        parts.push(d + CMD.LF);
        parts.push('Total items: ' + items.length + CMD.LF);
        parts.push(CMD.LF);
        parts.push('Staff signature: _______________' + CMD.LF);
        parts.push('User signature:  _______________' + CMD.LF);
        parts.push(CMD.LF);
        parts.push(CMD.CUT);

        return parts;
    }

    /**
     * Send ESC/POS data to the printer.
     *
     * USB mode: claim device → send raw data → release device (qz.usb.* API)
     * Printer name mode: create config → qz.print() (OS driver API)
     */
    function sendToPrinter(parts) {
        if (_cfg.connectionType === 'usb' && _cfg.usbVendorId && _cfg.usbProductId) {
            var vendorId  = _cfg.usbVendorId;
            var productId = _cfg.usbProductId;
            var iface     = _cfg.usbInterface || '0x00';
            var endpoint  = _cfg.usbEndpoint  || '0x01';

            // Concatenate all parts into one payload for sendData
            var payload = parts.join('');

            return qz.usb.claimDevice(vendorId, productId, iface)
                .then(function () {
                    return qz.usb.sendData(vendorId, productId, endpoint, payload);
                })
                .then(function () {
                    return qz.usb.releaseDevice(vendorId, productId);
                })
                .catch(function (err) {
                    // Always try to release on error
                    return qz.usb.releaseDevice(vendorId, productId)
                        .catch(function () { /* ignore release error */ })
                        .then(function () { throw err; });
                });
        }

        // Printer name mode: use qz.print() with OS driver
        var config = qz.configs.create(_cfg.printerName, { encoding: 'UTF-8' });
        return qz.print(config, parts);
    }

    function printReceipt(checkoutId, title) {
        return connect().then(function () {
            return fetch('ajax_checkout_receipt.php?checkout_id=' + encodeURIComponent(checkoutId), {
                credentials: 'same-origin'
            });
        }).then(function (r) {
            if (!r.ok) throw new Error('Failed to load checkout data (HTTP ' + r.status + ')');
            return r.json();
        }).then(function (data) {
            if (data.error) throw new Error(data.error);
            var parts = buildReceiptData(data, title);
            return sendToPrinter(parts);
        });
    }

    function printPickSheet(checkoutId) {
        return printReceipt(checkoutId, 'PICK SHEET');
    }

    function printCheckoutReceipt(checkoutId) {
        return printReceipt(checkoutId, 'CHECKOUT');
    }

    function printTest() {
        return connect().then(function () {
            var d = divider();
            var parts = [];

            parts.push(CMD.INIT);
            parts.push(CMD.ALIGN_CTR);
            parts.push(CMD.BOLD_ON);
            parts.push('QZ TRAY TEST' + CMD.LF);
            parts.push(CMD.BOLD_OFF);
            parts.push(CMD.ALIGN_LEFT);
            parts.push(d + CMD.LF);
            parts.push('Printer: ' + printerLabel() + CMD.LF);
            parts.push('Mode: ' + _cfg.connectionType + CMD.LF);
            parts.push('Paper width: ' + _cfg.paperWidth + ' chars' + CMD.LF);
            parts.push('Time: ' + new Date().toLocaleString() + CMD.LF);
            parts.push(d + CMD.LF);
            parts.push('If you can read this, printing works!' + CMD.LF);
            parts.push(CMD.LF);
            parts.push(CMD.CUT);

            return sendToPrinter(parts);
        });
    }

    return {
        init: init,
        connect: connect,
        printPickSheet: printPickSheet,
        printCheckoutReceipt: printCheckoutReceipt,
        printTest: printTest
    };
})();

// Global helper for pick sheet buttons
function qzPrintPickSheet(btn) {
    var id = btn.getAttribute('data-checkout-id');
    btn.disabled = true;
    btn.textContent = 'Printing...';
    SnipePrint.printPickSheet(id)
        .then(function () { btn.textContent = 'Printed!'; })
        .catch(function (err) {
            alert('Print failed: ' + (err.message || err));
            btn.disabled = false;
            btn.textContent = 'Print Pick Sheet';
        });
}

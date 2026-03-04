/**
 * qz-print.js
 * Client-side QZ Tray integration for receipt printing.
 * IIFE module exposing window.SnipePrint.
 *
 * Builds raw ESC/POS commands directly — no external encoder library needed.
 *
 * Supports two connection modes:
 *   - 'usb': direct raw USB printing via vendor/product hex IDs
 *   - 'printer_name': uses the OS printer driver by name
 */
var SnipePrint = (function () {
    'use strict';

    var _cfg = {
        connectionType: 'usb',
        printerName: '',
        usbVendorId: '',
        usbProductId: '',
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

    /**
     * Find and create a QZ printer config based on the connection type.
     * Returns a Promise that resolves to a qz config object.
     *
     * USB mode: uses qz.printers.find() to locate the device by vendor/product ID,
     * then creates a config from the found printer name.
     */
    function createPrinterConfig() {
        if (_cfg.connectionType === 'usb' && _cfg.usbVendorId && _cfg.usbProductId) {
            return qz.printers.find({
                vendor: _cfg.usbVendorId,
                product: _cfg.usbProductId
            }).then(function (found) {
                return qz.configs.create(found);
            });
        }
        return Promise.resolve(qz.configs.create(_cfg.printerName, { encoding: 'UTF-8' }));
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
     * Build raw ESC/POS data array for a receipt.
     * Each element is a string that QZ Tray sends as raw bytes.
     */
    function buildReceiptCommands(data, title) {
        var d = divider();
        var cmds = [];

        cmds.push(CMD.INIT);
        cmds.push(CMD.ALIGN_CTR);
        cmds.push(CMD.BOLD_ON);
        cmds.push((data.app_name || 'SnipeScheduler') + CMD.LF);
        cmds.push(title + CMD.LF);
        cmds.push(CMD.BOLD_OFF);
        cmds.push(CMD.ALIGN_LEFT);
        cmds.push(d + CMD.LF);
        cmds.push('Checkout #' + data.checkout_id + CMD.LF);
        cmds.push('User: ' + (data.user_name || data.user_email || 'Unknown') + CMD.LF);
        cmds.push('Date: ' + data.start_datetime + CMD.LF);
        cmds.push('Return by: ' + data.end_datetime + CMD.LF);
        cmds.push(d + CMD.LF);
        cmds.push(CMD.BOLD_ON);
        cmds.push('ITEMS' + CMD.LF);
        cmds.push(CMD.BOLD_OFF);

        var items = data.items || [];
        for (var j = 0; j < items.length; j++) {
            var item = items[j];
            var tag = item.asset_tag || '';
            var model = item.model_name || '';
            cmds.push(truncate(tag + ' - ' + model) + CMD.LF);
        }

        cmds.push(d + CMD.LF);
        cmds.push('Total items: ' + items.length + CMD.LF);
        cmds.push(CMD.LF);
        cmds.push('Staff signature: _______________' + CMD.LF);
        cmds.push('User signature:  _______________' + CMD.LF);
        cmds.push(CMD.LF);
        cmds.push(CMD.CUT);

        return cmds;
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

            var cmds = buildReceiptCommands(data, title);
            return createPrinterConfig().then(function (config) {
                return qz.print(config, cmds);
            });
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
            var cmds = [];

            cmds.push(CMD.INIT);
            cmds.push(CMD.ALIGN_CTR);
            cmds.push(CMD.BOLD_ON);
            cmds.push('QZ TRAY TEST' + CMD.LF);
            cmds.push(CMD.BOLD_OFF);
            cmds.push(CMD.ALIGN_LEFT);
            cmds.push(d + CMD.LF);
            cmds.push('Printer: ' + printerLabel() + CMD.LF);
            cmds.push('Mode: ' + _cfg.connectionType + CMD.LF);
            cmds.push('Paper width: ' + _cfg.paperWidth + ' chars' + CMD.LF);
            cmds.push('Time: ' + new Date().toLocaleString() + CMD.LF);
            cmds.push(d + CMD.LF);
            cmds.push('If you can read this, printing works!' + CMD.LF);
            cmds.push(CMD.LF);
            cmds.push(CMD.CUT);

            return createPrinterConfig().then(function (config) {
                return qz.print(config, cmds);
            });
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

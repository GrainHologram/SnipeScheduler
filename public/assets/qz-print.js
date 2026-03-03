/**
 * qz-print.js
 * Client-side QZ Tray integration for receipt printing.
 * IIFE module exposing window.SnipePrint.
 *
 * Supports two connection modes:
 *   - 'printer_name': uses the OS printer driver by name
 *   - 'usb': direct raw USB printing via vendor/product hex IDs
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
     * Create a QZ printer config based on the connection type.
     * - 'usb': direct raw USB via vendor/product IDs
     * - 'printer_name': OS printer driver by name
     */
    function createPrinterConfig() {
        if (_cfg.connectionType === 'usb' && _cfg.usbVendorId && _cfg.usbProductId) {
            return qz.configs.create({
                vendor: _cfg.usbVendorId,
                product: _cfg.usbProductId
            });
        }
        return qz.configs.create(_cfg.printerName, { encoding: 'UTF-8' });
    }

    function printerLabel() {
        if (_cfg.connectionType === 'usb' && _cfg.usbVendorId && _cfg.usbProductId) {
            return 'USB ' + _cfg.usbVendorId + ':' + _cfg.usbProductId;
        }
        return _cfg.printerName || '(default)';
    }

    function buildReceiptData(data, title) {
        var w = _cfg.paperWidth;
        var divider = '';
        for (var i = 0; i < w; i++) divider += '\u2500';

        var encoder = new ReceiptPrinterEncoder({
            columns: w,
            newline: '\n'
        });

        encoder
            .initialize()
            .codepage('auto')
            .align('center')
            .bold(true)
            .line(data.app_name || 'SnipeScheduler')
            .line(title)
            .bold(false)
            .align('left')
            .line(divider)
            .line('Checkout #' + data.checkout_id)
            .line('User: ' + (data.user_name || data.user_email || 'Unknown'))
            .line('Date: ' + data.start_datetime)
            .line('Return by: ' + data.end_datetime)
            .line(divider)
            .bold(true)
            .line('ITEMS')
            .bold(false);

        var items = data.items || [];
        for (var j = 0; j < items.length; j++) {
            var item = items[j];
            var tag = item.asset_tag || '';
            var model = item.model_name || '';
            var line = tag + ' - ' + model;
            if (line.length > w) line = line.substring(0, w);
            encoder.line(line);
        }

        encoder
            .line(divider)
            .line('Total items: ' + items.length)
            .newline()
            .line('Staff signature: _______________')
            .line('User signature:  _______________')
            .newline()
            .cut();

        return encoder.encode();
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

            var raw = buildReceiptData(data, title);
            var config = createPrinterConfig();
            var printData = [{ type: 'raw', format: 'command', data: raw, options: { language: 'ESCPOS' } }];

            return qz.print(config, printData);
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
            var w = _cfg.paperWidth;
            var divider = '';
            for (var i = 0; i < w; i++) divider += '\u2500';

            var encoder = new ReceiptPrinterEncoder({
                columns: w,
                newline: '\n'
            });

            encoder
                .initialize()
                .codepage('auto')
                .align('center')
                .bold(true)
                .line('QZ TRAY TEST')
                .bold(false)
                .align('left')
                .line(divider)
                .line('Printer: ' + printerLabel())
                .line('Mode: ' + _cfg.connectionType)
                .line('Paper width: ' + w + ' chars')
                .line('Time: ' + new Date().toLocaleString())
                .line(divider)
                .line('If you can read this, printing works!')
                .newline()
                .cut();

            var raw = encoder.encode();
            var config = createPrinterConfig();
            var printData = [{ type: 'raw', format: 'command', data: raw, options: { language: 'ESCPOS' } }];

            return qz.print(config, printData);
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

/**
 * print-label.js
 *
 * Bench label printing controller for print_label.php. Wraps QZ Tray to:
 *   - Connect once (cert + sign + websocket)
 *   - Upload Zebra fonts on first call per browser session
 *   - Look up an asset_tag via ajax_asset_label_data.php
 *   - Build ZPL for the chosen label type and send to the network printer
 *
 * Exposes window.PrintLabel.init(opts).
 */
(function () {
    'use strict';

    // Bump this when the fonts file or its drive letter changes so old
    // sessionStorage flags from prior deploys don't block a needed re-upload.
    var FONTS_SESSION_KEY = 'printLabel.fontsLoaded.r1';

    var _cfg = null;
    var _printerConfig = null;
    var _ready = false;

    function init(opts) {
        _cfg = opts;
        // layout.php loads qz-tray.js from layout_footer, which runs AFTER
        // this page's body scripts. So qz may not exist yet — poll briefly.
        var waited = 0;
        function whenReady() {
            if (typeof qz !== 'undefined') {
                _printerConfig = null;
                wireUI();
                connectAndPrepare();
                return;
            }
            if (waited > 5000) {
                setStatus('error', 'QZ Tray library failed to load.');
                return;
            }
            waited += 50;
            setTimeout(whenReady, 50);
        }
        whenReady();
    }

    function ensurePrinterConfig() {
        if (_printerConfig) return _printerConfig;
        if (_cfg.printerName) {
            // Named printer + bypass driver mode (per https://qz.io/docs/raw).
            console.log('[print-label] config: named printer', _cfg.printerName, 'forceRaw=true');
            _printerConfig = qz.configs.create(_cfg.printerName, {
                forceRaw: true,
                encoding: 'UTF-8'
            });
        } else {
            console.log('[print-label] config: network', _cfg.printerHost + ':' + _cfg.printerPort);
            _printerConfig = qz.configs.create({
                host: _cfg.printerHost,
                port: _cfg.printerPort
            }, {
                encoding: 'UTF-8'
            });
        }
        return _printerConfig;
    }

    function wireUI() {
        var form = document.getElementById('scan-form');
        var input = document.getElementById('scan-tag');
        var btn = document.getElementById('print-btn');
        if (form && input && btn) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                handlePrint(input.value);
            });
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                handlePrint(input.value);
            });
        }
    }

    function connectAndPrepare() {
        configureQzSecurity();
        ensureConnected()
            .then(ensureFontsUploaded)
            .then(function () {
                _ready = true;
                setStatus('ready', 'Printer ready');
                enableInput();
            })
            .catch(function (err) {
                console.error('print-label init failed:', err);
                setStatus('error', String(err && err.message || err));
            });
    }

    function configureQzSecurity() {
        qz.security.setCertificatePromise(function (resolve) {
            fetch(_cfg.certUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(resolve)
                .catch(function () { resolve(''); });
        });
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(function (toSign) {
            return function (resolve, reject) {
                fetch(_cfg.signUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ request: toSign })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.signature) resolve(data.signature);
                        else reject(data.error || 'Signing failed');
                    })
                    .catch(reject);
            };
        });
    }

    function ensureConnected() {
        if (qz.websocket.isActive()) {
            console.log('[print-label] ensureConnected: already active');
            return Promise.resolve();
        }
        console.log('[print-label] ensureConnected: calling qz.websocket.connect()');
        return qz.websocket.connect()
            .then(function () {
                console.log('[print-label] connect resolved. isActive=', qz.websocket.isActive(),
                            ' connection=', qz.websocket.connection);
            })
            .catch(function (err) {
                console.error('[print-label] connect failed:', err);
                var msg = (err && err.message) || String(err);
                if (msg.indexOf('Unable to connect') !== -1 || msg.indexOf('CLOSE_EVENT') !== -1) {
                    throw new Error('QZ Tray not detected. Install and start QZ Tray, then reload.');
                }
                throw err;
            });
    }

    function ensureFontsUploaded() {
        // Diagnostic escape hatch: ?nofonts=1 in the URL skips the upload
        // entirely. Useful for proving whether the handshake itself works
        // independent of the 412 KB ZPL transfer.
        if (window.location.search.indexOf('nofonts=1') !== -1) {
            return Promise.resolve();
        }
        try {
            if (sessionStorage.getItem(FONTS_SESSION_KEY) === '1') {
                return Promise.resolve();
            }
        } catch (e) { /* sessionStorage might be unavailable; just re-upload */ }

        setStatus('busy', 'Uploading fonts to printer…');
        return fetch(_cfg.fontsUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('Could not fetch fonts (HTTP ' + r.status + ')');
                return r.text();
            })
            .then(function (zpl) {
                console.log('[print-label] about to qz.print() fonts.',
                            'isActive=', qz.websocket.isActive(),
                            'bytes=', zpl.length);
                // Match the receipt code's payload shape (plain string array) —
                // simpler and avoids any object-spec quirks for raw network printing.
                return qz.print(ensurePrinterConfig(), [zpl]);
            })
            .then(function () {
                try { sessionStorage.setItem(FONTS_SESSION_KEY, '1'); } catch (e) {}
            });
    }

    function enableInput() {
        var input = document.getElementById('scan-tag');
        var btn = document.getElementById('print-btn');
        if (input) {
            input.disabled = false;
            input.focus();
        }
        if (btn) btn.disabled = false;
    }

    function handlePrint(rawValue) {
        if (!_ready) return;
        var tag = stripWrapitUrlSafe(rawValue).trim();
        if (tag === '') return;
        var input = document.getElementById('scan-tag');
        if (input) input.value = '';

        showFlash('info', 'Looking up ' + tag + '…');

        fetch(_cfg.assetLookupUrl + '?tag=' + encodeURIComponent(tag), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.body.ok) {
                    throw new Error(res.body.error || 'Asset lookup failed');
                }
                var zpl = buildLabelZpl(res.body, _cfg.labelType);
                showFlash('info', 'Printing ' + tag + '…');
                return qz.print(ensurePrinterConfig(), [zpl])
                    .then(function () {
                        showFlash('success', 'Printed ' + (res.body.asset_tag || tag) + ' — ' + (res.body.description || ''));
                        setLastPrinted(res.body);
                    });
            })
            .catch(function (err) {
                showFlash('error', String(err && err.message || err));
            })
            .finally(function () {
                if (input) input.focus();
            });
    }

    function buildLabelZpl(asset, labelType) {
        if (labelType === 'cable') {
            return buildCableWrapZpl(asset);
        }
        if (labelType === 'qronly') {
            return buildQrOnlyZpl(asset);
        }
        return buildGenericZpl(asset);
    }

    /**
     * Generic 2" × 1" label (203 dpi → 406 × 203 dots).
     * Layout: large asset_tag top-center under a small QR, horizontal rule,
     * description (auto-sized) in the middle band, footer text at bottom.
     */
    function buildGenericZpl(asset) {
        var tag = sanitizeZpl(asset.asset_tag);
        var description = sanitizeZpl(asset.description || asset.asset_name || asset.model_name || '');
        var descField = buildDescriptionField(description);
        return [
            '^XA~TA000~JSN^LT5^LS5^MNW^MTT^PON^PMN^LH0,0^JMA^PR3,3~SD25^JUS^LRN^CI28^PW406^LL203^XZ',
            '^XA^CWL,r:SWISS^XZ',
            '^XA^CWK,r:JBM_RG^XZ',
            '^XA',
            '^CI28',
            '^BY2,2',
            '^ARN,',
            '^FT110,72^AKN,56^FB320,1,0,C^FD' + tag + '\\&^FS',
            '^FO13,110,2',
            '^GB380,2,2,,^FS',
            descField,
            '^FO30,0',
            '^BQN,2,3',
            '^FDQA,https://wrapit.us/v/' + tag + '^FS',
            '^FT0,186^A0N,13,12^FB406,1,0,C^FDProperty of Southern Adventist University-SVAD\\&^FS',
            '^PQ1^XZ'
        ].join('\n');
    }

    /**
     * Tiered font/position picker for the description block. Pick font
     * height, max-line count, and the printed origin (x, y, width) from
     * the character count. Very long text is hard-truncated with an
     * ellipsis at the smallest tier.
     *
     * For multi-line tiers, balanceLineBreaks() inserts ZPL hard breaks
     * (\&) at the word boundary closest to each ideal split point. This
     * keeps lines roughly equal length and avoids a single trailing
     * orphan word on the last line.
     */
    function buildDescriptionField(description) {
        var tiers = [
            { max: 19,  size: 38, lines: 1, x: 0,  y: 156, width: 406 },
            { max: 32,  size: 28, lines: 1, x: 0,  y: 157, width: 406 },
            { max: 50,  size: 24, lines: 2, x: 5,  y: 162, width: 396 },
            { max: 120, size: 18, lines: 3, x: 13, y: 168, width: 380 },
            { max: 999, size: 14, lines: 4, x: 13, y: 170, width: 380 }
        ];
        var tier = tiers[tiers.length - 1];
        for (var i = 0; i < tiers.length; i++) {
            if (description.length <= tiers[i].max) { tier = tiers[i]; break; }
        }
        // Tier 5 character cap: 4 lines × ~49 chars/line at font 14 in 380 dots.
        if (tier.max === 999 && description.length > 190) {
            description = description.substring(0, 189) + '…';
        }
        var text = balanceLineBreaks(description, tier.lines);
        return '^FT' + tier.x + ',' + tier.y + '^ALN,' + tier.size + ',' + tier.size
             + '^FB' + tier.width + ',' + tier.lines + ',0,C^FD' + text + '\\&^FS';
    }

    /**
     * Insert ZPL hard breaks (\&) at word boundaries closest to the ideal
     * even-split points. Greedy left-to-right: fill the current line until
     * adding the next word would overshoot ~110% of the per-line target,
     * then start a new line. Never produces more than numLines lines.
     */
    function balanceLineBreaks(text, numLines) {
        if (numLines <= 1) return text;
        var words = String(text).split(/\s+/).filter(Boolean);
        if (words.length < 2) return text;
        var target = text.length / numLines;
        var lines = [];
        var current = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            var stillNeedBreak = lines.length < numLines - 1;
            var nextLen = current.length + (current ? 1 : 0) + w.length;
            if (stillNeedBreak && current.length >= target * 0.7 && nextLen > target * 1.1) {
                lines.push(current);
                current = w;
            } else {
                current = current ? current + ' ' + w : w;
            }
        }
        if (current) lines.push(current);
        return lines.join('\\&');
    }

    /**
     * Cable-wrap label (1" × 2.25"). Layout TBD — placeholder uses the
     * generic ZPL so end-to-end works while you design the wrap layout.
     */
    function buildCableWrapZpl(asset) {
        // TODO: bespoke 1"×2.25" wrap layout (^PW203^LL457 portrait, smaller text)
        return buildGenericZpl(asset);
    }

    /**
     * QR-only label (2" × 1", 203 dpi → 406 × 203 dots).
     * Three QR codes of decreasing magnification (4/3/2) with different
     * error-correction levels (Q/Q/H) plus a DataMatrix code, and the
     * asset_tag printed in four sizes across the label.
     */
    function buildQrOnlyZpl(asset) {
        var tag = sanitizeZpl(asset.asset_tag);
        return [
            '^XA~TA000~JSN^LT5^LS5^MNW^MTT^PON^PMN^LH0,0^JMA^PR3,3~SD25^JUS^LRN^CI28^PW406^LL203^XZ',
            '^XA^CWL,r:SWISS^XZ',
            '^XA^CWK,r:JBM_RG^XZ',
            '^XA',
            '^CI28',
            '^BY2,2',
            '^ARN,',
            '^FT10,168^AKN,36^FB120,1,0,C^FD' + tag + '\\&^FS',
            '^FT160,120^AKN,22^FB86,1,0,C^FD' + tag + '\\&^FS',
            '^FT314,74^AKB,14^FB62,1,0,C^FD' + tag + '\\&^FS',
            '^FT200,190^AKB,14^FB52,1,0,C^FD' + tag + '\\&^FS',
            '^FO10,5',
            '^BQN,2,4',
            '^FDQA,https://wrapit.us/v/' + tag + '^FS',
            '^FO160,5',
            '^BQN,2,3',
            '^FDQA,https://wrapit.us/v/' + tag + '^FS',
            '^FO320,5',
            '^BQN,2,2',
            '^FDHA,https://wrapit.us/v/' + tag + '^FS',
            '^FO210,146',
            '^BXN,5,200,,,,,2',
            '^FD' + tag + '^FS',
            '^PQ1^XZ'
        ].join('\n');
    }

    /**
     * ZPL data fields use ^ and ~ as control prefixes. Strip both from
     * user-supplied data so a stray caret in an asset name can't break the
     * field parse. Backslashes are kept (the templates use \& and \\ for
     * literals); the asset tag regex already excludes \.
     */
    function sanitizeZpl(s) {
        return String(s == null ? '' : s).replace(/[\^~]/g, ' ');
    }

    /**
     * Mirrors stripWrapitUrl in nav.js. Defined locally so this page works
     * even if nav.js hasn't loaded yet (autofocus on the scan input fires
     * very early on bench pages).
     */
    function stripWrapitUrlSafe(value) {
        if (typeof window.stripWrapitUrl === 'function') {
            return window.stripWrapitUrl(value);
        }
        var s = String(value == null ? '' : value).trim();
        var m = s.match(/^https?:\/\/(?:www\.)?wrapit\.us\/v\/([^\/\s?#]+)\/?$/i);
        return m ? decodeURIComponent(m[1]) : s;
    }

    function setStatus(kind, text) {
        var el = document.getElementById('printer-status');
        if (!el) return;
        el.classList.remove('text-muted', 'text-success', 'text-danger', 'text-info');
        var icon = '';
        if (kind === 'ready') {
            el.classList.add('text-success');
            icon = '<i class="bi bi-check-circle-fill me-1"></i>';
        } else if (kind === 'error') {
            el.classList.add('text-danger');
            icon = '<i class="bi bi-exclamation-triangle-fill me-1"></i>';
        } else if (kind === 'busy') {
            el.classList.add('text-info');
            icon = '<span class="spinner-border spinner-border-sm align-middle me-1" role="status"></span>';
        } else {
            el.classList.add('text-muted');
        }
        el.innerHTML = icon + escapeHtml(text);
    }

    function showFlash(kind, text) {
        var el = document.getElementById('print-flash');
        if (!el) return;
        var bsClass = 'alert-info';
        if (kind === 'success') bsClass = 'alert-success';
        else if (kind === 'error') bsClass = 'alert-danger';
        el.innerHTML = '<div class="alert ' + bsClass + ' py-2 mb-0">' + escapeHtml(text) + '</div>';
    }

    function setLastPrinted(asset) {
        var el = document.getElementById('last-printed');
        if (!el) return;
        var lines = [
            'Tag: ' + (asset.asset_tag || ''),
            'Description: ' + (asset.description || ''),
            'Model: ' + (asset.model_name || ''),
            'Asset name: ' + (asset.asset_name || '')
        ];
        if (asset.svad_name) lines.unshift('SVAD Name: ' + asset.svad_name);
        el.innerHTML = lines.map(function (l) { return escapeHtml(l); }).join('<br>');
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]);
        });
    }

    window.PrintLabel = { init: init };
})();

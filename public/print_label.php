<?php
// Bench label printing page.
//
// Scan an asset_tag and ZPL is sent to the Zebra printer via QZ Tray
// (running on the operator's local machine, bridging the page to the
// network printer at 10.31.0.28:9100).
//
// Workflow:
//   1. On load: operator picks label type (cable wrap or generic).
//   2. Page connects to QZ Tray, uploads fonts (once per session), focuses
//      the scan input.
//   3. Scan / type asset_tag + Enter → AJAX to ajax_asset_label_data.php
//      → JS builds ZPL → qz.print() → input refocuses.
//
// Staff/admin only.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo '<p>Admins only.</p>';
    exit;
}

$labelType = $_GET['type'] ?? '';
$labelType = in_array($labelType, ['generic', 'cable'], true) ? $labelType : '';

layout_page_start([
    'active'          => 'print_label.php',
    'title'           => 'Print Label',
    'pageHeaderTitle' => 'Print Label',
]);
?>

<?php if ($labelType === ''): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title mb-3">Pick a label type</h5>
      <p class="text-muted mb-3">The label type is locked for the session. Reload the page to switch.</p>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="print_label.php?type=generic">Generic (2&Prime; &times; 1&Prime;)</a>
        <a class="btn btn-outline-primary" href="print_label.php?type=cable">Cable wrap (1&Prime; &times; 2.25&Prime;)</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <span class="text-muted small">Label type</span>
          <strong class="ms-1"><?= h($labelType === 'cable' ? 'Cable wrap (1″ × 2.25″)' : 'Generic (2″ × 1″)') ?></strong>
          <a class="ms-3 small" href="print_label.php">switch</a>
        </div>
        <div id="printer-status" class="small text-muted">
          <span class="spinner-border spinner-border-sm align-middle me-1" role="status"></span>
          Connecting to QZ Tray…
        </div>
      </div>

      <form id="scan-form" class="row g-2 align-items-end" autocomplete="off" onsubmit="return false;">
        <div class="col">
          <label class="form-label mb-1 fw-semibold" for="scan-tag">Asset tag</label>
          <input type="text" id="scan-tag" class="form-control form-control-lg"
                 placeholder="Scan or type asset tag and press Enter"
                 autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                 disabled>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-lg" id="print-btn" disabled>Print</button>
        </div>
      </form>

      <div id="print-flash" class="mt-3"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6 class="card-title text-muted">Last printed</h6>
      <div id="last-printed" class="text-muted small">— nothing yet —</div>
    </div>
  </div>

  <?php
    // layout.php already loads qz-tray.js when admin Settings has QZ Tray
    // enabled. Loading it twice re-runs the IIFE and breaks the connection
    // state (isActive() flips false). Only emit the tag when layout didn't.
    $qzCfg = load_config()['qz_tray'] ?? [];
    if (empty($qzCfg['enabled'])):
  ?>
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2/qz-tray.js"></script>
  <?php endif; ?>
  <script src="assets/print-label.js"></script>
  <script>
    // ?printer=Name uses a named printer with forceRaw:true (bypass driver
    // mode). Otherwise default to direct network host:port.
    var __printerName = new URLSearchParams(window.location.search).get('printer');
    PrintLabel.init({
      labelType: <?= json_encode($labelType, JSON_UNESCAPED_SLASHES) ?>,
      printerName: __printerName || null,
      printerHost: '10.31.0.28',
      printerPort: 9100,
      certUrl: 'ajax_qz_cert.php',
      signUrl: 'ajax_qz_sign.php',
      fontsUrl: 'assets/label_fonts.zpl',
      assetLookupUrl: 'ajax_asset_label_data.php'
    });
  </script>
<?php endif; ?>

<?php
layout_page_end([
    'active' => 'print_label.php',
]);

<?php
// Public per-asset documentation page.
//
// Reached via:   https://wrapit.us/v/{asset_tag}   (nginx 302 → here)
//      or:      /v.php?tag={asset_tag}            (direct)
//
// Resolves the asset_tag → asset → parent model, then renders the file list
// attached to that model in Snipe-IT. .txt files are scanned for embedded
// URLs and rendered inline (links.txt fully inlined; other .txt rendered as
// download + extracted URLs). YouTube URLs get thumbnail + title via oEmbed.
//
// No login required. The proxy backing file downloads (v_file.php) is also
// public — anyone with the QR can read docs, matching the physical access
// model.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/qr_docs.php';

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$rawTag = $_GET['tag'] ?? '';
$tag    = normalize_scanned_tag($rawTag);

$valid = $tag !== '' && (bool)preg_match('/^[A-Za-z0-9._-]+$/', $tag);

$asset      = null;
$modelId    = 0;
$modelName  = '';
$files      = [];
$lookupErr  = '';

if (!$valid) {
    $lookupErr = 'Invalid asset tag.';
    http_response_code(400);
} else {
    try {
        $asset      = find_asset_by_tag($tag);
        $modelId    = (int)($asset['model']['id'] ?? 0);
        $modelName  = (string)($asset['model']['name'] ?? '');
        if ($modelId <= 0) {
            throw new Exception('Asset has no model.');
        }
        $files = get_model_files($modelId);
    } catch (Throwable $e) {
        $lookupErr = $e->getMessage();
        http_response_code(404);
    }
}

/**
 * Bootstrap icon class for a file extension.
 */
function v_icon_for_ext(string $ext): string
{
    $ext = strtolower($ext);
    $map = [
        'pdf'  => 'bi-file-earmark-pdf',
        'doc'  => 'bi-file-earmark-word',  'docx' => 'bi-file-earmark-word',
        'xls'  => 'bi-file-earmark-excel', 'xlsx' => 'bi-file-earmark-excel', 'ods' => 'bi-file-earmark-excel',
        'odt'  => 'bi-file-earmark-word',  'odp'  => 'bi-file-earmark-slides',
        'png'  => 'bi-file-earmark-image', 'jpg'  => 'bi-file-earmark-image', 'jpeg' => 'bi-file-earmark-image',
        'gif'  => 'bi-file-earmark-image', 'webp' => 'bi-file-earmark-image', 'avif' => 'bi-file-earmark-image',
        'svg'  => 'bi-file-earmark-image', 'ico'  => 'bi-file-earmark-image', 'jfif' => 'bi-file-earmark-image',
        'mp4'  => 'bi-file-earmark-play',  'mov'  => 'bi-file-earmark-play',  'webm' => 'bi-file-earmark-play',
        'mp3'  => 'bi-file-earmark-music', 'wav'  => 'bi-file-earmark-music', 'ogg'  => 'bi-file-earmark-music',
        'zip'  => 'bi-file-earmark-zip',   'rar'  => 'bi-file-earmark-zip',
        'txt'  => 'bi-file-earmark-text',  'rtf'  => 'bi-file-earmark-text',
        'json' => 'bi-file-earmark-code',  'xml'  => 'bi-file-earmark-code',
    ];
    return $map[$ext] ?? 'bi-file-earmark';
}

// Build the list of rendered entries:
//  - kind = 'file'    → a Snipe-IT file download
//  - kind = 'link'    → a plain URL extracted from a .txt
//  - kind = 'youtube' → a YouTube URL with oEmbed metadata
$entries = [];

// Throttle oEmbed lookups so a giant links.txt can't hammer YouTube.
$youtubeBudget = 10;

foreach ($files as $f) {
    if (!is_array($f)) continue;
    $fid       = (int)($f['id'] ?? 0);
    $fnameRaw  = (string)($f['filename'] ?? $f['name'] ?? '');
    if ($fid <= 0 || $fnameRaw === '') continue;
    $ext       = strtolower(pathinfo($fnameRaw, PATHINFO_EXTENSION));
    $isTxt     = $ext === 'txt';
    $isLinksTxt = $isTxt && strcasecmp($fnameRaw, 'links.txt') === 0;

    $downloadHref = 'v_file.php?model_id=' . $modelId . '&file_id=' . $fid;

    $extractedUrls = [];
    if ($isTxt) {
        $extractedUrls = scan_txt_for_urls($modelId, $fid);
    }

    if (!$isLinksTxt) {
        $entries[] = [
            'kind'  => 'file',
            'label' => $fnameRaw,
            'ext'   => $ext,
            'href'  => $downloadHref,
            'notes' => (string)($f['notes'] ?? ''),
        ];
    }

    foreach ($extractedUrls as $url) {
        if (is_youtube_url($url) && $youtubeBudget > 0) {
            $youtubeBudget--;
            $meta = youtube_oembed($url);
            $entries[] = [
                'kind'      => 'youtube',
                'href'      => $url,
                'title'     => $meta['title'] ?? $url,
                'thumbnail' => $meta['thumbnail_url'] ?? '',
                'channel'   => $meta['author_name'] ?? '',
            ];
        } else {
            $entries[] = [
                'kind'  => 'link',
                'href'  => $url,
                'label' => $url,
            ];
        }
    }
}

$pageTitle = $modelName !== '' ? ($modelName . ' — Documentation') : 'Asset Documentation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/style.css">
<style>
  body { background: var(--surface-page, #f6f7fb); }
  .v-shell { max-width: 760px; margin: 2rem auto; padding: 0 1rem 4rem; }
  .v-header { margin-bottom: 1.5rem; }
  .v-header h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
  .v-header .tag { font-family: ui-monospace, monospace; color: var(--text-muted, #6c757d); }
  .v-empty { padding: 2rem; text-align: center; color: var(--text-muted, #6c757d); border: 1px dashed var(--border-color, #dee2e6); border-radius: 8px; }
  .v-entry { display: flex; gap: .75rem; align-items: center; padding: .75rem; border: 1px solid var(--border-color, #dee2e6); border-radius: 8px; margin-bottom: .5rem; background: #fff; text-decoration: none; color: inherit; }
  .v-entry:hover { background: #f9fafc; border-color: #adb5bd; }
  .v-entry .icon { font-size: 1.5rem; color: var(--text-muted, #6c757d); flex: 0 0 auto; }
  .v-entry .label { flex: 1 1 auto; min-width: 0; word-break: break-word; }
  .v-entry .sub { display: block; font-size: .8125rem; color: var(--text-muted, #6c757d); margin-top: .125rem; }
  .v-entry img.thumb { width: 96px; height: 54px; object-fit: cover; border-radius: 4px; flex: 0 0 auto; }
  .v-footer { margin-top: 2rem; text-align: center; font-size: .8125rem; color: var(--text-muted, #6c757d); }
</style>
</head>
<body>
<div class="v-shell">

  <?php if ($lookupErr !== ''): ?>
    <div class="v-header">
      <h1>Asset not found</h1>
      <div class="tag"><?= h($tag !== '' ? $tag : $rawTag) ?></div>
    </div>
    <div class="v-empty">
      <p class="mb-1"><?= h($lookupErr) ?></p>
      <p class="mb-0 small">If this tag is correct, check that the asset still exists in Snipe-IT.</p>
    </div>
  <?php else: ?>
    <div class="v-header">
      <h1><?= h($modelName !== '' ? $modelName : 'Asset') ?></h1>
      <div class="tag">Asset tag: <?= h($tag) ?></div>
    </div>

    <?php if (empty($entries)): ?>
      <div class="v-empty">
        <p class="mb-1">No documentation uploaded for this model yet.</p>
        <p class="mb-0 small">Attach files to the model in Snipe-IT and they'll show here.</p>
      </div>
    <?php else: ?>
      <?php foreach ($entries as $e): ?>
        <?php if ($e['kind'] === 'youtube'): ?>
          <a class="v-entry" href="<?= h($e['href']) ?>" target="_blank" rel="noopener">
            <?php if (!empty($e['thumbnail'])): ?>
              <img class="thumb" src="<?= h($e['thumbnail']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <i class="icon bi bi-youtube" style="color:#c00;"></i>
            <?php endif; ?>
            <span class="label">
              <?= h($e['title']) ?>
              <?php if (!empty($e['channel'])): ?>
                <span class="sub">YouTube · <?= h($e['channel']) ?></span>
              <?php else: ?>
                <span class="sub">YouTube</span>
              <?php endif; ?>
            </span>
          </a>
        <?php elseif ($e['kind'] === 'link'): ?>
          <a class="v-entry" href="<?= h($e['href']) ?>" target="_blank" rel="noopener">
            <i class="icon bi bi-link-45deg"></i>
            <span class="label"><?= h($e['label']) ?></span>
          </a>
        <?php else: /* file */ ?>
          <a class="v-entry" href="<?= h($e['href']) ?>" target="_blank" rel="noopener">
            <i class="icon bi <?= h(v_icon_for_ext($e['ext'])) ?>"></i>
            <span class="label">
              <?= h($e['label']) ?>
              <?php if (!empty($e['notes'])): ?>
                <span class="sub"><?= h($e['notes']) ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <div class="v-footer">WrapIt</div>
</div>
</body>
</html>

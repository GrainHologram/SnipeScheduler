# QR codes and the `/v/{asset_tag}` documentation page

## What this is

Physical asset labels carry a dual-purpose QR code:

    https://wrapit.us/v/{asset_tag}

Two flows use the same code:

1. **USB barcode scanner** types the full URL into a scan field on
   `quick_checkin.php`, `quick_checkout.php`, or `staff_checkout.php`. JS
   (`stripWrapitUrl` in `public/assets/nav.js`) peels the URL down to the
   bare asset_tag before processing; `normalize_scanned_tag()` in
   `src/snipeit_client.php` does the same server-side as a safety net.
2. **Phone camera** opens the URL in a browser. `public/v.php` renders a
   public documentation page for the asset's parent model, listing files
   attached to that model in Snipe-IT.

## Documentation page behavior

`v.php` resolves asset_tag → asset → model via the Snipe-IT API, then:

- Lists each file attached to the model (icon + filename, links to
  `v_file.php` which proxies the download).
- Scans every `.txt` file for embedded http(s) URLs.
  - For a file named exactly **`links.txt`**: the .txt itself is hidden;
    each URL becomes its own entry in the list.
  - For other `.txt` files: the download stays, and extracted URLs are
    rendered as additional entries underneath.
- YouTube URLs (watch / shorts / embed / live / `youtu.be`) get
  thumbnail + title via YouTube oEmbed. Capped at 10 lookups per page
  render. oEmbed results (including failures) are cached for 7 days.

No login required for `v.php` or `v_file.php` — the QR is on the physical
asset, so anyone with physical access (or who already knows the tag) can
read docs.

## Deploy: nginx redirect on `wrapit.us`

`wrapit.us` is a separate vhost from `ss.studio6h.com` (the SnipeScheduler
app) and does not have PHP-FPM configured. Add a 302 to its server block
so `/v/{tag}` reaches the app:

    # /etc/nginx/sites-enabled/wrapit.us.conf
    server {
        server_name wrapit.us;
        # ... existing config ...

        # Per-asset documentation page (served by SnipeScheduler).
        # Match the asset-tag pattern: 4 digit number, or 'svad'+5 digits.
        location ~* ^/v/([A-Za-z0-9._-]+)/?$ {
            return 302 https://ss.studio6h.com/v.php?tag=$1;
        }
    }

Reload after editing:

    sudo nginx -t && sudo systemctl reload nginx

## Testing checklist

- [ ] Scan a printed QR with a USB barcode scanner into the `quick_checkin`
      scan field. The flash message should show the bare asset_tag, not the
      URL.
- [ ] Same scan in `quick_checkout` and `staff_checkout`.
- [ ] Browse `https://wrapit.us/v/{a-real-tag}` on a phone. Should 302 to
      `ss.studio6h.com/v.php?tag={tag}` and render the model name + file
      list.
- [ ] Attach a `links.txt` to a model in Snipe-IT with one URL per line
      including a YouTube link. Reload the v.php page — the .txt should not
      appear as a download; each URL should be its own entry; the YouTube
      entry should have thumbnail + title.
- [ ] Attach `setup-notes.txt` containing prose with a URL embedded. Both
      the .txt download and the extracted URL should appear.

## Caches

- Model files (`get_model_files`): 1h TTL.
- `.txt` URL extractions (`scan_txt_for_urls`): 1h TTL.
- YouTube oEmbed lookups (`youtube_oembed`): 7d TTL (success and failure
  both cached).

All cached under `config/cache/`. Safe to delete if a model's docs change
and you don't want to wait for the TTL.

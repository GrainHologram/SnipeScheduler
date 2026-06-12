<?php
// Helpers backing public/v.php (per-model QR documentation page).
//
// - extract_urls_from_text(): pull http(s) URLs out of a text blob
// - is_youtube_url():        recognize YouTube watch/shorts/embed/youtu.be URLs
// - youtube_oembed():        fetch title + thumbnail via YouTube's oEmbed API,
//                            with 7-day caching to avoid hammering the endpoint
// - scan_txt_for_urls():     read a Snipe-IT model .txt file and return its URLs,
//                            cached so repeat page renders don't re-fetch

require_once __DIR__ . '/snipeit_client.php';

if (!function_exists('extract_urls_from_text')) {
    function extract_urls_from_text(string $text): array
    {
        // Match http(s) URLs, stop at whitespace and common closing punctuation.
        // Trailing punctuation likely to be sentence-end is trimmed.
        if (!preg_match_all('#https?://[^\s\)\]\>\"\'<]+#i', $text, $m)) {
            return [];
        }
        $urls = [];
        foreach ($m[0] as $u) {
            $u = rtrim($u, ".,;:!?");
            if ($u !== '' && !in_array($u, $urls, true)) {
                $urls[] = $u;
            }
        }
        return $urls;
    }
}

if (!function_exists('is_youtube_url')) {
    function is_youtube_url(string $url): bool
    {
        return (bool)preg_match(
            '#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?|shorts/|embed/|live/)|youtu\.be/)#i',
            $url
        );
    }
}

if (!function_exists('youtube_oembed')) {
    /**
     * Fetch YouTube oEmbed metadata for a URL. Returns null on any failure.
     * Successful results AND failures both cache for 7 days so a broken or
     * private video doesn't get retried on every page view.
     */
    function youtube_oembed(string $url): ?array
    {
        $cacheKey = 'oembed_yt_' . md5($url);
        $cached = snipeit_cache_get($cacheKey, 86400 * 7);
        if ($cached !== null) {
            return empty($cached['ok']) ? null : $cached['data'];
        }

        $api = 'https://www.youtube.com/oembed?format=json&url=' . urlencode($url);
        $ch  = curl_init($api);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body)) {
            snipeit_cache_set($cacheKey, ['ok' => false]);
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            snipeit_cache_set($cacheKey, ['ok' => false]);
            return null;
        }

        $out = [
            'title'         => isset($data['title']) ? (string)$data['title'] : '',
            'thumbnail_url' => isset($data['thumbnail_url']) ? (string)$data['thumbnail_url'] : '',
            'author_name'   => isset($data['author_name']) ? (string)$data['author_name'] : '',
        ];
        snipeit_cache_set($cacheKey, ['ok' => true, 'data' => $out]);
        return $out;
    }
}

if (!function_exists('scan_txt_for_urls')) {
    /**
     * Read a Snipe-IT model .txt file and return its embedded URLs.
     * Caches the URL list for 1h so we don't fetch the file twice per page.
     * Returns empty array on any fetch error.
     */
    function scan_txt_for_urls(int $modelId, int $fileId): array
    {
        $cacheKey = 'txt_urls_' . $modelId . '_' . $fileId;
        $cached = snipeit_cache_get($cacheKey, 3600);
        if ($cached !== null) {
            return is_array($cached) ? $cached : [];
        }
        try {
            [$ct, $cd, $body] = fetch_model_file($modelId, $fileId);
            $urls = extract_urls_from_text($body);
        } catch (Throwable $e) {
            $urls = [];
        }
        snipeit_cache_set($cacheKey, $urls);
        return $urls;
    }
}

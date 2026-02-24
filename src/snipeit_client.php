<?php
// snipeit_client.php
//
// Thin client for talking to the Snipe-IT API.
// Uses config.php for base URL, API token and SSL verification settings.
//
// Exposes:
//   - get_bookable_models($page, $search, $categoryId, $sort, $perPage, $allowedCategoryIds)
//   - get_model_categories()
//   - get_model($id)
//   - get_model_hardware_count($modelId)

require_once __DIR__ . '/bootstrap.php';

$config       = load_config();
$snipeConfig  = $config['snipeit'] ?? [];

$snipeBaseUrl   = rtrim($snipeConfig['base_url'] ?? '', '/');
$snipeApiToken  = $snipeConfig['api_token'] ?? '';
$snipeVerifySsl = !empty($snipeConfig['verify_ssl']);
$cacheTtl       = isset($config['app']['api_cache_ttl_seconds'])
    ? max(0, (int)$config['app']['api_cache_ttl_seconds'])
    : 60;
$cacheDir       = CONFIG_PATH . '/cache';

$limit = 200;

function snipeit_cache_path(string $key): string
{
    global $cacheDir;
    return rtrim($cacheDir, '/\\') . '/' . $key . '.json';
}

function snipeit_cache_get(string $key, int $ttl)
{
    $path = snipeit_cache_path($key);
    if ($ttl <= 0 || !is_file($path)) {
        return null;
    }
    $age = time() - (int)@filemtime($path);
    if ($age > $ttl) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function snipeit_cache_set(string $key, array $data): void
{
    global $cacheDir;
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $path = snipeit_cache_path($key);
    @file_put_contents($path, json_encode($data), LOCK_EX);
}

/**
 * Core HTTP wrapper for Snipe-IT API.
 *
 * @param string $method   HTTP method (GET, POST, etc.)
 * @param string $endpoint Relative endpoint, e.g. "models" or "models/5"
 * @param array  $params   Query/body params
 * @return array           Decoded JSON response
 * @throws Exception       On HTTP or decode errors
 */
function snipeit_request(string $method, string $endpoint, array $params = []): array
{
    global $snipeBaseUrl, $snipeApiToken, $snipeVerifySsl, $cacheTtl;

    if ($snipeBaseUrl === '' || $snipeApiToken === '') {
        throw new Exception('Snipe-IT API is not configured (missing base_url or api_token).');
    }

    $url = $snipeBaseUrl . '/api/v1/' . ltrim($endpoint, '/');

    $method = strtoupper($method);
    $cacheKey = null;

    // Simple GET cache to reduce repeated hits
    if ($method === 'GET' && $cacheTtl > 0) {
        $cacheKey = sha1($url . '|' . json_encode($params));
        $cached = snipeit_cache_get($cacheKey, $cacheTtl);
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $snipeApiToken,
    ];

    if ($method === 'GET') {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => $snipeVerifySsl,
        CURLOPT_SSL_VERIFYHOST => $snipeVerifySsl ? 2 : 0,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Error talking to Snipe-IT API: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);

    if ($httpCode >= 400) {
        $msg = $decoded['message'] ?? $raw;
        throw new Exception('Snipe-IT API returned HTTP ' . $httpCode . ': ' . $msg);
    }

    if (!is_array($decoded)) {
        throw new Exception('Invalid JSON from Snipe-IT API');
    }

    if ($cacheKey !== null && $cacheTtl > 0) {
        snipeit_cache_set($cacheKey, $decoded);
    }

    return $decoded;
}

/**
 * Fetch **all** matching models from Snipe-IT,
 * then sort them as requested, then paginate locally.
 *
 * Sort options:
 *   - manu_asc / manu_desc      (manufacturer)
 *   - name_asc / name_desc      (model name)
 *   - units_asc / units_desc    (assets_count)
 *
 * @param int         $page
 * @param string      $search
 * @param int|null    $categoryId
 * @param string|null $sort
 * @param int         $perPage
 * @param array       $allowedCategoryIds Optional allowlist; if provided, only models in these category IDs are returned.
 * @return array                  ['total' => X, 'rows' => [...]]
 * @throws Exception
 */
function get_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50,
    array $allowedCategoryIds = []
): array {
    $page    = max(1, $page);
    $perPage = max(1, $perPage);
    $allowedMap = [];
    foreach ($allowedCategoryIds as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $allowedMap[(int)$cid] = true;
        }
    }

    // If an allowlist exists and the requested category is not allowed, clear it to avoid wasted calls.
    $effectiveCategory = $categoryId;
    if (!empty($allowedMap) && $categoryId !== null && !isset($allowedMap[$categoryId])) {
        $effectiveCategory = null;
    }

    $limit  = 200; // per-API-call limit
    $allRows = [];

    $offset = 0;
    // Pull pages from Snipe-IT until we have everything.
    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        if ($search !== '') {
            $params['search'] = $search;
        }

        if (!empty($effectiveCategory)) {
            $params['category_id'] = $effectiveCategory;
        }

        $chunk = snipeit_request('GET', 'models', $params);

        if (!isset($chunk['rows']) || !is_array($chunk['rows'])) {
            break;
        }

        $rows    = $chunk['rows'];
        $allRows = array_merge($allRows, $rows);

        $fetchedThisCall = count($rows);
        $offset += $limit;

        // Stop if we didn't get a full page (end of data).
        if ($fetchedThisCall < $limit) {
            break;
        }
    } while (true);

    // Filter by requestable flag (Snipe-IT uses 'requestable' on models)
    $allRows = array_values(array_filter($allRows, function ($row) {
        return !empty($row['requestable']);
    }));

    // Apply optional category allowlist (overrides requestable-only default scope)
    if (!empty($allowedMap)) {
        $allRows = array_values(array_filter($allRows, function ($row) use ($allowedMap) {
            $cid = isset($row['category']['id']) ? (int)$row['category']['id'] : 0;
            return $cid > 0 && isset($allowedMap[$cid]);
        }));
    }

    // Determine total after filtering
    $total = count($allRows);

    // Sort full set client-side according to requested sort
    $sort = $sort ?? '';

    usort($allRows, function ($a, $b) use ($sort) {
        $nameA  = $a['name'] ?? '';
        $nameB  = $b['name'] ?? '';
        $manA   = $a['manufacturer']['name'] ?? '';
        $manB   = $b['manufacturer']['name'] ?? '';
        $unitsA = isset($a['assets_count']) ? (int)$a['assets_count'] : 0;
        $unitsB = isset($b['assets_count']) ? (int)$b['assets_count'] : 0;

        switch ($sort) {
            case 'manu_asc':
                return strcasecmp($manA, $manB);
            case 'manu_desc':
                return strcasecmp($manB, $manA);

            case 'name_desc':
                return strcasecmp($nameB, $nameA);
            case 'name_asc':
            case '':
                return strcasecmp($nameA, $nameB);

            case 'units_asc':
                if ($unitsA === $unitsB) {
                    return strcasecmp($nameA, $nameB);
                }
                return ($unitsA <=> $unitsB);

            case 'units_desc':
                if ($unitsA === $unitsB) {
                    return strcasecmp($nameA, $nameB);
                }
                return ($unitsB <=> $unitsA);

            default:
                return strcasecmp($nameA, $nameB);
        }
    });

    // Local pagination
    $offsetLocal = ($page - 1) * $perPage;
    $rowsPage    = array_slice($allRows, $offsetLocal, $perPage);

    return [
        'total' => $total,
        'rows'  => $rowsPage,
    ];
}

/**
 * Fetch unique categories from requestable asset models in Snipe-IT.
 * Always returned A–Z by name (client-side sort).
 *
 * @return array
 * @throws Exception
 */
function get_model_categories(): array
{
    $params = [
        'limit'       => 500,
        'requestable' => 'true', // String required — Snipe-IT API ignores PHP bool true (API bug/quirk)
    ];

    $data = snipeit_request('GET', 'models', $params);

    if (!isset($data['rows']) || !is_array($data['rows'])) {
        return [];
    }

    $seen = [];
    $rows = [];
    foreach ($data['rows'] as $model) {
        $cat = $model['category'] ?? null;
        if (!is_array($cat) || empty($cat['id'])) {
            continue;
        }
        $cid = (int)$cat['id'];
        if (isset($seen[$cid])) {
            continue;
        }
        $seen[$cid] = true;
        $rows[] = $cat;
    }

    usort($rows, function ($a, $b) {
        $na = $a['name'] ?? '';
        $nb = $b['name'] ?? '';
        return strcasecmp($na, $nb);
    });

    return $rows;
}

/**
 * Fetch a single model by ID.
 *
 * @param int $modelId
 * @return array
 * @throws Exception
 */
function get_model(int $modelId): array
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Invalid model ID');
    }

    return snipeit_request('GET', 'models/' . $modelId);
}

/**
 * Get the number of hardware assets for a given model.
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */

function get_model_hardware_count(int $modelId): int
{
    $model = get_model($modelId);

    if (isset($model['assets_count']) && is_numeric($model['assets_count'])) {
        return (int)$model['assets_count'];
    }

    if (isset($model['assets_count_total']) && is_numeric($model['assets_count_total'])) {
        return (int)$model['assets_count_total'];
    }

    return 0;
}

/**
 * Fetch all predefined kits from Snipe-IT.
 *
 * Uses 5-minute cache to balance API load with timely updates.
 *
 * @return array  Flat array of kit objects
 * @throws Exception
 */
function get_kits(): array
{
    $cacheKey = 'kits_list';
    $cached = snipeit_cache_get($cacheKey, 300);
    if ($cached !== null) {
        return $cached;
    }

    $data = snipeit_request('GET', 'kits', ['limit' => 500]);
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    snipeit_cache_set($cacheKey, $rows);
    return $rows;
}

/**
 * Fetch a single kit by ID from Snipe-IT.
 *
 * @param int $kitId
 * @return array
 * @throws Exception
 */
function get_kit(int $kitId): array
{
    if ($kitId <= 0) {
        throw new InvalidArgumentException('Invalid kit ID');
    }

    $cacheKey = 'kit_' . $kitId;
    $cached = snipeit_cache_get($cacheKey, 300);
    if ($cached !== null) {
        return $cached;
    }

    $data = snipeit_request('GET', 'kits/' . $kitId);
    snipeit_cache_set($cacheKey, $data);
    return $data;
}

/**
 * Fetch the models (with quantities) that belong to a kit.
 *
 * @param int $kitId
 * @return array  Array of {model: {...}, quantity: int} entries
 * @throws Exception
 */
function get_kit_models(int $kitId): array
{
    if ($kitId <= 0) {
        throw new InvalidArgumentException('Invalid kit ID');
    }

    $cacheKey = 'kit_' . $kitId . '_models';
    $cached = snipeit_cache_get($cacheKey, 300);
    if ($cached !== null) {
        return $cached;
    }

    $data = snipeit_request('GET', 'kits/' . $kitId . '/models');
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    snipeit_cache_set($cacheKey, $rows);
    return $rows;
}

/**
 * Find a single asset by asset_tag.
 *
 * This uses the /hardware endpoint with a search, then looks for an
 * exact asset_tag match. It does NOT rely on /hardware/bytag so it
 * stays compatible across Snipe-IT versions.
 *
 * @param string $tag
 * @return array
 * @throws Exception if no or ambiguous match
 */
function find_asset_by_tag(string $tag): array
{
    $tagTrim = trim($tag);
    if ($tagTrim === '') {
        throw new InvalidArgumentException('Asset tag cannot be empty.');
    }

    // Search hardware with a small limit
    $params = [
        'search' => $tagTrim,
        'limit'  => 50,
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No assets found in Snipe-IT matching tag '{$tagTrim}'.");
    }

    // Look for an exact asset_tag match (case-insensitive)
    $exactMatches = [];
    foreach ($data['rows'] as $row) {
        $rowTag = $row['asset_tag'] ?? '';
        if (strcasecmp(trim($rowTag), $tagTrim) === 0) {
            $exactMatches[] = $row;
        }
    }

    if (count($exactMatches) === 1) {
        return $exactMatches[0];
    }

    if (count($exactMatches) > 1) {
        throw new Exception("Multiple assets found with asset_tag '{$tagTrim}'. Please disambiguate in Snipe-IT.");
    }

    // No exact matches, but we got some approximate results
    // You can choose to accept the first or to treat as "not found".
    // Here we treat as not found to avoid wrong checkouts.
    throw new Exception("No exact asset_tag match for '{$tagTrim}' in Snipe-IT.");
}

/**
 * Search assets by tag or name (Snipe-IT hardware search).
 *
 * @param string $query
 * @param int $limit
 * @param bool $requestableOnly
 * @return array
 * @throws Exception
 */
function search_assets(string $query, int $limit = 20, bool $requestableOnly = false): array
{
    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $params = [
        'search' => $q,
        'limit'  => max(1, min(50, $limit)),
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    $rows = array_values(array_filter($rows, function ($row) use ($requestableOnly) {
        $tag = $row['asset_tag'] ?? '';
        if ($tag === '') {
            return false;
        }
        if ($requestableOnly && empty($row['requestable'])) {
            return false;
        }
        return true;
    }));

    return $rows;
}

/**
 * List hardware assets for a given model.
 *
 * @param int $modelId
 * @param int $maxResults
 * @return array
 * @throws Exception
 */
function list_assets_by_model(int $modelId, int $maxResults = 300): array
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    $all    = [];
    $limit  = min(200, max(1, $maxResults));
    $offset = 0;

    do {
        $params = [
            'model_id' => $modelId,
            'limit'    => $limit,
            'offset'   => $offset,
        ];

        $chunk = snipeit_request('GET', 'hardware', $params);
        $rows  = isset($chunk['rows']) && is_array($chunk['rows']) ? $chunk['rows'] : [];

        $all    = array_merge($all, $rows);
        $count  = count($rows);
        $offset += $limit;

        if ($count < $limit || count($all) >= $maxResults) {
            break;
        }
    } while (true);

    return $all;
}

/**
 * Count requestable assets for a model (asset-level requestable flag).
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */
function count_requestable_assets_by_model(int $modelId): int
{
    static $cache = [];
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }
    if (isset($cache[$modelId])) {
        return $cache[$modelId];
    }

    $assets = list_assets_by_model($modelId, 500);
    $count  = 0;

    foreach ($assets as $a) {
        if (!empty($a['requestable'])) {
            $count++;
        }
    }

    $cache[$modelId] = $count;
    return $count;
}

/**
 * Check whether a Snipe-IT asset is deployable.
 *
 * @param array $asset  Asset record from the Snipe-IT API
 * @return bool
 */
function is_asset_deployable(array $asset): bool
{
    $sl = $asset['status_label'] ?? [];
    if (!is_array($sl)) {
        return true; // no status info available — assume deployable
    }
    $meta = strtolower($sl['status_meta'] ?? ($sl['status_type'] ?? ''));
    // 'deployed' means checked out but operational — still deployable hardware.
    // Only 'undeployable' and 'archived' are truly non-deployable.
    return $meta !== 'undeployable' && $meta !== 'archived';
}

/**
 * Count undeployable (broken/missing/etc.) requestable assets for a model.
 *
 * @param int $modelId
 * @return array{undeployable_count: int, status_names: string[]}
 * @throws Exception
 */
function count_undeployable_assets_by_model(int $modelId): array
{
    static $cache = [];
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }
    if (isset($cache[$modelId])) {
        return $cache[$modelId];
    }

    $assets = list_assets_by_model($modelId, 500);
    $count  = 0;
    $names  = [];

    foreach ($assets as $a) {
        if (empty($a['requestable'])) {
            continue;
        }
        // Skip assets that are assigned/checked-out — those aren't "broken"
        $assignedTo = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
        if (!empty($assignedTo)) {
            continue;
        }
        if (!is_asset_deployable($a)) {
            $count++;
            $statusName = $a['status_label']['name'] ?? 'Unknown';
            $names[$statusName] = true;
        }
    }

    $result = [
        'undeployable_count' => $count,
        'status_names'       => array_keys($names),
    ];
    $cache[$modelId] = $result;
    return $result;
}

/**
 * Count how many assets for a model are currently checked out/assigned.
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */
function count_checked_out_assets_by_model(int $modelId): int
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM checked_out_asset_cache
         WHERE model_id = :model_id
    ");
    $stmt->execute([':model_id' => $modelId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Find a single Snipe-IT user by email or name.
 *
 * Uses /users?search=... and tries to reduce to a single match:
 *  - If exactly one row, returns it.
 *  - If multiple rows and one has an exact email match (case-insensitive),
 *    returns that.
 *  - Otherwise throws an exception listing how many matches there were.
 *
 * @param string $query
 * @return array
 * @throws Exception
 */
function find_single_user_by_email_or_name(string $query): array
{
    $q = trim($query);
    if ($q === '') {
        throw new InvalidArgumentException('User search query cannot be empty.');
    }

    $params = [
        'search' => $q,
        'limit'  => 20,
    ];

    $data = snipeit_request('GET', 'users', $params);

    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No Snipe-IT users found matching '{$q}'.");
    }

    $rows = $data['rows'];

    // If exactly one result, use it
    if (count($rows) === 1) {
        return $rows[0];
    }

    // Try to find exact email match
    $exactEmailMatches = [];
    $exactNameMatches  = [];
    $qLower = strtolower($q);
    foreach ($rows as $row) {
        $email = $row['email'] ?? '';
        $name  = $row['name'] ?? ($row['username'] ?? '');
        if ($email !== '' && strtolower(trim($email)) === $qLower) {
            $exactEmailMatches[] = $row;
        }
        if ($name !== '' && strtolower(trim($name)) === $qLower) {
            $exactNameMatches[] = $row;
        }
    }

    if (count($exactEmailMatches) === 1) {
        return $exactEmailMatches[0];
    }
    if (count($exactNameMatches) === 1) {
        return $exactNameMatches[0];
    }

    // Multiple matches, ambiguous
    $count = count($rows);
    throw new Exception("{$count} users matched '{$q}' in Snipe-IT; please refine (e.g. use full email).");
}

/**
 * Resolve a Snipe-IT user ID from an email address.
 *
 * Returns the Snipe-IT user ID (int > 0) on success, or 0 if the
 * user cannot be found or Snipe-IT is unreachable.
 */
function resolve_snipeit_user_id(string $email): int
{
    $email = trim($email);
    if ($email === '') {
        return 0;
    }
    try {
        $matched = find_single_user_by_email_or_name($email);
        return (int)($matched['id'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Find a Snipe-IT user by email or name, returning candidates on ambiguity.
 *
 * @param string $query
 * @return array{user: ?array, candidates: array}
 * @throws Exception
 */
function find_user_by_email_or_name_with_candidates(string $query): array
{
    $q = trim($query);
    if ($q === '') {
        throw new InvalidArgumentException('User search query cannot be empty.');
    }

    $params = [
        'search' => $q,
        'limit'  => 20,
    ];

    $data = snipeit_request('GET', 'users', $params);

    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No Snipe-IT users found matching '{$q}'.");
    }

    $rows = $data['rows'];

    if (count($rows) === 1) {
        return ['user' => $rows[0], 'candidates' => []];
    }

    $exactEmailMatches = [];
    $exactNameMatches  = [];
    $qLower = strtolower($q);
    foreach ($rows as $row) {
        $email = $row['email'] ?? '';
        $name  = $row['name'] ?? ($row['username'] ?? '');
        if ($email !== '' && strtolower(trim($email)) === $qLower) {
            $exactEmailMatches[] = $row;
        }
        if ($name !== '' && strtolower(trim($name)) === $qLower) {
            $exactNameMatches[] = $row;
        }
    }

    if (count($exactEmailMatches) === 1) {
        return ['user' => $exactEmailMatches[0], 'candidates' => []];
    }
    if (count($exactNameMatches) === 1) {
        return ['user' => $exactNameMatches[0], 'candidates' => []];
    }

    $candidates = $rows;
    if (!empty($exactEmailMatches)) {
        $candidates = $exactEmailMatches;
    } elseif (!empty($exactNameMatches)) {
        $candidates = $exactNameMatches;
    }

    return ['user' => null, 'candidates' => $candidates];
}

/**
 * Check out a single asset to a Snipe-IT user by ID.
 *
 * Uses POST /hardware/{id}/checkout
 *
 * @param int         $assetId
 * @param int         $userId
 * @param string      $note
 * @param string|null $expectedCheckin ISO datetime string for expected checkin
 * @return void
 * @throws Exception
 */
function checkout_asset_to_user(int $assetId, int $userId, string $note = '', ?string $expectedCheckin = null): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for checkout.');
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID for checkout.');
    }

    $payload = [
        'checkout_to_type' => 'user',
        // Snipe-IT checkout expects these for user checkouts
        'checkout_to_id'   => $userId,
        'assigned_user'    => $userId,
    ];

    if ($note !== '') {
        $payload['note'] = $note;
    }
    if (!empty($expectedCheckin)) {
        // $expectedCheckin is UTC – convert to snipe_tz for Snipe-IT
        $snipeTz = snipe_get_timezone();
        try {
            $dt = new DateTime($expectedCheckin, new DateTimeZone('UTC'));
            $dt->setTimezone($snipeTz);
            $snipeDateTime = $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $snipeDateTime = $expectedCheckin;
        }
        $payload['expected_checkin'] = $snipeDateTime;
    }

    $resp = snipeit_request('POST', 'hardware/' . $assetId . '/checkout', $payload);

    // Basic sanity check: API should report success
    $status = $resp['status'] ?? 'success';

    // Flatten any messages into a readable string
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';

    // Treat missing status as success unless we spotted explicit error messages
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT checkout did not succeed: ' . $message);
    }

    // Set custom field via PUT (checkout endpoint ignores custom fields)
    if (!empty($expectedCheckin)) {
        $customField = snipe_get_expected_checkin_custom_field();
        if ($customField !== null) {
            snipeit_request('PUT', 'hardware/' . $assetId, [
                $customField => $snipeDateTime,
            ]);
        }
    }
}

/**
 * Update the expected check-in date for an asset.
 *
 * @param int    $assetId
 * @param string $expectedDate ISO date (YYYY-MM-DD)
 * @return void
 * @throws Exception
 */
function update_asset_expected_checkin(int $assetId, string $expectedDate): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    $expectedDate = trim($expectedDate);
    if ($expectedDate === '') {
        throw new InvalidArgumentException('Expected check-in date cannot be empty.');
    }

    // $expectedDate is in app_tz (from user input) – convert to snipe_tz
    $appTz = app_get_timezone();
    $snipeTz = snipe_get_timezone();
    try {
        $dt = new DateTime($expectedDate, $appTz);
        if ($snipeTz && $appTz && $snipeTz->getName() !== $appTz->getName()) {
            $dt->setTimezone($snipeTz);
        }
        $snipeDateTime = $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $snipeDateTime = $expectedDate;
    }

    // Snipe-IT stores expected_checkin as DATE (no time), so send date-only
    // for the native field; the custom field keeps the full datetime.
    $snipeDate = (new DateTime($snipeDateTime))->format('Y-m-d');

    $payload = [
        'expected_checkin' => $snipeDateTime, // Testing with DateTime so time shows in logging.
    ];

    // Write the full datetime to the custom field so the time is preserved
    $customField = snipe_get_expected_checkin_custom_field();
    if ($customField !== null) {
        $payload[$customField] = $snipeDateTime;
    }

    $resp = snipeit_request('PUT', 'hardware/' . $assetId, $payload);
    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Failed to update expected check-in: ' . $message);
    }
}

/**
 * Check in a single asset in Snipe-IT by ID.
 *
 * @param int    $assetId
 * @param string $note
 * @return void
 * @throws Exception
 */
function checkin_asset(int $assetId, string $note = ''): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for checkin.');
    }

    $payload = [];
    if ($note !== '') {
        $payload['note'] = $note;
    }

    $resp = snipeit_request('POST', 'hardware/' . $assetId . '/checkin', $payload);

    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT checkin did not succeed: ' . $message);
    }

    // Clear expected checkin custom field (checkin endpoint doesn't touch custom fields)
    $customField = snipe_get_expected_checkin_custom_field();
    if ($customField !== null) {
        snipeit_request('PUT', 'hardware/' . $assetId, [
            $customField => '',
        ]);
    }
}

/**
 * Add a note to an asset's activity log in Snipe-IT.
 *
 * @param int    $assetId
 * @param string $note
 * @return void
 * @throws Exception
 */
function add_asset_note(int $assetId, string $note): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for note.');
    }
    if ($note === '') {
        throw new InvalidArgumentException('Note text cannot be empty.');
    }

    $resp = snipeit_request('POST', 'notes/' . $assetId . '/store', ['note' => $note]);

    $status = $resp['status'] ?? 'success';
    if ($status !== 'success') {
        $message = $resp['messages'] ?? ($resp['message'] ?? 'Unknown API response');
        if (is_array($message)) {
            $flat = [];
            array_walk_recursive($message, function ($val) use (&$flat) {
                if (is_string($val) && trim($val) !== '') {
                    $flat[] = $val;
                }
            });
            $message = $flat ? implode('; ', $flat) : 'Unknown API response';
        }
        throw new Exception('Snipe-IT add note did not succeed: ' . $message);
    }
}

/**
 * Fetch checked-out assets (requestable only) directly from Snipe-IT.
 *
 * @param bool $overdueOnly
 * @param int $maxResults Safety cap for total hardware rows fetched (0 to use config)
 * @return array
 * @throws Exception
 */
function fetch_checked_out_assets_from_snipeit(bool $overdueOnly = false, int $maxResults = 0): array
{
    if ($maxResults <= 0) {
        $maxResults = PHP_INT_MAX;
    }
    $all = [];
    $limit = min(200, $maxResults);
    $offset = 0;

    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        $data = snipeit_request('GET', 'hardware', $params);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        if (empty($rows)) {
            break;
        }
        $all = array_merge($all, $rows);
        $count = count($rows);
        $offset += $limit;

        if ($count < $limit || count($all) >= $maxResults) {
            break;
        }
    } while (true);

    $now = time();
    $filtered = [];
    foreach ($all as $row) {
        // Only requestable assets
        if (empty($row['requestable'])) {
            continue;
        }

        // Consider "checked out" if assigned_to/user is present
        $assigned = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
        if ($assigned === '') {
            continue;
        }

        // Normalize date fields
        $lastCheckout = $row['last_checkout'] ?? '';
        if (is_array($lastCheckout)) {
            $lastCheckout = $lastCheckout['datetime'] ?? ($lastCheckout['date'] ?? '');
        }
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if (is_array($expectedCheckin)) {
            $expectedCheckin = $expectedCheckin['datetime'] ?? ($expectedCheckin['date'] ?? '');
        }

        // Prefer custom field value (full datetime) over date-only expected_checkin
        $customField = snipe_get_expected_checkin_custom_field();
        if ($customField !== null) {
            $customFields = $row['custom_fields'] ?? [];
            if (is_array($customFields)) {
                foreach ($customFields as $cf) {
                    if (is_array($cf) && ($cf['field'] ?? '') === $customField) {
                        $cfValue = trim((string)($cf['value'] ?? ''));
                        if ($cfValue !== '') {
                            $expectedCheckin = $cfValue;
                        }
                        break;
                    }
                }
            }
        }

        // Overdue check
        if ($overdueOnly) {
            // If Snipe-IT returns only a date (no time), treat it as due by end-of-day rather than midnight.
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            // Snipe-IT dates are in the Snipe-IT server's timezone.
            $snipeTz = snipe_get_timezone();
            try {
                $dt = new DateTime($normalizedExpected, $snipeTz);
                $expTs = $dt->getTimestamp();
            } catch (Throwable $e) {
                $expTs = null;
            }
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $row['_last_checkout_norm']   = $lastCheckout;
        $row['_expected_checkin_norm'] = $expectedCheckin;

        $filtered[] = $row;
    }

    return $filtered;
}

/**
 * Fetch all Snipe-IT groups (for settings UI dropdown).
 *
 * @return array  Array of ['id'=>int,'name'=>string] entries
 * @throws Exception
 */
function get_snipeit_groups(): array
{
    $data = snipeit_request('GET', 'groups', ['limit' => 500]);
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
    $result = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $name = $row['name'] ?? '';
        if ($id > 0 && $name !== '') {
            $result[] = ['id' => $id, 'name' => $name];
        }
    }
    usort($result, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

/**
 * Fetch a single Snipe-IT user by ID.
 *
 * @param int $userId
 * @return array
 * @throws Exception
 */
function get_user_by_id(int $userId): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID.');
    }
    return snipeit_request('GET', 'users/' . $userId);
}

/**
 * Get the groups a Snipe-IT user belongs to.
 *
 * Returns [['id'=>int,'name'=>string], ...].
 * Uses session cache with 5-min TTL. If $userData is provided with
 * groups.rows already populated, skips the API call.
 *
 * @param int        $userId
 * @param array|null $userData  Optional pre-fetched user data
 * @return array
 */
function get_user_groups(int $userId, ?array $userData = null): array
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    // Session cache with 5-min TTL
    $sessionKey = 'snipeit_user_groups';
    $sessionTtl = 300;
    if (isset($_SESSION[$sessionKey][$userId])) {
        $entry = $_SESSION[$sessionKey][$userId];
        if (isset($entry['ts'], $entry['data']) && (time() - (int)$entry['ts']) <= $sessionTtl) {
            $cache[$userId] = $entry['data'];
            return $cache[$userId];
        }
    }

    // Check if userData already has groups
    $rows = null;
    if ($userData !== null && isset($userData['groups']['rows']) && is_array($userData['groups']['rows'])) {
        $rows = $userData['groups']['rows'];
    }

    if ($rows === null) {
        try {
            $data = get_user_by_id($userId);
            $rows = isset($data['groups']['rows']) && is_array($data['groups']['rows'])
                ? $data['groups']['rows']
                : [];
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    $groups = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $name = $row['name'] ?? '';
        if ($id > 0) {
            $groups[] = ['id' => $id, 'name' => $name];
        }
    }

    $cache[$userId] = $groups;
    $_SESSION[$sessionKey][$userId] = ['ts' => time(), 'data' => $groups];
    return $groups;
}

/**
 * Fetch hardware assets currently checked out to a specific Snipe-IT user.
 *
 * Uses GET /hardware?assigned_to={userId} and paginates through all results.
 * Returns the raw asset rows from the API response.
 *
 * @param int $userId  Snipe-IT user ID
 * @return array       Array of asset rows
 * @throws Exception
 */
function get_assets_checked_out_to_user(int $userId): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID.');
    }

    $data = snipeit_request('GET', 'users/' . $userId . '/assets');
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    return $rows;
}

/**
 * Fetch custom field definitions for a fieldset.
 *
 * @param int $fieldsetId
 * @return array
 * @throws Exception
 */
function get_fieldset_fields(int $fieldsetId): array
{
    if ($fieldsetId <= 0) {
        return [];
    }
    $data = snipeit_request('GET', 'fieldsets/' . $fieldsetId);
    // Fields are nested: { "fields": { "total": N, "rows": [...] } }
    if (isset($data['fields']['rows']) && is_array($data['fields']['rows'])) {
        return $data['fields']['rows'];
    }
    // Fallback: top-level rows (in case of alternate API response format)
    return isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
}

/**
 * Get authorization requirements for a model by scanning its requestable
 * assets' custom field values for "Certification Needed" and "Access Level".
 *
 * Returns ['certs' => [...], 'access_levels' => [...]].
 * Values are full group names (e.g. 'Cert - Grip Truck', 'Access - Advanced').
 * Static-cached per request.
 *
 * @param int $modelId
 * @return array{certs: string[], access_levels: string[]}
 */
function get_model_auth_requirements(int $modelId): array
{
    static $cache = [];
    if (isset($cache[$modelId])) {
        return $cache[$modelId];
    }

    $cache[$modelId] = ['certs' => [], 'access_levels' => []];

    try {
        $assets = list_assets_by_model($modelId, 500);
        $certs = [];
        $accessLevels = [];

        foreach ($assets as $asset) {
            if (empty($asset['requestable'])) {
                continue;
            }
            $customFields = $asset['custom_fields'] ?? [];
            if (!is_array($customFields)) {
                continue;
            }
            foreach ($customFields as $fieldKey => $cf) {
                if (!is_array($cf)) {
                    continue;
                }
                $fieldName = (string)$fieldKey;
                $value = trim((string)($cf['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                if (stripos($fieldName, 'Access Level') !== false) {
                    $accessLevels[$value] = true;
                } elseif (stripos($fieldName, 'Certification Needed') !== false) {
                    $certs[$value] = true;
                }
            }
        }

        $cache[$modelId] = [
            'certs' => array_keys($certs),
            'access_levels' => array_keys($accessLevels),
        ];
    } catch (Throwable $e) {
        // Silently fail — no auth requirements if API fails
    }

    return $cache[$modelId];
}

/**
 * Backward-compatible alias for get_model_auth_requirements().
 * Returns only the cert names (without 'Cert - ' prefix) for legacy callers.
 *
 * @param int $modelId
 * @return array
 * @deprecated Use get_model_auth_requirements() instead
 */
function get_model_certification_requirements(int $modelId): array
{
    $reqs = get_model_auth_requirements($modelId);
    return $reqs['certs'];
}

/**
 * Fetch checked-out assets from the local cache table.
 *
 * @param bool $overdueOnly
 * @return array
 * @throws Exception
 */
function list_checked_out_assets(bool $overdueOnly = false): array
{
    global $pdo;
    require_once SRC_PATH . '/db.php';

    $sql = "
        SELECT
            asset_id,
            asset_tag,
            asset_name,
            model_id,
            model_name,
            assigned_to_id,
            assigned_to_name,
            assigned_to_email,
            assigned_to_username,
            status_label,
            last_checkout,
            expected_checkin
        FROM checked_out_asset_cache
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $now = time();
    $results = [];
    foreach ($rows as $row) {
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if ($overdueOnly) {
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            // Cached dates are in the Snipe-IT server's timezone.
            $snipeTz = snipe_get_timezone();
            try {
                $dt = new DateTime($normalizedExpected, $snipeTz);
                $expTs = $dt->getTimestamp();
            } catch (Throwable $e) {
                $expTs = null;
            }
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $assigned = [];
        $assignedId = (int)($row['assigned_to_id'] ?? 0);
        if ($assignedId > 0) {
            $assigned['id'] = $assignedId;
        }
        $assignedEmail = $row['assigned_to_email'] ?? '';
        $assignedName = $row['assigned_to_name'] ?? '';
        $assignedUsername = $row['assigned_to_username'] ?? '';
        if ($assignedEmail !== '') {
            $assigned['email'] = $assignedEmail;
        }
        if ($assignedUsername !== '') {
            $assigned['username'] = $assignedUsername;
        }
        if ($assignedName !== '') {
            $assigned['name'] = $assignedName;
        }

        $item = [
            'id' => (int)($row['asset_id'] ?? 0),
            'asset_tag' => $row['asset_tag'] ?? '',
            'name' => $row['asset_name'] ?? '',
            'model' => [
                'id' => (int)($row['model_id'] ?? 0),
                'name' => $row['model_name'] ?? '',
            ],
            'status_label' => $row['status_label'] ?? '',
            'last_checkout' => $row['last_checkout'] ?? '',
            'expected_checkin' => $expectedCheckin,
            '_last_checkout_norm' => $row['last_checkout'] ?? '',
            '_expected_checkin_norm' => $expectedCheckin,
        ];

        if (!empty($assigned)) {
            $item['assigned_to'] = $assigned;
        } elseif ($assignedName !== '') {
            $item['assigned_to_fullname'] = $assignedName;
        }

        $results[] = $item;
    }

    return $results;
}

/**
 * Bulk-fetch all hardware and compute per-model stats for the catalogue.
 *
 * Replaces N per-model API calls with a single paginated fetch of all assets,
 * then computes requestable counts, undeployable info, and cert requirements
 * for each requested model ID.
 *
 * @param int[] $modelIds  Model IDs to compute stats for
 * @return array<int, array{requestable_count: int, undeployable: array{undeployable_count: int, status_names: string[]}, certs: string[]}>
 */
function prefetch_catalogue_model_stats(array $modelIds): array
{
    static $cache = null;
    static $cacheKey = null;

    $ids = array_unique(array_filter(array_map('intval', $modelIds)));
    sort($ids);
    $key = implode(',', $ids);

    if ($cache !== null && $cacheKey === $key) {
        return $cache;
    }

    // Fetch all hardware in bulk (paginated)
    $all = [];
    $limit = 500;
    $offset = 0;

    do {
        $data = snipeit_request('GET', 'hardware', [
            'limit'  => $limit,
            'offset' => $offset,
        ]);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        if (empty($rows)) {
            break;
        }
        $all = array_merge($all, $rows);
        $offset += $limit;
        if (count($rows) < $limit) {
            break;
        }
    } while (true);

    // Build lookup set for O(1) membership check
    $idSet = array_flip($ids);

    // Initialize results for all requested model IDs
    $results = [];
    foreach ($ids as $id) {
        $results[$id] = [
            'requestable_count' => 0,
            'undeployable' => [
                'undeployable_count' => 0,
                'status_names' => [],
            ],
            'certs' => [],
            'access_levels' => [],
        ];
    }

    // Single pass through all assets
    $statusSets = []; // model_id => [name => true]
    $certSets   = []; // model_id => [cert_value => true]
    $accessSets = []; // model_id => [access_value => true]

    foreach ($all as $asset) {
        $mid = (int)($asset['model']['id'] ?? 0);
        if (!isset($idSet[$mid])) {
            continue;
        }
        if (empty($asset['requestable'])) {
            continue;
        }

        $results[$mid]['requestable_count']++;

        // Undeployable check
        if (!is_asset_deployable($asset)) {
            $results[$mid]['undeployable']['undeployable_count']++;
            $statusName = $asset['status_label']['name'] ?? 'Unknown';
            $statusSets[$mid][$statusName] = true;
        }

        // Authorization requirements from custom field values
        $customFields = $asset['custom_fields'] ?? [];
        if (is_array($customFields)) {
            foreach ($customFields as $fieldKey => $cf) {
                if (!is_array($cf)) {
                    continue;
                }
                $fieldName = (string)$fieldKey;
                $value = trim((string)($cf['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                if (stripos($fieldName, 'Access Level') !== false) {
                    $accessSets[$mid][$value] = true;
                } elseif (stripos($fieldName, 'Certification Needed') !== false) {
                    $certSets[$mid][$value] = true;
                }
            }
        }
    }

    // Flatten sets into arrays
    foreach ($ids as $id) {
        if (!empty($statusSets[$id])) {
            $results[$id]['undeployable']['status_names'] = array_keys($statusSets[$id]);
        }
        if (!empty($certSets[$id])) {
            $results[$id]['certs'] = array_keys($certSets[$id]);
        }
        if (!empty($accessSets[$id])) {
            $results[$id]['access_levels'] = array_keys($accessSets[$id]);
        }
    }

    $cache = $results;
    $cacheKey = $key;
    return $results;
}

/**
 * Create a maintenance record for an asset in Snipe-IT.
 *
 * @param int    $assetId
 * @param string $title
 * @param string $notes
 * @param string $type  Maintenance type (e.g. 'Repair', 'Maintenance')
 * @return void
 * @throws Exception
 */
if (!function_exists('create_asset_maintenance')) {
    function create_asset_maintenance(int $assetId, string $title, string $notes, string $type = 'Repair'): void
    {
        if ($assetId <= 0) {
            throw new InvalidArgumentException('Invalid asset ID for maintenance.');
        }

        $config = load_config();
        $snipeTz = $config['snipeit']['timezone'] ?? ($config['app']['timezone'] ?? 'UTC');

        $startDate = (new DateTimeImmutable('now', new DateTimeZone($snipeTz)))->format('Y-m-d');

        $supplierId = (int)($config['snipeit']['maintenance_supplier_id'] ?? 0);

        $payload = [
            'asset_id'                 => $assetId,
            'name'                     => $title,
            'asset_maintenance_type'   => $type,
            'start_date'               => $startDate,
            'notes'                    => $notes,
        ];
        if ($supplierId > 0) {
            $payload['supplier_id'] = $supplierId;
        }

        $resp = snipeit_request('POST', 'maintenances', $payload);

        $status = $resp['status'] ?? 'success';
        if ($status !== 'success') {
            $message = $resp['messages'] ?? ($resp['message'] ?? 'Unknown API response');
            if (is_array($message)) {
                $flat = [];
                array_walk_recursive($message, function ($val) use (&$flat) {
                    if (is_string($val) && trim($val) !== '') {
                        $flat[] = $val;
                    }
                });
                $message = $flat ? implode('; ', $flat) : 'Unknown API response';
            }
            throw new Exception('Snipe-IT create maintenance failed: ' . $message);
        }
    }
}

/**
 * Update an asset's status label in Snipe-IT.
 *
 * @param int $assetId
 * @param int $statusId  Snipe-IT status label ID
 * @return void
 * @throws Exception
 */
if (!function_exists('update_asset_status')) {
    function update_asset_status(int $assetId, int $statusId): void
    {
        if ($assetId <= 0) {
            throw new InvalidArgumentException('Invalid asset ID for status update.');
        }
        if ($statusId <= 0) {
            throw new InvalidArgumentException('Invalid status label ID.');
        }

        $resp = snipeit_request('PUT', 'hardware/' . $assetId, [
            'status_id' => $statusId,
        ]);

        $status = $resp['status'] ?? 'success';
        if ($status !== 'success') {
            $message = $resp['messages'] ?? ($resp['message'] ?? 'Unknown API response');
            if (is_array($message)) {
                $flat = [];
                array_walk_recursive($message, function ($val) use (&$flat) {
                    if (is_string($val) && trim($val) !== '') {
                        $flat[] = $val;
                    }
                });
                $message = $flat ? implode('; ', $flat) : 'Unknown API response';
            }
            throw new Exception('Snipe-IT status update failed: ' . $message);
        }
    }
}

/**
 * Look up a Snipe-IT status label ID by name.
 *
 * @param string $name  Status label name to search for
 * @return int|null  Status label ID, or null if not found
 */
if (!function_exists('get_status_label_id_by_name')) {
    function get_status_label_id_by_name(string $name): ?int
    {
        static $cache = [];
        $key = strtolower(trim($name));
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $resp = snipeit_request('GET', 'statuslabels', ['search' => $name]);
            $rows = $resp['rows'] ?? [];
            foreach ($rows as $row) {
                if (strtolower(trim((string)($row['name'] ?? ''))) === $key) {
                    $cache[$key] = (int)$row['id'];
                    return $cache[$key];
                }
            }
        } catch (Throwable $e) {
            // Lookup failure — treat as not found
        }

        $cache[$key] = null;
        return null;
    }
}

<?php
// checkout_rules.php
//
// Centralised policy enforcement for checkout duration limits,
// certification requirements, and single active checkout.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/snipeit_client.php';

/**
 * Get checkout limits config, with defaults for missing keys.
 *
 * @return array
 */
function checkout_limits_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $config = load_config();
    $limits = $config['checkout_limits'] ?? [];
    $cfg = [
        'enabled'                => !empty($limits['enabled']),
        'default'                => [
            'max_checkout_hours' => (int)($limits['default']['max_checkout_hours'] ?? 0),
            'max_renewal_hours'  => (int)($limits['default']['max_renewal_hours'] ?? 0),
            'max_total_hours'    => (int)($limits['default']['max_total_hours'] ?? 0),
        ],
        'group_overrides'        => $limits['group_overrides'] ?? [],
        'single_active_checkout' => !empty($limits['single_active_checkout']),
    ];
    return $cfg;
}

/**
 * Get the effective checkout limits for a user (most permissive group wins).
 *
 * @param int $snipeitUserId
 * @return array ['max_checkout_hours'=>int, 'max_renewal_hours'=>int, 'max_total_hours'=>int]
 */
function get_effective_checkout_limits(int $snipeitUserId): array
{
    $cfg = checkout_limits_config();
    $defaults = $cfg['default'];

    if (!$cfg['enabled']) {
        return ['max_checkout_hours' => 0, 'max_renewal_hours' => 0, 'max_total_hours' => 0];
    }

    $overrides = $cfg['group_overrides'];
    if (empty($overrides) || $snipeitUserId <= 0) {
        return $defaults;
    }

    $groups = get_user_groups($snipeitUserId);
    $groupIds = array_map(function ($g) { return (int)$g['id']; }, $groups);

    // Start with defaults, apply most permissive (highest value) per-field.
    // 0 = unlimited, so if any applicable group has 0, that field becomes unlimited.
    $effective = $defaults;
    $hasOverride = false;

    foreach ($groupIds as $gid) {
        if (!isset($overrides[$gid])) {
            continue;
        }
        $hasOverride = true;
        $ov = $overrides[$gid];
        foreach (['max_checkout_hours', 'max_renewal_hours', 'max_total_hours'] as $key) {
            $ovVal = (int)($ov[$key] ?? 0);
            // 0 = unlimited → always wins as "most permissive"
            if ($ovVal === 0) {
                $effective[$key] = 0;
            } elseif ($effective[$key] !== 0) {
                $effective[$key] = max($effective[$key], $ovVal);
            }
            // If effective is already 0 (unlimited), keep it
        }
    }

    // If user has overrides, use the override result (not merged with defaults further);
    // if no overrides matched, defaults apply.
    return $effective;
}

/**
 * Validate a checkout duration against limits.
 *
 * @param int      $snipeitUserId
 * @param DateTime $start
 * @param DateTime $end
 * @return string|null  null if valid, error string if exceeded
 */
function validate_checkout_duration(int $snipeitUserId, DateTime $start, DateTime $end): ?string
{
    $cfg = checkout_limits_config();
    if (!$cfg['enabled']) {
        return null;
    }

    $limits = get_effective_checkout_limits($snipeitUserId);
    $maxHours = $limits['max_checkout_hours'];

    if ($maxHours <= 0) {
        return null; // unlimited
    }

    $diffSeconds = $end->getTimestamp() - $start->getTimestamp();
    $diffHours = $diffSeconds / 3600;

    if ($diffHours > $maxHours) {
        $days = round($maxHours / 24, 1);
        return "Checkout duration exceeds the maximum allowed ({$maxHours} hours / {$days} days). "
             . "Please select a shorter period.";
    }

    return null;
}

/**
 * Validate a renewal duration against renewal and total limits.
 *
 * @param int    $snipeitUserId
 * @param string $currentExpected  Current expected check-in datetime string
 * @param DateTime $newExpected    New proposed expected check-in
 * @param string|null $lastCheckout  Last checkout datetime string (for total calc)
 * @return string|null  null if valid, error string if exceeded
 */
function validate_renewal_duration(int $snipeitUserId, string $currentExpected, DateTime $newExpected, ?string $lastCheckout = null): ?string
{
    $cfg = checkout_limits_config();
    if (!$cfg['enabled']) {
        return null;
    }

    $limits = get_effective_checkout_limits($snipeitUserId);
    $maxRenewalHours = $limits['max_renewal_hours'];
    $maxTotalHours = $limits['max_total_hours'];

    // Check renewal limit: extension from current expected to new expected
    if ($maxRenewalHours > 0 && $currentExpected !== '') {
        try {
            $currentDt = new DateTime($currentExpected);
            $extensionSeconds = $newExpected->getTimestamp() - $currentDt->getTimestamp();
            $extensionHours = $extensionSeconds / 3600;

            if ($extensionHours > $maxRenewalHours) {
                $days = round($maxRenewalHours / 24, 1);
                return "Renewal extension exceeds the maximum allowed ({$maxRenewalHours} hours / {$days} days).";
            }
        } catch (Throwable $e) {
            // Can't parse current expected, skip renewal check
        }
    }

    // Check total limit: from last checkout to new expected
    if ($maxTotalHours > 0 && $lastCheckout !== null && $lastCheckout !== '') {
        try {
            $checkoutDt = new DateTime($lastCheckout);
            $totalSeconds = $newExpected->getTimestamp() - $checkoutDt->getTimestamp();
            $totalHours = $totalSeconds / 3600;

            if ($totalHours > $maxTotalHours) {
                $days = round($maxTotalHours / 24, 1);
                return "Total checkout duration (including renewals) exceeds the maximum allowed ({$maxTotalHours} hours / {$days} days).";
            }
        } catch (Throwable $e) {
            // Can't parse last checkout, skip total check
        }
    }

    return null;
}

/**
 * Get the maximum allowed end DateTime for a checkout.
 *
 * @param int      $snipeitUserId
 * @param DateTime $start
 * @return DateTime|null  null if unlimited
 */
function get_max_checkout_end(int $snipeitUserId, DateTime $start): ?DateTime
{
    $cfg = checkout_limits_config();
    if (!$cfg['enabled']) {
        return null;
    }

    $limits = get_effective_checkout_limits($snipeitUserId);
    $maxHours = $limits['max_checkout_hours'];

    if ($maxHours <= 0) {
        return null;
    }

    $max = clone $start;
    $max->modify("+{$maxHours} hours");
    return $max;
}

/**
 * Get the maximum allowed end DateTime for a renewal.
 *
 * @param int    $snipeitUserId
 * @param string $currentExpected  Current expected check-in
 * @param string $lastCheckout     Original checkout datetime
 * @return DateTime|null  null if unlimited
 */
function get_max_renewal_end(int $snipeitUserId, string $currentExpected, string $lastCheckout): ?DateTime
{
    $cfg = checkout_limits_config();
    if (!$cfg['enabled']) {
        return null;
    }

    $limits = get_effective_checkout_limits($snipeitUserId);
    $maxRenewalHours = $limits['max_renewal_hours'];
    $maxTotalHours = $limits['max_total_hours'];
    $candidates = [];

    if ($maxRenewalHours > 0 && $currentExpected !== '') {
        try {
            $dt = new DateTime($currentExpected);
            $dt->modify("+{$maxRenewalHours} hours");
            $candidates[] = $dt;
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($maxTotalHours > 0 && $lastCheckout !== '') {
        try {
            $dt = new DateTime($lastCheckout);
            $dt->modify("+{$maxTotalHours} hours");
            $candidates[] = $dt;
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (empty($candidates)) {
        return null;
    }

    // Return the minimum (most restrictive) of the candidates
    $min = $candidates[0];
    for ($i = 1; $i < count($candidates); $i++) {
        if ($candidates[$i] < $min) {
            $min = $candidates[$i];
        }
    }

    return $min;
}

/**
 * Check if a user has the required certifications for a set of requirements.
 *
 * @param int   $snipeitUserId
 * @param array $certRequirements  e.g. ['Photography', 'Drone Pilot']
 * @return array  Array of missing cert names. Empty = all satisfied.
 */
function check_user_certifications(int $snipeitUserId, array $certRequirements): array
{
    if (empty($certRequirements) || $snipeitUserId <= 0) {
        return [];
    }

    $groups = get_user_groups($snipeitUserId);
    $groupNames = array_map(function ($g) {
        return strtolower(trim($g['name']));
    }, $groups);

    $missing = [];
    foreach ($certRequirements as $cert) {
        $certLower = strtolower(trim($cert));
        if (!in_array($certLower, $groupNames, true)) {
            $missing[] = $cert;
        }
    }

    return $missing;
}

/**
 * Check if a user currently has any checked-out assets.
 *
 * @param int $snipeitUserId
 * @return bool
 */
function check_user_has_active_checkout(int $snipeitUserId): bool
{
    if ($snipeitUserId <= 0) {
        return false;
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM checked_out_asset_cache
         WHERE assigned_to_id = :uid
    ");
    $stmt->execute([':uid' => $snipeitUserId]);
    return (int)$stmt->fetchColumn() > 0;
}

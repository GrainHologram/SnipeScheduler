<?php
// checkout_rules.php
//
// Centralised policy enforcement for checkout duration limits,
// certification requirements, and single active checkout.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/snipeit_client.php';
require_once __DIR__ . '/opening_hours.php';

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
            'max_advance_days'   => (int)($limits['default']['max_advance_days'] ?? 0),
        ],
        'group_overrides'        => $limits['group_overrides'] ?? [],
        'single_active_checkout' => !empty($limits['single_active_checkout']),
        'staff_date_override'    => $limits['staff_date_override'] ?? true,
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
        return ['max_checkout_hours' => 0, 'max_renewal_hours' => 0, 'max_total_hours' => 0, 'max_advance_days' => 0];
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
        foreach (['max_checkout_hours', 'max_renewal_hours', 'max_total_hours', 'max_advance_days'] as $key) {
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
    $maxEnd = get_max_checkout_end($snipeitUserId, $start);
    if ($maxEnd === null) {
        return null; // unlimited
    }

    if ($end > $maxEnd) {
        $limits = get_effective_checkout_limits($snipeitUserId);
        $maxHours = $limits['max_checkout_hours'];
        $days = round($maxHours / 24, 1);
        return "Checkout duration exceeds the maximum allowed ({$maxHours} hours / {$days} days). "
             . "Please select a shorter period.";
    }

    return null;
}

/**
 * Validate that a reservation start date is within the max advance days limit.
 *
 * @param int      $snipeitUserId
 * @param DateTime $start
 * @return string|null  null if valid, error string if exceeded
 */
function validate_advance_reservation(int $snipeitUserId, DateTime $start): ?string
{
    $cfg = checkout_limits_config();
    if (!$cfg['enabled']) {
        return null;
    }

    $limits = get_effective_checkout_limits($snipeitUserId);
    $maxDays = $limits['max_advance_days'];

    if ($maxDays <= 0) {
        return null; // unlimited
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $diffSeconds = $start->getTimestamp() - $now->getTimestamp();
    $diffDays = $diffSeconds / 86400;

    if ($diffDays > $maxDays) {
        return "Reservation start date is too far in the future. You can book up to {$maxDays} day(s) in advance. "
             . "Please select a closer start date.";
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
    $maxEnd = get_max_renewal_end($snipeitUserId, $currentExpected, $lastCheckout ?? '');
    if ($maxEnd === null) {
        return null; // unlimited
    }

    if ($newExpected > $maxEnd) {
        $limits = get_effective_checkout_limits($snipeitUserId);
        $maxRenewalHours = $limits['max_renewal_hours'];
        $maxTotalHours = $limits['max_total_hours'];

        // Determine which limit is the binding constraint
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
                // ignore
            }
        }

        if ($maxTotalHours > 0 && $lastCheckout !== null && $lastCheckout !== '') {
            $days = round($maxTotalHours / 24, 1);
            return "Total checkout duration (including renewals) exceeds the maximum allowed ({$maxTotalHours} hours / {$days} days).";
        }

        // Generic fallback
        return "Renewal exceeds the maximum allowed duration.";
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

    // Extend past closed hours to the first available open slot
    $intervalMinutes = (int)(load_config()['app']['slot_interval_minutes'] ?? 15);
    $firstSlot = oh_first_available_slot($max, $intervalMinutes);
    if ($firstSlot !== null) {
        $max = $firstSlot;
    }

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
    $intervalMinutes = (int)(load_config()['app']['slot_interval_minutes'] ?? 15);
    $candidates = [];

    if ($maxRenewalHours > 0 && $currentExpected !== '') {
        try {
            $dt = new DateTime($currentExpected);
            $dt->modify("+{$maxRenewalHours} hours");
            // Extend past closed hours to the first available open slot
            $slot = oh_first_available_slot($dt, $intervalMinutes);
            $candidates[] = ($slot !== null) ? $slot : $dt;
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($maxTotalHours > 0 && $lastCheckout !== '') {
        try {
            $dt = new DateTime($lastCheckout);
            $dt->modify("+{$maxTotalHours} hours");
            // Extend past closed hours to the first available open slot
            $slot = oh_first_available_slot($dt, $intervalMinutes);
            $candidates[] = ($slot !== null) ? $slot : $dt;
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
 * Check model authorization: certifications and access level requirements.
 *
 * Priority: if certs exist, check certs only. If no certs but access levels
 * exist, check access levels (with Faculty override).
 *
 * @param int   $snipeitUserId
 * @param array $authReqs  ['certs' => [...], 'access_levels' => [...]]
 * @return array  ['certs' => [...missing...], 'access_levels' => [...missing...]] or empty
 */
function check_model_authorization(int $snipeitUserId, array $authReqs): array
{
    $certs = $authReqs['certs'] ?? [];
    $accessLevels = $authReqs['access_levels'] ?? [];

    if ((empty($certs) && empty($accessLevels)) || $snipeitUserId <= 0) {
        return [];
    }

    $groups = get_user_groups($snipeitUserId);
    $groupNames = array_map(function ($g) {
        return strtolower(trim($g['name']));
    }, $groups);

    // Cert takes priority: if cert requirements exist, check only certs
    if (!empty($certs)) {
        $missing = [];
        foreach ($certs as $cert) {
            if (!in_array(strtolower(trim($cert)), $groupNames, true)) {
                $missing[] = $cert;
            }
        }
        return !empty($missing) ? ['certs' => $missing] : [];
    }

    // No certs — fall back to access level check
    // User passes if they belong to ANY required access level group OR 'Access - Faculty'
    $hasFaculty = in_array('access - faculty', $groupNames, true);
    if ($hasFaculty) {
        return [];
    }

    $hasAny = false;
    foreach ($accessLevels as $level) {
        if (in_array(strtolower(trim($level)), $groupNames, true)) {
            $hasAny = true;
            break;
        }
    }

    return $hasAny ? [] : ['access_levels' => $accessLevels];
}

/**
 * Backward-compatible wrapper around check_model_authorization().
 *
 * @param int   $snipeitUserId
 * @param array $certRequirements  e.g. ['Cert - Grip Truck']
 * @return array  Array of missing cert names. Empty = all satisfied.
 * @deprecated Use check_model_authorization() instead
 */
function check_user_certifications(int $snipeitUserId, array $certRequirements): array
{
    $result = check_model_authorization($snipeitUserId, [
        'certs' => $certRequirements,
        'access_levels' => [],
    ]);
    return $result['certs'] ?? [];
}

/**
 * Check if a user belongs to at least one "Access - *" group in Snipe-IT.
 *
 * @param int $snipeitUserId
 * @return bool
 */
function check_user_has_access_group(int $snipeitUserId): bool
{
    if ($snipeitUserId <= 0) {
        return false;
    }

    $groups = get_user_groups($snipeitUserId);
    foreach ($groups as $g) {
        $name = trim($g['name'] ?? '');
        if (preg_match('/^Access\s*-/i', $name)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if a user currently has any active checkouts.
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
          FROM checkouts
         WHERE snipeit_user_id = :uid
           AND parent_checkout_id IS NULL
           AND status IN ('open','partial')
    ");
    $stmt->execute([':uid' => $snipeitUserId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Get the user's active parent checkout, if any.
 *
 * @param int $snipeitUserId
 * @return array|null  Checkout row or null
 */
function get_user_active_checkout(int $snipeitUserId): ?array
{
    if ($snipeitUserId <= 0) {
        return null;
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';

    $stmt = $pdo->prepare("
        SELECT *
          FROM checkouts
         WHERE snipeit_user_id = :uid
           AND parent_checkout_id IS NULL
           AND status IN ('open','partial')
         ORDER BY created_at DESC
         LIMIT 1
    ");
    $stmt->execute([':uid' => $snipeitUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

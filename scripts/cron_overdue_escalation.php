<?php
// cron_overdue_escalation.php
// Tiered overdue escalation: sends progressively urgent notifications for overdue checkouts.
//
// Reads escalation tiers from config['overdue']['tiers']. Each tier specifies how many hours
// overdue triggers it and which channels (user/staff email/discord) to notify.
// Tracks sent notifications in the notification_log table to avoid duplicates.
//
// Run via cron, e.g.:
//   */15 * * * * /usr/bin/php /path/to/scripts/cron_overdue_escalation.php >> /var/log/layout_overdue_escalation.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/notifications.php';
require_once SRC_PATH . '/activity_log.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build the escalation message lines for a given tier and set of overdue items.
 *
 * @param int    $tierIndex   Zero-based tier index.
 * @param string $tierLabel   Human-readable tier label from config.
 * @param int    $hoursOverdue Actual hours the checkout is overdue.
 * @param string $endDisplay  Formatted due date/time string.
 * @param array  $items       Array of overdue checkout_items rows.
 * @return array{subject: string, body_lines: string[]}
 */
function build_escalation_message(int $tierIndex, string $tierLabel, int $hoursOverdue, string $endDisplay, array $items): array
{
    $config  = load_config();
    $appName = $config['app']['name'] ?? 'SnipeScheduler';

    // Build asset list text
    $assetLines = [];
    foreach ($items as $item) {
        $tag   = $item['asset_tag'] ?? '';
        $name  = $item['asset_name'] ?? '';
        $model = $item['model_name'] ?? '';
        $label = $tag;
        if ($name !== '') {
            $label .= " ({$name})";
        }
        if ($model !== '') {
            $label .= " - {$model}";
        }
        $assetLines[] = "  - {$label}";
    }
    $assetList = implode("\n", $assetLines);

    $daysOverdue = max(1, (int)floor($hoursOverdue / 24));

    // Escalating tone based on tier index
    switch ($tierIndex) {
        case 0:
            $subject  = 'Late notice';
            $opening  = "Friendly reminder: your checkout was due {$endDisplay}. Please return at your earliest convenience.";
            break;
        case 1:
            $subject  = 'Overdue - grace period expired';
            $opening  = "Your checkout is now {$hoursOverdue} hours overdue. Grace period has ended. Please return immediately.";
            break;
        case 2:
            $subject  = "OVERDUE - {$daysOverdue} days past due";
            $opening  = "OVERDUE: Your checkout is {$daysOverdue} days past due. Continued non-return may result in policy consequences.";
            break;
        default:
            $subject  = "URGENT - {$daysOverdue} days overdue, policy action pending";
            $opening  = "URGENT: Your checkout is {$daysOverdue} days overdue. Policy action will be applied if not returned promptly.";
            break;
    }

    $bodyLines = [
        $opening,
        '',
        "Due: {$endDisplay}",
        "Tier: {$tierLabel}",
        '',
        'Overdue assets:',
    ];
    foreach ($assetLines as $line) {
        $bodyLines[] = $line;
    }

    return [
        'subject'    => $subject,
        'body_lines' => $bodyLines,
    ];
}

/**
 * Get the Discord embed color for a tier index.
 */
function tier_discord_color(int $tierIndex): int
{
    switch ($tierIndex) {
        case 0:  return DISCORD_COLOR_YELLOW;
        case 1:  return DISCORD_COLOR_ORANGE;
        case 2:  return DISCORD_COLOR_RED;
        default: return DISCORD_COLOR_DARK_RED;
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

try {
    $config = load_config();

    // Load tiers from config.
    $tiers = $config['overdue']['tiers'] ?? [];
    if (empty($tiers)) {
        echo sprintf("[%s] No escalation tiers configured (overdue.tiers). Nothing to do.\n", date('Y-m-d H:i:s'));
        exit(0);
    }

    // Sort tiers by hours_overdue descending so we match highest tier first.
    usort($tiers, static function (array $a, array $b): int {
        return ((int)($b['hours_overdue'] ?? 0)) - ((int)($a['hours_overdue'] ?? 0));
    });

    // Also keep an indexed version keyed by original tier index for message building.
    // We need the original sorted-ascending index for tone escalation.
    $tiersAsc = $config['overdue']['tiers'];
    usort($tiersAsc, static function (array $a, array $b): int {
        return ((int)($a['hours_overdue'] ?? 0)) - ((int)($b['hours_overdue'] ?? 0));
    });
    // Map hours_overdue to ascending index for tone lookup.
    $tierIndexMap = [];
    foreach ($tiersAsc as $idx => $t) {
        $tierIndexMap[(int)($t['hours_overdue'] ?? 0)] = $idx;
    }

    // Staff recipients from config.
    $staffEmailsRaw = trim($config['app']['overdue_staff_email'] ?? '');
    $staffNamesRaw  = trim($config['app']['overdue_staff_name'] ?? '');
    $staffEmails    = $staffEmailsRaw !== '' ? array_map('trim', explode(',', $staffEmailsRaw)) : [];
    $staffNames     = $staffNamesRaw !== ''  ? array_map('trim', explode(',', $staffNamesRaw))  : [];

    // Query all overdue checkout items.
    $sql = "
        SELECT ci.id AS checkout_item_id, ci.checkout_id, ci.asset_tag, ci.asset_name, ci.model_name,
               c.user_email, c.user_name, c.end_datetime,
               TIMESTAMPDIFF(HOUR, c.end_datetime, NOW()) AS hours_overdue
          FROM checkout_items ci
          JOIN checkouts c ON c.id = ci.checkout_id
         WHERE c.status IN ('open', 'partial')
           AND ci.checked_in_at IS NULL
           AND c.end_datetime < NOW()
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo sprintf("[%s] No overdue checkout items found.\n", date('Y-m-d H:i:s'));
        exit(0);
    }

    // Group items by checkout_id.
    $checkoutGroups = [];
    foreach ($rows as $row) {
        $cid = (int)$row['checkout_id'];
        if (!isset($checkoutGroups[$cid])) {
            $checkoutGroups[$cid] = [
                'checkout_id'   => $cid,
                'user_email'    => $row['user_email'] ?? '',
                'user_name'     => $row['user_name'] ?? '',
                'end_datetime'  => $row['end_datetime'] ?? '',
                'hours_overdue' => (int)($row['hours_overdue'] ?? 0),
                'items'         => [],
            ];
        }
        $checkoutGroups[$cid]['items'][] = $row;
        // Use the max hours_overdue across items (they share the same checkout end_datetime,
        // but in case of rounding differences, take the max).
        $checkoutGroups[$cid]['hours_overdue'] = max(
            $checkoutGroups[$cid]['hours_overdue'],
            (int)($row['hours_overdue'] ?? 0)
        );
    }

    // Prepare notification_log check statement.
    $checkLogStmt = $pdo->prepare("
        SELECT COUNT(*) FROM notification_log
         WHERE checkout_id = :checkout_id
           AND escalation_tier = :tier
    ");

    // Prepare notification_log insert statement.
    $insertLogStmt = $pdo->prepare("
        INSERT INTO notification_log (checkout_id, user_email, escalation_tier, channel, sent_at)
        VALUES (:checkout_id, :user_email, :tier, :channel, NOW())
    ");

    $checkoutsProcessed = 0;
    $notificationsSent  = 0;

    foreach ($checkoutGroups as $checkout) {
        $checkoutId   = $checkout['checkout_id'];
        $hoursOverdue = $checkout['hours_overdue'];

        // Find the highest applicable tier.
        $matchedTier      = null;
        $matchedTierHours = null;
        foreach ($tiers as $tier) {
            $tierHours = (int)($tier['hours_overdue'] ?? 0);
            if ($hoursOverdue >= $tierHours) {
                $matchedTier      = $tier;
                $matchedTierHours = $tierHours;
                break; // tiers are sorted descending, first match is highest
            }
        }

        if ($matchedTier === null) {
            continue; // not overdue enough for any tier
        }

        // Determine the tier index (ascending order) for this tier.
        $tierIndex = $tierIndexMap[$matchedTierHours] ?? 0;

        // Check if we already sent for this checkout at this tier.
        $checkLogStmt->execute([
            ':checkout_id' => $checkoutId,
            ':tier'        => $tierIndex,
        ]);
        $alreadySent = (int)$checkLogStmt->fetchColumn() > 0;

        if ($alreadySent) {
            continue;
        }

        $checkoutsProcessed++;

        // Format the due date for display.
        // end_datetime is stored in UTC; use UTC as source timezone for app_format_datetime_local.
        $utcTz      = new DateTimeZone('UTC');
        $endDisplay = app_format_datetime_local($checkout['end_datetime'], $config, $utcTz);

        $tierLabel = $matchedTier['label'] ?? "Tier {$tierIndex}";
        $message   = build_escalation_message($tierIndex, $tierLabel, $hoursOverdue, $endDisplay, $checkout['items']);

        $userEmail = $checkout['user_email'];
        $userName  = $checkout['user_name'] ?: $userEmail;

        // Build Discord embed fields for asset list.
        $discordAssetLines = [];
        foreach ($checkout['items'] as $item) {
            $tag   = $item['asset_tag'] ?? '';
            $name  = $item['asset_name'] ?? '';
            $model = $item['model_name'] ?? '';
            $line  = "**{$tag}**";
            if ($name !== '') {
                $line .= " ({$name})";
            }
            if ($model !== '') {
                $line .= " - {$model}";
            }
            $discordAssetLines[] = $line;
        }
        $discordDescription = implode("\n", $message['body_lines']);
        $discordColor       = tier_discord_color($tierIndex);

        $embedFields = [
            ['name' => 'User',    'value' => $userName,  'inline' => true],
            ['name' => 'Due',     'value' => $endDisplay, 'inline' => true],
            ['name' => 'Overdue', 'value' => "{$hoursOverdue} hours", 'inline' => true],
            ['name' => 'Assets',  'value' => implode("\n", $discordAssetLines), 'inline' => false],
        ];

        // --- User email + DM ---
        if (!empty($matchedTier['user_email']) && $userEmail !== '') {
            try {
                $dmData = get_user_discord_dm_data($userEmail);
                send_notification('user', 'overdue_reminder', [
                    'user_email'  => $userEmail,
                    'user_name'   => $userName,
                    'subject'     => $message['subject'],
                    'body_lines'  => $message['body_lines'],
                    'discord_dm_event_key' => 'late_items',
                    'discord_embeds' => [build_discord_embed(
                        $message['subject'],
                        implode("\n", $message['body_lines']),
                        DISCORD_COLOR_RED,
                        $embedFields
                    )],
                ] + $dmData);
                $insertLogStmt->execute([
                    ':checkout_id' => $checkoutId,
                    ':user_email'  => $userEmail,
                    ':tier'        => $tierIndex,
                    ':channel'     => 'email',
                ]);
                $notificationsSent++;
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf(
                    "[%s] Failed user email for checkout %d: %s\n",
                    date('Y-m-d H:i:s'), $checkoutId, $e->getMessage()
                ));
            }
        }

        // --- Staff email ---
        if (!empty($matchedTier['staff_email']) && !empty($staffEmails)) {
            foreach ($staffEmails as $i => $staffEmail) {
                $staffName = $staffNames[$i] ?? $staffEmail;
                try {
                    send_notification('staff', 'overdue_escalation', [
                        'staff_email' => $staffEmail,
                        'staff_name'  => $staffName,
                        'subject'     => $message['subject'] . " - {$userName}",
                        'body_lines'  => array_merge(
                            ["User: {$userName} ({$userEmail})"],
                            $message['body_lines']
                        ),
                    ]);
                    $insertLogStmt->execute([
                        ':checkout_id' => $checkoutId,
                        ':user_email'  => $staffEmail,
                        ':tier'        => $tierIndex,
                        ':channel'     => 'email',
                    ]);
                    $notificationsSent++;
                } catch (Throwable $e) {
                    fwrite(STDERR, sprintf(
                        "[%s] Failed staff email (%s) for checkout %d: %s\n",
                        date('Y-m-d H:i:s'), $staffEmail, $checkoutId, $e->getMessage()
                    ));
                }
            }
        }

        // --- Staff Discord ---
        if (!empty($matchedTier['staff_discord'])) {
            try {
                $embed = build_discord_embed(
                    $tierLabel . ' - ' . $userName,
                    $discordDescription,
                    $discordColor,
                    $embedFields
                );
                send_notification('staff', 'overdue_escalation', [
                    'staff_email'     => '', // no specific staff email for Discord
                    'staff_name'      => '',
                    'discord_content' => '',
                    'discord_embeds'  => [$embed],
                    'body_lines'      => [], // skip email in this call
                    'subject'         => '',
                ]);
                $insertLogStmt->execute([
                    ':checkout_id' => $checkoutId,
                    ':user_email'  => $userEmail,
                    ':tier'        => $tierIndex,
                    ':channel'     => 'discord',
                ]);
                $notificationsSent++;
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf(
                    "[%s] Failed staff Discord for checkout %d: %s\n",
                    date('Y-m-d H:i:s'), $checkoutId, $e->getMessage()
                ));
            }
        }

        // Log the escalation event.
        activity_log_event('overdue_escalation', "Overdue escalation tier {$tierIndex} sent", [
            'actor'        => ['name' => 'cron', 'email' => 'system'],
            'subject_type' => 'checkout',
            'subject_id'   => $checkoutId,
            'metadata'     => [
                'tier_index'    => $tierIndex,
                'tier_label'    => $tierLabel,
                'hours_overdue' => $hoursOverdue,
                'user_email'    => $userEmail,
                'asset_count'   => count($checkout['items']),
            ],
        ]);
    }

    echo sprintf(
        "[%s] Processed %d overdue checkouts, sent %d notifications\n",
        date('Y-m-d H:i:s'),
        $checkoutsProcessed,
        $notificationsSent
    );

} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] Fatal error: %s\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getTraceAsString()
    ));
    exit(1);
}

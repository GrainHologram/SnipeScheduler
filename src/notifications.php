<?php
// src/notifications.php
// Unified notification dispatcher for SnipeScheduler.
// Routes event notifications to email and/or Discord based on config.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/discord.php';

/**
 * Check whether a given event + transport combination is enabled in config.
 *
 * @param string $event     Event name (e.g. 'checkout', 'checkin', 'overdue_reminder').
 * @param string $transport Transport type: 'email' or 'discord'.
 * @return bool
 */
if (!function_exists('is_notification_enabled')) {
    function is_notification_enabled(string $event, string $transport): bool
    {
        $config = load_config();
        $eventConfig = $config['notifications']['events'][$event] ?? null;

        // Default to enabled if the event key does not exist in config.
        if ($eventConfig === null) {
            return true;
        }

        return !empty($eventConfig[$transport]);
    }
}

/**
 * Get Discord DM data for a user by email.
 *
 * @param string $userEmail User's email address.
 * @return array ['discord_user_id' => ..., 'discord_dm_enabled' => bool, 'discord_embed_style' => 'rich'|'plain', 'discord_db_user_id' => int] or empty array.
 */
if (!function_exists('get_user_discord_dm_data')) {
    function get_user_discord_dm_data(string $userEmail): array
    {
        if ($userEmail === '') {
            return [];
        }

        require_once __DIR__ . '/db.php';
        global $pdo;

        try {
            $stmt = $pdo->prepare("SELECT id, discord_user_id, discord_dm_enabled, discord_embed_style FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $userEmail]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$row || empty($row['discord_user_id'])) {
            return [];
        }

        return [
            'discord_user_id'     => $row['discord_user_id'],
            'discord_dm_enabled'  => (bool)$row['discord_dm_enabled'],
            'discord_embed_style' => $row['discord_embed_style'] ?: 'rich',
            'discord_db_user_id'  => (int)$row['id'],
        ];
    }
}

/**
 * Check if a specific DM event is enabled for a user.
 *
 * @param int    $userId   Local user ID.
 * @param string $eventKey Event key (e.g. 'reservation_created').
 * @return bool Defaults to true if no row exists.
 */
if (!function_exists('is_dm_event_enabled')) {
    function is_dm_event_enabled(int $userId, string $eventKey): bool
    {
        if ($userId <= 0 || $eventKey === '') {
            return true;
        }

        require_once __DIR__ . '/db.php';
        global $pdo;

        try {
            $stmt = $pdo->prepare("SELECT enabled FROM user_discord_preferences WHERE user_id = :uid AND event_key = :ek LIMIT 1");
            $stmt->execute([':uid' => $userId, ':ek' => $eventKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return true;
        }

        if (!$row) {
            return true; // default to enabled
        }

        return (bool)$row['enabled'];
    }
}

/**
 * Send a notification through configured channels.
 *
 * @param string $channel 'staff' or 'user' -- determines recipients.
 * @param string $event   Event name (e.g. 'checkout', 'reservation_submitted').
 * @param array  $data    Event payload. Expected keys:
 *                        - user_name, user_email     (for user channel)
 *                        - staff_name, staff_email   (for staff channel)
 *                        - subject                   (email subject line)
 *                        - body_lines                (array of strings for email body)
 *                        - discord_content           (plain text for Discord, optional)
 *                        - discord_embeds            (array of embed arrays, optional)
 */
if (!function_exists('send_notification')) {
    function send_notification(string $channel, string $event, array $data): void
    {
        try {
            $config = load_config();

            $emailEnabled   = is_notification_enabled($event, 'email');
            $discordEnabled = is_notification_enabled($event, 'discord');

            $discordAvailable = !empty($config['notifications']['discord_webhook_url']);

            // Determine recipient based on channel.
            if ($channel === 'user') {
                $recipientEmail = $data['user_email'] ?? '';
                $recipientName  = $data['user_name'] ?? $recipientEmail;
            } elseif ($channel === 'staff') {
                $recipientEmail = $data['staff_email'] ?? '';
                $recipientName  = $data['staff_name'] ?? $recipientEmail;
            } else {
                error_log("SnipeScheduler notification: unknown channel '{$channel}' for event '{$event}'.");
                return;
            }

            // Send email if enabled, recipient is available, and there is content to send.
            $subject   = $data['subject'] ?? '';
            $bodyLines = $data['body_lines'] ?? [];
            if ($emailEnabled && $recipientEmail !== '' && ($subject !== '' || !empty($bodyLines))) {
                layout_send_notification($recipientEmail, $recipientName, $subject ?: $event, $bodyLines);
            }

            // Send Discord DM if this is a user notification and bot DM is enabled.
            if ($channel === 'user') {
                $botCfg = $config['discord_bot'] ?? [];
                if (!empty($botCfg['dm_enabled']) && !empty($botCfg['bot_token'])) {
                    $dmUserId    = $data['discord_user_id'] ?? '';
                    $dmEnabled   = $data['discord_dm_enabled'] ?? false;
                    $embedStyle  = $data['discord_embed_style'] ?? 'rich';
                    $dmEventKey  = $data['discord_dm_event_key'] ?? '';
                    $dmDbUserId  = $data['discord_db_user_id'] ?? 0;

                    if ($dmUserId !== '' && $dmEnabled && ($dmEventKey === '' || is_dm_event_enabled($dmDbUserId, $dmEventKey))) {
                        $dmDiscordContent = $data['discord_content'] ?? '';
                        $dmDiscordEmbeds  = $data['discord_embeds'] ?? [];
                        if ($embedStyle === 'plain') {
                            $plainText = $data['discord_dm_plain'] ?? $dmDiscordContent;
                            if ($plainText === '' && !empty($dmDiscordEmbeds)) {
                                $e = $dmDiscordEmbeds[0];
                                $plainText = build_discord_dm_plain($e['title'] ?? '', $e['description'] ?? '', $e['fields'] ?? []);
                            }
                            send_discord_dm($dmUserId, $plainText);
                        } else {
                            send_discord_dm($dmUserId, $dmDiscordContent, $dmDiscordEmbeds);
                        }
                    }
                }
            }

            // Send Discord if enabled, webhook is configured, and channel is staff.
            // Discord webhooks post to a shared channel — only staff-facing events belong there.
            // Users receive notifications via email only.
            $discordContent = $data['discord_content'] ?? '';
            $discordEmbeds  = $data['discord_embeds'] ?? [];
            if ($channel === 'staff' && $discordEnabled && $discordAvailable && ($discordContent !== '' || !empty($discordEmbeds))) {
                send_discord_notification($discordContent, $discordEmbeds);
            }
        } catch (\Throwable $e) {
            error_log("SnipeScheduler notification error ({$channel}/{$event}): " . $e->getMessage());
        }
    }
}

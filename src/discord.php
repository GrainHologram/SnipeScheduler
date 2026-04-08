<?php
// src/discord.php
// Discord webhook notification sender for SnipeScheduler.

require_once __DIR__ . '/bootstrap.php';

// Color constants for Discord embed sidebar.
if (!defined('DISCORD_COLOR_GREEN'))    { define('DISCORD_COLOR_GREEN',    0x2ECC71); } // checkout
if (!defined('DISCORD_COLOR_BLUE'))     { define('DISCORD_COLOR_BLUE',     0x3498DB); } // checkin
if (!defined('DISCORD_COLOR_YELLOW'))   { define('DISCORD_COLOR_YELLOW',   0xF1C40F); } // reservation
if (!defined('DISCORD_COLOR_GREY'))     { define('DISCORD_COLOR_GREY',     0x95A5A6); } // cancellation
if (!defined('DISCORD_COLOR_RED'))      { define('DISCORD_COLOR_RED',      0xE74C3C); } // overdue
if (!defined('DISCORD_COLOR_ORANGE'))   { define('DISCORD_COLOR_ORANGE',   0xE67E22); } // overdue escalation
if (!defined('DISCORD_COLOR_DARK_RED')) { define('DISCORD_COLOR_DARK_RED', 0x992D22); } // urgent overdue

/**
 * Send a notification to the configured Discord webhook.
 *
 * @param string $content  Plain-text message body (appears above any embeds).
 * @param array  $embeds   Optional array of embed structures (use build_discord_embed()).
 * @return bool  True on success, false on failure or if webhook is not configured.
 */
if (!function_exists('send_discord_notification')) {
    function send_discord_notification(string $content, array $embeds = []): bool
    {
        $config = load_config();
        $webhookUrl = $config['notifications']['discord_webhook_url'] ?? '';

        if ($webhookUrl === '') {
            return false;
        }

        $payload = [];
        if ($content !== '') {
            $payload['content'] = $content;
        }
        if (!empty($embeds)) {
            $payload['embeds'] = $embeds;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log('Discord notification: JSON encode failed — ' . json_last_error_msg());
            return false;
        }

        $contextOptions = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($contextOptions);

        try {
            $response = @file_get_contents($webhookUrl, false, $context);
        } catch (\Throwable $e) {
            error_log('Discord notification: request failed — ' . $e->getMessage());
            return false;
        }

        if ($response === false) {
            error_log('Discord notification: request failed — no response from webhook URL');
            return false;
        }

        // Discord returns 204 No Content on success. Check the response status.
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            $statusCode = (int)$matches[1];
            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }
            error_log('Discord notification: webhook returned HTTP ' . $statusCode . ' — ' . $response);
            return false;
        }

        // Could not parse status; treat as failure.
        error_log('Discord notification: unable to parse HTTP response status');
        return false;
    }
}

/**
 * Build a Discord embed structure.
 *
 * @param string $title        Embed title.
 * @param string $description  Embed body text (supports Markdown).
 * @param int    $color        Sidebar color (use DISCORD_COLOR_* constants).
 * @param array  $fields       Optional embed fields: [['name' => …, 'value' => …, 'inline' => bool], …]
 * @return array  Embed array ready to include in the $embeds parameter of send_discord_notification().
 */
/**
 * Generic Discord Bot API caller.
 *
 * @param string $endpoint API endpoint (e.g. '/api/v10/users/@me').
 * @param string $method   HTTP method.
 * @param array  $payload  JSON body (for POST/PUT/PATCH).
 * @return array ['status' => int, 'body' => mixed]
 */
if (!function_exists('discord_bot_api')) {
    function discord_bot_api(string $endpoint, string $method = 'GET', array $payload = []): array
    {
        $config = load_config();
        $botToken = $config['discord_bot']['bot_token'] ?? '';
        if ($botToken === '') {
            return ['status' => 0, 'body' => ['error' => 'Bot token not configured']];
        }

        $url = 'https://discord.com' . $endpoint;
        $headers = [
            "Authorization: Bot {$botToken}",
            "Content-Type: application/json",
        ];

        $contextOptions = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headers) . "\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ];

        if (!empty($payload) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contextOptions['http']['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $context = stream_context_create($contextOptions);

        try {
            $response = @file_get_contents($url, false, $context);
        } catch (\Throwable $e) {
            error_log('Discord Bot API error: ' . $e->getMessage());
            return ['status' => 0, 'body' => null];
        }

        $statusCode = 0;
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})/', $statusLine, $m)) {
            $statusCode = (int)$m[1];
        }

        $body = json_decode($response ?: '', true);
        return ['status' => $statusCode, 'body' => $body];
    }
}

/**
 * Send a Discord DM to a user via the bot.
 *
 * @param string $discordUserId Discord user ID.
 * @param string $content       Plain-text message content.
 * @param array  $embeds        Optional embed structures.
 * @return bool True on success.
 */
if (!function_exists('send_discord_dm')) {
    function send_discord_dm(string $discordUserId, string $content, array $embeds = []): bool
    {
        // Create DM channel (Discord caches this, so repeated calls reuse the channel)
        $dmChannel = discord_bot_api('/api/v10/users/@me/channels', 'POST', [
            'recipient_id' => $discordUserId,
        ]);

        if ($dmChannel['status'] < 200 || $dmChannel['status'] >= 300) {
            error_log('Discord DM: failed to create DM channel for user ' . $discordUserId . ' — HTTP ' . $dmChannel['status']);
            return false;
        }

        $channelId = $dmChannel['body']['id'] ?? '';
        if ($channelId === '') {
            error_log('Discord DM: no channel ID returned for user ' . $discordUserId);
            return false;
        }

        // Send message
        $msgPayload = [];
        if ($content !== '') {
            $msgPayload['content'] = $content;
        }
        if (!empty($embeds)) {
            $msgPayload['embeds'] = $embeds;
        }

        $result = discord_bot_api("/api/v10/channels/{$channelId}/messages", 'POST', $msgPayload);

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return true;
        }

        error_log('Discord DM: failed to send message to channel ' . $channelId . ' — HTTP ' . $result['status']);
        return false;
    }
}

/**
 * Build a plain-text DM message for users who prefer plain embed style.
 *
 * @param string $title       Message title.
 * @param string $description Message description.
 * @param array  $fields      Optional fields: [['name' => ..., 'value' => ...], ...]
 * @return string
 */
if (!function_exists('build_discord_dm_plain')) {
    function build_discord_dm_plain(string $title, string $description, array $fields = []): string
    {
        $lines = [];
        $lines[] = "**{$title}**";
        if ($description !== '') {
            $lines[] = $description;
        }
        if (!empty($fields)) {
            $lines[] = '';
            foreach ($fields as $field) {
                $name  = $field['name'] ?? '';
                $value = $field['value'] ?? '';
                if ($name !== '') {
                    $lines[] = "{$name}: {$value}";
                }
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('build_discord_embed')) {
    function build_discord_embed(string $title, string $description, int $color, array $fields = []): array
    {
        $embed = [
            'title'       => $title,
            'description' => $description,
            'color'       => $color,
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!empty($fields)) {
            $embed['fields'] = $fields;
        }

        return $embed;
    }
}

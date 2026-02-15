<?php
// datetime_helpers.php
// Shared helpers for configurable date/time formatting.

if (!function_exists('app_date_format_options')) {
    function app_date_format_options(): array
    {
        return [
            'd/m/Y' => '31/12/2026 (DD/MM/YYYY)',
            'm/d/Y' => '12/31/2026 (MM/DD/YYYY)',
            'Y-m-d' => '2026-12-31 (YYYY-MM-DD, ISO)',
            'Y.m.d' => '2026.12.31 (YYYY.MM.DD)',
            'd.m.Y' => '31.12.2026 (DD.MM.YYYY)',
            'd-m-Y' => '31-12-2026 (DD-MM-YYYY)',
            'Y/m/d' => '2026/12/31 (YYYY/MM/DD)',
            'j M Y' => '31 Dec 2026 (D Mon YYYY)',
            'M j, Y' => 'Dec 31, 2026 (Mon D, YYYY)',
        ];
    }
}

if (!function_exists('app_time_format_options')) {
    function app_time_format_options(): array
    {
        return [
            'H:i' => '23:59 (24-hour)',
            'H:i:s' => '23:59:59 (24-hour with seconds)',
            'h:i A' => '11:59 PM (12-hour)',
            'h:i:s A' => '11:59:59 PM (12-hour with seconds)',
        ];
    }
}

if (!function_exists('app_get_timezone')) {
    function app_get_timezone(?array $cfg = null): ?DateTimeZone
    {
        $cfg = $cfg ?? load_config();
        $timezone = $cfg['app']['timezone'] ?? 'Europe/Jersey';
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('app_get_date_format')) {
    function app_get_date_format(?array $cfg = null): string
    {
        $cfg = $cfg ?? load_config();
        $options = app_date_format_options();
        $raw = $cfg['app']['date_format'] ?? 'd/m/Y';
        return array_key_exists($raw, $options) ? $raw : 'd/m/Y';
    }
}

if (!function_exists('app_get_time_format')) {
    function app_get_time_format(?array $cfg = null): string
    {
        $cfg = $cfg ?? load_config();
        $options = app_time_format_options();
        $raw = $cfg['app']['time_format'] ?? 'H:i';
        return array_key_exists($raw, $options) ? $raw : 'H:i';
    }
}

if (!function_exists('app_parse_datetime_value')) {
    function app_parse_datetime_value($value, ?DateTimeZone $tz = null): ?DateTime
    {
        if ($value instanceof DateTimeInterface) {
            $dt = new DateTime($value->format('c'));
            if ($tz) {
                $dt->setTimezone($tz);
            }
            return $dt;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $dt = new DateTime('@' . $value);
            if ($tz) {
                $dt->setTimezone($tz);
            }
            return $dt;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        try {
            $dt = new DateTime($text);
            if ($tz) {
                $dt->setTimezone($tz);
            }
            return $dt;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('app_format_date')) {
    function app_format_date($value, ?array $cfg = null, ?DateTimeZone $tz = null): string
    {
        $cfg = $cfg ?? load_config();
        $tz = $tz ?? app_get_timezone($cfg);
        $dt = app_parse_datetime_value($value, $tz);
        if (!$dt) {
            return is_scalar($value) ? (string)$value : '';
        }
        return $dt->format(app_get_date_format($cfg));
    }
}

if (!function_exists('app_format_datetime')) {
    function app_format_datetime($value, ?array $cfg = null, ?DateTimeZone $tz = null): string
    {
        $cfg = $cfg ?? load_config();
        $tz = $tz ?? app_get_timezone($cfg);
        $dt = app_parse_datetime_value($value, $tz);
        if (!$dt) {
            return is_scalar($value) ? (string)$value : '';
        }
        $format = app_get_date_format($cfg) . ' ' . app_get_time_format($cfg);
        return $dt->format($format);
    }
}

/**
 * Return the Snipe-IT server timezone.
 * Falls back to the app timezone when snipeit.timezone is empty.
 */
if (!function_exists('snipe_get_timezone')) {
    function snipe_get_timezone(?array $cfg = null): ?DateTimeZone
    {
        $cfg = $cfg ?? load_config();
        $tz = trim($cfg['snipeit']['timezone'] ?? '');
        if ($tz !== '') {
            try {
                return new DateTimeZone($tz);
            } catch (Throwable $e) {
                // Invalid timezone string – fall through to app timezone.
            }
        }
        return app_get_timezone($cfg);
    }
}

/**
 * Return the configured Snipe-IT custom field DB column name for
 * expected check-in datetime, or null if not configured.
 */
if (!function_exists('snipe_get_expected_checkin_custom_field')) {
    function snipe_get_expected_checkin_custom_field(?array $cfg = null): ?string
    {
        $cfg = $cfg ?? load_config();
        $field = trim($cfg['snipeit']['expected_checkin_custom_field'] ?? '');
        return $field !== '' ? $field : null;
    }
}

/**
 * Format a date that originates from Snipe-IT (in snipe_tz) for display
 * in the app's local timezone.
 *
 * @param mixed             $value    Date string or array with datetime/date key
 * @param array|null        $cfg      Config array
 * @param DateTimeZone|null $sourceTz Source timezone (defaults to snipe_get_timezone())
 */
if (!function_exists('app_format_date_local')) {
    function app_format_date_local($value, ?array $cfg = null, ?DateTimeZone $sourceTz = null): string
    {
        if (is_array($value)) {
            $value = $value['datetime'] ?? ($value['date'] ?? '');
        }
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        $cfg = $cfg ?? load_config();
        $sourceTz = $sourceTz ?? snipe_get_timezone($cfg);
        $appTz = app_get_timezone($cfg);
        try {
            $dt = new DateTime($text, $sourceTz);
            if ($appTz && $sourceTz->getName() !== $appTz->getName()) {
                $dt->setTimezone($appTz);
            }
        } catch (Throwable $e) {
            return $text;
        }
        return $dt->format(app_get_date_format($cfg));
    }
}

/**
 * Format a datetime that originates from Snipe-IT (in snipe_tz) for display
 * in the app's local timezone.
 *
 * @param mixed             $value    Datetime string or array with datetime/date key
 * @param array|null        $cfg      Config array
 * @param DateTimeZone|null $sourceTz Source timezone (defaults to snipe_get_timezone())
 */
if (!function_exists('app_format_datetime_local')) {
    function app_format_datetime_local($value, ?array $cfg = null, ?DateTimeZone $sourceTz = null): string
    {
        if (is_array($value)) {
            $value = $value['datetime'] ?? ($value['date'] ?? '');
        }
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        $cfg = $cfg ?? load_config();
        $sourceTz = $sourceTz ?? snipe_get_timezone($cfg);
        $appTz = app_get_timezone($cfg);
        try {
            $dt = new DateTime($text, $sourceTz);
            if ($appTz && $sourceTz->getName() !== $appTz->getName()) {
                $dt->setTimezone($appTz);
            }
        } catch (Throwable $e) {
            return $text;
        }
        $format = app_get_date_format($cfg) . ' ' . app_get_time_format($cfg);
        return $dt->format($format);
    }
}

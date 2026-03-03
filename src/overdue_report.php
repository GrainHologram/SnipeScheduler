<?php
// src/overdue_report.php
// Shared renderer for overdue asset reports (web page + future email).

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('build_overdue_report_rows')) {
    /**
     * Normalize raw overdue asset data into flat report rows.
     *
     * Expected return is sourced from the scheduler_expected_checkin custom field
     * (stored in checked_out_asset_cache.expected_checkin by the sync cron when
     * snipeit.expected_checkin_custom_field is configured).
     *
     * @param array $assets Output of list_checked_out_assets(true)
     * @return array Sorted rows with display-ready fields
     */
    function build_overdue_report_rows(array $assets): array
    {
        $cfg     = load_config();
        $snipeTz = snipe_get_timezone($cfg);
        $appTz   = app_get_timezone($cfg);
        $dateFmt = app_get_date_format($cfg);
        $timeFmt = app_get_time_format($cfg);
        $rows    = [];

        foreach ($assets as $a) {
            $tag   = $a['asset_tag'] ?? '';
            $name  = $a['name'] ?? '';
            $model = $a['model']['name'] ?? '';

            $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
            $userName  = '';
            $userEmail = '';
            $userId    = 0;
            if (is_array($assigned)) {
                $userName  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
                $userEmail = $assigned['email'] ?? ($assigned['username'] ?? '');
                $userId    = (int)($assigned['id'] ?? 0);
            } elseif (is_string($assigned)) {
                $userName = $assigned;
            }

            // Expected return — full datetime from custom field (via cache)
            $expRaw = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
            $expDate = '';
            $expTime = '';
            $expTs   = null;
            if ($expRaw !== '') {
                try {
                    $dtExp = new DateTime($expRaw, $snipeTz);
                    if ($appTz && $snipeTz && $snipeTz->getName() !== $appTz->getName()) {
                        $dtExp->setTimezone($appTz);
                    }
                    $expTs   = $dtExp->getTimestamp();
                    $expDate = $dtExp->format($dateFmt);
                    $expTime = $dtExp->format($timeFmt);
                } catch (Throwable $e) {
                    $expDate = $expRaw;
                }
            }

            // Last checkout — full datetime
            $coRaw  = $a['_last_checkout_norm'] ?? ($a['last_checkout'] ?? '');
            $coDate = '';
            $coTime = '';
            if ($coRaw !== '') {
                try {
                    $dtCo = new DateTime($coRaw, $snipeTz);
                    if ($appTz && $snipeTz && $snipeTz->getName() !== $appTz->getName()) {
                        $dtCo->setTimezone($appTz);
                    }
                    $coDate = $dtCo->format($dateFmt);
                    $coTime = $dtCo->format($timeFmt);
                } catch (Throwable $e) {
                    $coDate = $coRaw;
                }
            }

            $daysOverdue = $expTs ? max(1, (int)floor((time() - $expTs) / 86400)) : 1;

            $rows[] = [
                'asset_tag'               => $tag,
                'asset_name'              => $name,
                'model_name'              => $model,
                'assigned_to_name'        => $userName,
                'assigned_to_email'       => $userEmail,
                'assigned_to_id'          => $userId,
                'expected_checkin_date'    => $expDate,
                'expected_checkin_time'    => $expTime,
                'expected_checkin_display' => trim($expDate . ' ' . $expTime) ?: 'unknown',
                'last_checkout_date'      => $coDate,
                'last_checkout_time'      => $coTime,
                'last_checkout_display'   => trim($coDate . ' ' . $coTime),
                'days_overdue'            => $daysOverdue,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['days_overdue'] === $b['days_overdue']) {
                return strcasecmp($a['asset_tag'], $b['asset_tag']);
            }
            return $b['days_overdue'] <=> $a['days_overdue'];
        });

        return $rows;
    }
}

if (!function_exists('render_overdue_report_html')) {
    /**
     * Render an HTML table from overdue report rows.
     *
     * @param array $rows     Output of build_overdue_report_rows()
     * @param array $options  'context' => 'web'|'email', 'group_by_user' => bool,
     *                        'user_print_links' => bool (add per-user print link)
     * @return string HTML table string (no wrapper)
     */
    function render_overdue_report_html(array $rows, array $options = []): string
    {
        $context        = $options['context'] ?? 'web';
        $groupByUser    = !empty($options['group_by_user']);
        $userPrintLinks = !empty($options['user_print_links']);

        $esc = function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };

        $severityClass = function (int $days) use ($context): string {
            if ($context === 'email') {
                return $days >= 2 ? 'background:#f8d7da;' : 'background:#fff3cd;';
            }
            return $days >= 2 ? 'overdue-row-danger' : 'overdue-row-warning';
        };

        // Format a date/time pair with a linebreak between date and time
        $formatDatetime = function (array $r, string $prefix) use ($context, $esc): string {
            $date = $r[$prefix . '_date'] ?? '';
            $time = $r[$prefix . '_time'] ?? '';
            if ($date === '' && $time === '') {
                return '';
            }
            if ($time === '') {
                return $esc($date);
            }
            if ($context === 'email') {
                return $esc($date) . '<br style="mso-data-placement:same-cell;">' . $esc($time);
            }
            return $esc($date) . '<br>' . '<span class="text-muted small">' . $esc($time) . '</span>';
        };

        $columns = ['Asset Tag', 'Asset Name', 'Model', 'Assigned To', 'Last Checkout', 'Expected Return', 'Days Overdue'];

        $tableClass = $context === 'web' ? 'table table-sm align-middle overdue-report-table' : '';
        $tableStyle = $context === 'email' ? 'border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;' : '';

        $thStyle = $context === 'email' ? 'padding:6px 8px;border:1px solid #e5e5e5;background:#f1f1f1;text-align:left;' : '';

        $html = '';

        if ($groupByUser) {
            $grouped = [];
            foreach ($rows as $row) {
                $key = $row['assigned_to_email'] ?: $row['assigned_to_name'] ?: 'Unknown';
                $grouped[$key][] = $row;
            }

            $isFirst = true;
            foreach ($grouped as $userKey => $userRows) {
                $userName = $userRows[0]['assigned_to_name'] ?: $userKey;
                $userLabel = $esc($userName);
                if ($userRows[0]['assigned_to_email'] && $userRows[0]['assigned_to_email'] !== $userName) {
                    $userLabel .= ' (' . $esc($userRows[0]['assigned_to_email']) . ')';
                }

                if ($context === 'web') {
                    $groupClass = 'overdue-user-group mb-3';
                    if (!$isFirst) {
                        $groupClass .= ' overdue-user-group-break';
                    }
                    $html .= '<div class="' . $groupClass . '">';
                    $html .= '<h6 class="fw-semibold mb-2">' . $userLabel
                        . ' <span class="badge bg-secondary">' . count($userRows) . ' item' . (count($userRows) !== 1 ? 's' : '') . '</span>';
                    if ($userPrintLinks) {
                        $printUrl = 'overdue_report.php?group=user&user=' . urlencode($userKey);
                        $html .= ' <a href="' . $esc($printUrl) . '" class="btn btn-sm btn-outline-secondary ms-2 no-print" title="Print this user\'s report">Print</a>';
                    }
                    $html .= '</h6>';
                } else {
                    $html .= '<h3 style="font-size:15px;margin:16px 0 8px 0;">' . $userLabel . ' (' . count($userRows) . ' item' . (count($userRows) !== 1 ? 's' : '') . ')</h3>';
                }

                $html .= _render_overdue_table($userRows, $columns, $tableClass, $tableStyle, $thStyle, $context, $esc, $severityClass, $formatDatetime, true);

                if ($context === 'web') {
                    $html .= '</div>';
                }

                $isFirst = false;
            }
        } else {
            $html .= _render_overdue_table($rows, $columns, $tableClass, $tableStyle, $thStyle, $context, $esc, $severityClass, $formatDatetime, false);
        }

        return $html;
    }

    /**
     * @internal Render a single overdue table.
     */
    function _render_overdue_table(array $rows, array $columns, string $tableClass, string $tableStyle, string $thStyle, string $context, callable $esc, callable $severityClass, callable $formatDatetime, bool $skipUserColumn): string
    {
        $html = '<table' . ($tableClass ? ' class="' . $tableClass . '"' : '') . ($tableStyle ? ' style="' . $tableStyle . '"' : '') . '>';
        $html .= '<thead><tr>';
        foreach ($columns as $col) {
            if ($skipUserColumn && $col === 'Assigned To') {
                continue;
            }
            $html .= '<th' . ($thStyle ? ' style="' . $thStyle . '"' : '') . '>' . $esc($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $tdStyle = $context === 'email' ? 'padding:6px 8px;border:1px solid #e5e5e5;' : '';

        foreach ($rows as $r) {
            $severity = $severityClass($r['days_overdue']);
            if ($context === 'email') {
                $html .= '<tr style="' . $severity . '">';
            } else {
                $html .= '<tr class="' . $severity . '">';
            }

            $html .= '<td' . ($tdStyle ? ' style="' . $tdStyle . '"' : '') . '>' . $esc($r['asset_tag']) . '</td>';
            $html .= '<td' . ($tdStyle ? ' style="' . $tdStyle . '"' : '') . '>' . $esc($r['asset_name']) . '</td>';
            $html .= '<td' . ($tdStyle ? ' style="' . $tdStyle . '"' : '') . '>' . $esc($r['model_name']) . '</td>';
            if (!$skipUserColumn) {
                $userDisplay = $r['assigned_to_name'];
                if ($r['assigned_to_email'] && $r['assigned_to_email'] !== $r['assigned_to_name']) {
                    $userDisplay .= ' (' . $r['assigned_to_email'] . ')';
                }
                $html .= '<td' . ($tdStyle ? ' style="' . $tdStyle . '"' : '') . '>' . $esc($userDisplay ?: 'Unknown') . '</td>';
            }
            $html .= '<td' . ($tdStyle ? ' style="' . $tdStyle . '"' : '') . '>' . $formatDatetime($r, 'last_checkout') . '</td>';
            $html .= '<td' . ($tdStyle ? ' style="' . $tdStyle . '"' : '') . '>' . $formatDatetime($r, 'expected_checkin') . '</td>';

            $daysAlign = $context === 'email' ? $tdStyle . 'text-align:center;' : '';
            $html .= '<td' . ($daysAlign ? ' style="' . $daysAlign . '"' : ' class="text-center"') . '>' . (int)$r['days_overdue'] . '</td>';

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }
}

if (!function_exists('render_overdue_report_text')) {
    /**
     * Render a plaintext version of the overdue report.
     *
     * @param array $rows Output of build_overdue_report_rows()
     * @return string One line per asset
     */
    function render_overdue_report_text(array $rows): string
    {
        $lines = [];
        foreach ($rows as $r) {
            $user = $r['assigned_to_email'] ?: $r['assigned_to_name'] ?: 'Unknown';
            $days = $r['days_overdue'];
            $lines[] = sprintf(
                '- %s (%s) – due %s (%d day%s overdue) | User: %s',
                $r['asset_tag'],
                $r['model_name'],
                $r['expected_checkin_display'],
                $days,
                $days === 1 ? '' : 's',
                $user
            );
        }
        return implode("\n", $lines);
    }
}

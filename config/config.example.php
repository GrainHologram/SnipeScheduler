<?php
/**
 * Global configuration for the Snipe-IT booking app.
 *
 * Edit the values below to match your environment.
 *
 * Copy this file to config/config.php and keep your secrets out of version control.
 */

/**
 * Paging / limits for catalogue + Snipe-IT API
 * These run as soon as config.php is required.
 */
if (!defined('SNIPEIT_API_PAGE_LIMIT')) {
    define('SNIPEIT_API_PAGE_LIMIT', 12); // or whatever default you want
}

if (!defined('CATALOGUE_ITEMS_PER_PAGE')) {
    define('CATALOGUE_ITEMS_PER_PAGE', SNIPEIT_API_PAGE_LIMIT);
}

// ---------------------------------------------------------------------
// Main config array (keep your existing values here)
// ---------------------------------------------------------------------
return [

    'db_booking' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => '',
        'username' => '',
        'password' => '',      // keep your existing password
        'charset'  => 'utf8mb4',
    ],

    'snipeit' => [
        'base_url'  => '',
        'api_token' => '',     // keep your existing token
        'verify_ssl' => false,
        'timezone' => '',  // Snipe-IT server timezone (e.g. 'America/New_York'). Defaults to app timezone if empty.
        'expected_checkin_custom_field' => '',  // DB column name for a Snipe-IT text custom field, e.g. '_snipeit_expected_return_datetime_5'
    ],

    'ldap' => [
        'host'          => 'ldaps://',
        'base_dn'       => '',
        'bind_dn'       => '',
        'bind_password' => '', // keep your existing password
        'ignore_cert'   => true,
    ],

    'auth' => [
        'ldap_enabled' => true,
        'google_oauth_enabled' => false,
        'microsoft_oauth_enabled' => false,
        // Accepts a single CN string or an array of CNs
        'admin_group_cn' => [
            // 'ICT Admins',
            // 'Another Admin Group',
        ],
        'checkout_group_cn' => [
            // 'Checkout Staff',
            // 'Equipment Desk',
        ],
        // Optional: treat these Google accounts as administrators
        'google_admin_emails' => [
            // 'admin@example.com',
        ],
        // Optional: treat these Google accounts as checkout staff
        'google_checkout_emails' => [
            // 'staff@example.com',
        ],
        // Optional: treat these Microsoft accounts as administrators
        'microsoft_admin_emails' => [
            // 'admin@example.com',
        ],
        // Optional: treat these Microsoft accounts as checkout staff
        'microsoft_checkout_emails' => [
            // 'staff@example.com',
        ],
    ],

    'google_oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        // Leave blank to auto-detect the login_process.php callback URL
        'redirect_uri'  => '',
        // Optional restriction to specific Google Workspace domains
        'allowed_domains' => [
            // 'example.com',
        ],
    ],

    // Optional: Google Workspace directory search (requires Admin SDK + service account)
    'google_directory' => [
        // Provide either a raw JSON string or a filesystem path to the JSON file
        'service_account_json' => '',
        'service_account_path' => '',
        // Admin user email to impersonate for directory read access
        'impersonated_user'     => '',
    ],

    'microsoft_oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        // Tenant ID (GUID)
        'tenant'        => '',
        // Leave blank to auto-detect the login_process.php callback URL
        'redirect_uri'  => '',
        // Optional restriction to specific domains
        'allowed_domains' => [
            // 'example.com',
        ],
    ],

    // Optional: Entra directory search (defaults to microsoft_oauth client_id/secret/tenant)
    'entra_directory' => [
        'client_id'     => '',
        'client_secret' => '',
        'tenant'        => '',
    ],

    'app' => [
        'timezone' => 'Europe/Jersey',
        'debug'    => true,
        'logo_url' => '', // optional: full URL or relative path to logo image
        'primary_color' => '#660000', // main UI colour for gradients/buttons
        'date_format' => 'd/m/Y', // display format for dates (see settings for options)
        'time_format' => 'H:i', // display format for times (12/24-hour options in settings)
        'missed_cutoff_minutes' => 60, // minutes after start time before marking reservation as missed
        'api_cache_ttl_seconds' => 60, // cache Snipe-IT GET responses for this many seconds
        'overdue_staff_email' => '', // overdue report recipients (comma/newline separated)
        'overdue_staff_name'  => '', // optional names for recipients (comma/newline separated)
        'block_catalogue_overdue' => true, // block catalogue for users with overdue checkouts
    ],

    'reservations' => [
        'deletable_statuses' => ['pending', 'confirmed', 'cancelled', 'missed'], // statuses that allow deletion (checked_out and completed are not deletable)
    ],

    'catalogue' => [
        // Restrict which categories appear in the catalogue filter.
        // Leave empty to show all categories returned by Snipe-IT.
        'allowed_categories' => [],
    ],

    'checkout_limits' => [
        'enabled' => false,  // master switch; false = no restrictions (backwards compatible)
        'default' => [
            'max_checkout_hours' => 0,   // 0 = unlimited
            'max_renewal_hours'  => 0,   // 0 = unlimited
            'max_total_hours'    => 0,   // 0 = unlimited (initial + all renewals)
        ],
        'group_overrides' => [
            // Keyed by Snipe-IT group ID (int). Most permissive wins for multi-group users.
            // Example:
            // 5  => ['max_checkout_hours' => 168, 'max_renewal_hours' => 48, 'max_total_hours' => 336],
            // 12 => ['max_checkout_hours' => 720, 'max_renewal_hours' => 168, 'max_total_hours' => 1440],
        ],
        'single_active_checkout' => false,  // true = enforce one active checkout per user
    ],

    'smtp' => [
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls', // none|ssl|tls
        'auth_method'=> 'login', // login|plain|none
        'from_email' => '',
        'from_name'  => 'SnipeScheduler',
    ],
];

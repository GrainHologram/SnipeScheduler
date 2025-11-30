<?php
// footer.php
// Shared footer renderer for ReserveIT pages.

if (!function_exists('reserveit_footer')) {
    function reserveit_footer(): void
    {
        $versionFile = __DIR__ . '/version.txt';
        $versionRaw  = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : '';
        $version     = $versionRaw !== '' ? $versionRaw : 'dev';
        $versionEsc  = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');

        echo '<footer class="text-center text-muted mt-4 small">'
            . 'ReserveIT Version ' . $versionEsc . ' - Created by '
            . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
            . '</footer>';
    }
}

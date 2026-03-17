<?php
// discord_link.php — Discord OAuth2 account linking callback.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';

$config    = load_config();
$botCfg    = $config['discord_bot'] ?? [];
$clientId  = trim($botCfg['oauth_client_id'] ?? '');
$clientSecret = trim($botCfg['oauth_client_secret'] ?? '');

if ($clientId === '' || $clientSecret === '') {
    $_SESSION['discord_link_error'] = 'Discord OAuth is not configured.';
    header('Location: my_account.php');
    exit;
}

// Auto-detect redirect URI
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$fallbackRedirect = $scheme . '://' . $host . $base . '/discord_link.php';
$redirectUri = trim($botCfg['oauth_redirect_uri'] ?? '') ?: $fallbackRedirect;

if (!isset($_GET['code'])) {
    // Step 1: Redirect to Discord authorization
    $state = bin2hex(random_bytes(16));
    $_SESSION['discord_oauth_state'] = $state;

    $authUrl = 'https://discord.com/api/oauth2/authorize?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'identify',
        'state'         => $state,
    ]);

    header('Location: ' . $authUrl);
    exit;
}

// Step 2: Handle callback with code
$state = $_GET['state'] ?? '';
if ($state === '' || empty($_SESSION['discord_oauth_state']) || !hash_equals($_SESSION['discord_oauth_state'], $state)) {
    unset($_SESSION['discord_oauth_state']);
    $_SESSION['discord_link_error'] = 'Discord linking failed (invalid state). Please try again.';
    header('Location: my_account.php');
    exit;
}
unset($_SESSION['discord_oauth_state']);

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    $_SESSION['discord_link_error'] = 'Discord linking failed (no code returned).';
    header('Location: my_account.php');
    exit;
}

// Exchange code for access token
$tokenCh = curl_init('https://discord.com/api/oauth2/token');
curl_setopt_array($tokenCh, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
    ]),
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$tokenRaw = curl_exec($tokenCh);
if ($tokenRaw === false) {
    $err = curl_error($tokenCh);
    curl_close($tokenCh);
    error_log('Discord link: token request failed — ' . $err);
    $_SESSION['discord_link_error'] = 'Discord linking failed (token exchange error).';
    header('Location: my_account.php');
    exit;
}
$tokenCode = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
curl_close($tokenCh);

$tokenData = json_decode($tokenRaw, true);
if ($tokenCode >= 400 || !$tokenData || !empty($tokenData['error'])) {
    $msg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unexpected response';
    error_log('Discord link: token error — ' . $msg);
    $_SESSION['discord_link_error'] = 'Discord linking failed (token error).';
    header('Location: my_account.php');
    exit;
}

$accessToken = $tokenData['access_token'] ?? '';
if ($accessToken === '') {
    $_SESSION['discord_link_error'] = 'Discord linking failed (no access token).';
    header('Location: my_account.php');
    exit;
}

// Fetch Discord user info
$userCh = curl_init('https://discord.com/api/users/@me');
curl_setopt_array($userCh, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$userRaw = curl_exec($userCh);
if ($userRaw === false) {
    $err = curl_error($userCh);
    curl_close($userCh);
    error_log('Discord link: user request failed — ' . $err);
    $_SESSION['discord_link_error'] = 'Discord linking failed (could not fetch profile).';
    header('Location: my_account.php');
    exit;
}
$userCode = curl_getinfo($userCh, CURLINFO_HTTP_CODE);
curl_close($userCh);

$discordUser = json_decode($userRaw, true);
if ($userCode >= 400 || !$discordUser || empty($discordUser['id'])) {
    error_log('Discord link: user fetch failed — HTTP ' . $userCode);
    $_SESSION['discord_link_error'] = 'Discord linking failed (could not read Discord profile).';
    header('Location: my_account.php');
    exit;
}

$discordUserId = $discordUser['id'];
$discordUsername = $discordUser['username'] ?? $discordUserId;

// Save to database
$localUserId = $currentUser['id'] ?? 0;
if ($localUserId <= 0) {
    $_SESSION['discord_link_error'] = 'Discord linking failed (no user session).';
    header('Location: my_account.php');
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET discord_user_id = :did WHERE id = :id");
    $stmt->execute([':did' => $discordUserId, ':id' => $localUserId]);
} catch (\Throwable $e) {
    error_log('Discord link: DB update failed — ' . $e->getMessage());
    $_SESSION['discord_link_error'] = 'Discord linking failed (database error).';
    header('Location: my_account.php');
    exit;
}

// Update session
$_SESSION['user']['discord_user_id'] = $discordUserId;

header('Location: my_account.php?linked=1');
exit;

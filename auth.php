<?php

if (!headers_sent() && ob_get_level() === 0) {
    ob_start();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        session_start();
    } elseif (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
}

const APP_ADMIN_USER = 'admin';
const APP_ADMIN_DEFAULT_PASS = 'Turkiet3040?!';
const APP_AUTH_FILE = __DIR__ . '/data/admin_auth.json';
const APP_AUTH_COOKIE = 'crlx_auth';
const APP_AUTH_COOKIE_TTL = 2592000;

function ensureAuthStorage(): void
{
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    if (!file_exists(APP_AUTH_FILE)) {
        $default = [
            'username' => APP_ADMIN_USER,
            'password_hash' => password_hash(APP_ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT),
            'updated_at' => gmdate('c'),
        ];
        file_put_contents(APP_AUTH_FILE, json_encode($default, JSON_PRETTY_PRINT));
    }
}

function getAuthData(): array
{
    ensureAuthStorage();

    $raw = file_get_contents(APP_AUTH_FILE);
    if ($raw === false) {
        return [
            'username' => APP_ADMIN_USER,
            'password_hash' => password_hash(APP_ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT),
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['username']) || empty($data['password_hash'])) {
        return [
            'username' => APP_ADMIN_USER,
            'password_hash' => password_hash(APP_ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT),
        ];
    }

    return $data;
}

function saveAuthData(array $data): bool
{
    ensureAuthStorage();

    $payload = [
        'username' => (string) ($data['username'] ?? APP_ADMIN_USER),
        'password_hash' => (string) ($data['password_hash'] ?? ''),
        'updated_at' => gmdate('c'),
    ];

    return file_put_contents(APP_AUTH_FILE, json_encode($payload, JSON_PRETTY_PRINT)) !== false;
}

function getAdminUsername(): string
{
    $auth = getAuthData();

    return (string) ($auth['username'] ?? APP_ADMIN_USER);
}

function verifyAdminCredentials(string $username, string $password): bool
{
    $auth = getAuthData();
    $storedUser = (string) ($auth['username'] ?? APP_ADMIN_USER);
    $hash = (string) ($auth['password_hash'] ?? '');

    if (!hash_equals($storedUser, $username) || $hash === '') {
        return false;
    }

    return password_verify($password, $hash);
}

function authCookieSignature(string $username, string $expires): string
{
    $auth = getAuthData();
    $secret = (string) ($auth['password_hash'] ?? APP_ADMIN_DEFAULT_PASS);

    return hash_hmac('sha256', $username . '|' . $expires, $secret);
}

function buildAuthCookieValue(string $username): string
{
    $expires = (string) (time() + APP_AUTH_COOKIE_TTL);
    $sig = authCookieSignature($username, $expires);

    return base64_encode($username . '|' . $expires . '|' . $sig);
}

function buildAuthCookieJavascript(string $username): string
{
    $value = rawurlencode(buildAuthCookieValue($username));
    $maxAge = APP_AUTH_COOKIE_TTL;

    return APP_AUTH_COOKIE . '=' . $value . '; path=/; max-age=' . $maxAge . '; samesite=lax';
}

function readAuthCookieUsername(): string
{
    $raw = isset($_COOKIE[APP_AUTH_COOKIE]) ? (string) $_COOKIE[APP_AUTH_COOKIE] : '';
    if ($raw === '') {
        return '';
    }

    $decoded = base64_decode($raw, true);
    if (!is_string($decoded)) {
        return '';
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) {
        return '';
    }

    [$username, $expires, $sig] = $parts;
    if ($username === '' || !ctype_digit($expires) || (int) $expires < time()) {
        return '';
    }

    $expected = authCookieSignature($username, $expires);
    if (!hash_equals($expected, $sig)) {
        return '';
    }

    return $username;
}

function persistAuthCookie(string $username): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(
        APP_AUTH_COOKIE,
        buildAuthCookieValue($username),
        [
            'expires' => time() + APP_AUTH_COOKIE_TTL,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

function clearAuthCookie(): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(APP_AUTH_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function loginAdminUser(string $username): void
{
    $_SESSION['auth_user'] = $username;
    persistAuthCookie($username);
}

function logoutAdminUser(): void
{
    $_SESSION = [];
    clearAuthCookie();

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}

function changeAdminPassword(string $currentPassword, string $newPassword): array
{
    $auth = getAuthData();
    $username = (string) ($auth['username'] ?? APP_ADMIN_USER);
    $hash = (string) ($auth['password_hash'] ?? '');

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        return ['ok' => false, 'message' => 'Current password is incorrect.'];
    }

    if (strlen($newPassword) < 10) {
        return ['ok' => false, 'message' => 'New password must be at least 10 characters.'];
    }

    $ok = saveAuthData([
        'username' => $username,
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    ]);

    return ['ok' => $ok, 'message' => $ok ? 'Password changed successfully.' : 'Could not save new password.'];
}

function isAuthenticated(): bool
{
    $admin = getAdminUsername();
    if (isset($_SESSION['auth_user']) && $_SESSION['auth_user'] === $admin) {
        return true;
    }

    $cookieUser = readAuthCookieUsername();
    if ($cookieUser !== '' && hash_equals($admin, $cookieUser)) {
        $_SESSION['auth_user'] = $admin;

        return true;
    }

    return false;
}

function requireAuth(): void
{
    if (isAuthenticated()) {
        return;
    }

    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/index.php');
    if (!headers_sent()) {
        header('Location: login.php?redirect=' . $redirect);
        exit;
    }

    $target = 'login.php?redirect=' . $redirect;
    echo '<script>window.location.href=' . json_encode($target) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

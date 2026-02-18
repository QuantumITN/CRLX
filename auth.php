<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const APP_ADMIN_USER = 'admin';
const APP_ADMIN_DEFAULT_PASS = 'Turkiet3040?!';
const APP_AUTH_FILE = __DIR__ . '/data/admin_auth.json';

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
    return isset($_SESSION['auth_user']) && $_SESSION['auth_user'] === getAdminUsername();
}

function requireAuth(): void
{
    if (isAuthenticated()) {
        return;
    }

    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/index.php');
    header('Location: login.php?redirect=' . $redirect);
    exit;
}

<?php

declare(strict_types=1);

/**
 * Database helper for MySQL-backed reservation storage.
 *
 * Configure credentials by copying data/db_config.php.example to data/db_config.php
 * and updating values.
 */
function loadDbConfig(): array
{
    $defaults = [
        'host' => getenv('CLR_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('CLR_DB_PORT') ?: 3306),
        'name' => getenv('CLR_DB_NAME') ?: '',
        'user' => getenv('CLR_DB_USER') ?: '',
        'pass' => getenv('CLR_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ];

    $configPath = __DIR__ . '/data/db_config.php';
    if (file_exists($configPath)) {
        /** @var array<string,mixed> $cfg */
        $cfg = include $configPath;
        if (is_array($cfg)) {
            $defaults = array_merge($defaults, $cfg);
        }
    }

    return $defaults;
}

function getDbPdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($failed) {
        return null;
    }

    $cfg = loadDbConfig();
    if (($cfg['name'] ?? '') === '' || ($cfg['user'] ?? '') === '') {
        $failed = true;
        return null;
    }

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, (string) $cfg['user'], (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

function dbIsReady(): bool
{
    return getDbPdo() instanceof PDO;
}

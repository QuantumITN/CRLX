<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';

requireAuth();

date_default_timezone_set('UTC');

const DB_CONFIG_FILE = __DIR__ . '/data/db_config.php';

function saveDbConfig(array $cfg): bool
{
    $export = var_export([
        'host' => (string) ($cfg['host'] ?? '127.0.0.1'),
        'port' => (int) ($cfg['port'] ?? 3306),
        'name' => (string) ($cfg['name'] ?? ''),
        'user' => (string) ($cfg['user'] ?? ''),
        'pass' => (string) ($cfg['pass'] ?? ''),
        'charset' => (string) ($cfg['charset'] ?? 'utf8mb4'),
    ], true);

    $php = "<?php\nreturn " . $export . ";\n";

    return file_put_contents(DB_CONFIG_FILE, $php) !== false;
}

$messages = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = (string) ($_POST['intent'] ?? '');
    $cfg = [
        'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
        'port' => (int) ($_POST['db_port'] ?? 3306),
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
        'charset' => trim((string) ($_POST['db_charset'] ?? 'utf8mb4')),
    ];

    if ($intent === 'save_db_config') {
        if (saveDbConfig($cfg)) {
            $messages[] = 'Database config saved.';
        } else {
            $errors[] = 'Could not save database config file.';
        }
    }

    if ($intent === 'test_db_config') {
        $test = testDbConnection($cfg);
        if ($test['ok']) {
            $messages[] = 'DB test successful: ' . (string) $test['message'];
        } else {
            $errors[] = 'DB test failed: ' . (string) $test['message'];
        }
    }
}

$dbConfig = loadDbConfig();
$checks = [];

$checks[] = ['name' => 'PHP version', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'details' => PHP_VERSION];
$checks[] = ['name' => 'PDO extension', 'ok' => extension_loaded('pdo'), 'details' => extension_loaded('pdo') ? 'loaded' : 'missing'];
$checks[] = ['name' => 'pdo_mysql extension', 'ok' => extension_loaded('pdo_mysql'), 'details' => extension_loaded('pdo_mysql') ? 'loaded' : 'missing'];

$dataDir = __DIR__ . '/data';
$checks[] = ['name' => 'data directory exists', 'ok' => is_dir($dataDir), 'details' => $dataDir];
$checks[] = ['name' => 'data directory writable', 'ok' => is_writable($dataDir), 'details' => is_writable($dataDir) ? 'writable' : 'not writable'];

$pdo = getDbPdo();
$dbOk = $pdo instanceof PDO;
$checks[] = ['name' => 'MySQL connection', 'ok' => $dbOk, 'details' => $dbOk ? 'connected' : 'not connected'];

$tableDetails = 'not checked';
$tableOk = false;
if ($dbOk) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'reservations'");
    $hasReservations = $stmt && $stmt->fetchColumn() !== false;
    $stmt = $pdo->query("SHOW TABLES LIKE 'reservation_events'");
    $hasEvents = $stmt && $stmt->fetchColumn() !== false;
    $tableOk = $hasReservations && $hasEvents;
    $tableDetails = 'reservations=' . ($hasReservations ? 'yes' : 'no') . ', reservation_events=' . ($hasEvents ? 'yes' : 'no');
}
$checks[] = ['name' => 'Required DB tables', 'ok' => $tableOk, 'details' => $tableDetails];

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Health Check - CLR Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('System Health'); ?>
<main class="container page-with-header">

    <section class="panel">
        <?php foreach ($errors as $error): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php foreach ($messages as $message): ?><div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

        <?php if ($allOk): ?>
            <div class="flash success">All checks passed ✅</div>
        <?php else: ?>
            <div class="flash error">Some checks failed ❌. Fix the failing items below.</div>
        <?php endif; ?>


        <div class="admin-grid" style="margin-top:10px;">
            <form method="post" class="admin-card">
                <h3>Database configuration</h3>
                <input type="hidden" name="intent" value="save_db_config">
                <input type="text" name="db_host" value="<?= htmlspecialchars((string) ($dbConfig['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB host" required>
                <input type="number" name="db_port" value="<?= (int) ($dbConfig['port'] ?? 3306) ?>" placeholder="DB port" required>
                <input type="text" name="db_name" value="<?= htmlspecialchars((string) ($dbConfig['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB name" required>
                <input type="text" name="db_user" value="<?= htmlspecialchars((string) ($dbConfig['user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB user" required>
                <input type="password" name="db_pass" value="<?= htmlspecialchars((string) ($dbConfig['pass'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB password">
                <input type="text" name="db_charset" value="<?= htmlspecialchars((string) ($dbConfig['charset'] ?? 'utf8mb4'), ENT_QUOTES, 'UTF-8') ?>" placeholder="utf8mb4" required>
                <button class="btn" type="submit">Save DB config</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Test DB connection</h3>
                <input type="hidden" name="intent" value="test_db_config">
                <input type="text" name="db_host" value="<?= htmlspecialchars((string) ($dbConfig['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB host" required>
                <input type="number" name="db_port" value="<?= (int) ($dbConfig['port'] ?? 3306) ?>" placeholder="DB port" required>
                <input type="text" name="db_name" value="<?= htmlspecialchars((string) ($dbConfig['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB name" required>
                <input type="text" name="db_user" value="<?= htmlspecialchars((string) ($dbConfig['user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB user" required>
                <input type="password" name="db_pass" value="<?= htmlspecialchars((string) ($dbConfig['pass'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB password">
                <input type="text" name="db_charset" value="<?= htmlspecialchars((string) ($dbConfig['charset'] ?? 'utf8mb4'), ENT_QUOTES, 'UTF-8') ?>" placeholder="utf8mb4" required>
                <button class="btn accent" type="submit">Test connection</button>
            </form>
        </div>

        <div class="feed-building">
            <?php foreach ($checks as $c): ?>
                <div class="feed-row">
                    <strong><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span>Status: <?= $c['ok'] ? 'PASS' : 'FAIL' ?></span>
                    <span>Details: <?= htmlspecialchars((string) $c['details'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>

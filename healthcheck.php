<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAuth();

date_default_timezone_set('UTC');

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
<main class="container">
    <header class="hero panel">
        <h1>System Health Check</h1>
        <p>Quick diagnostics for shared hosting readiness.</p>
        <p>
            <a class="btn ghost" href="index.php">← Back to CLR Calendar</a>
            <a class="btn ghost" href="admin_tools.php">Admin Tools</a>
            <a class="btn ghost" href="logout.php">Logout</a>
        </p>
    </header>

    <section class="panel">
        <?php if ($allOk): ?>
            <div class="flash success">All checks passed ✅</div>
        <?php else: ?>
            <div class="flash error">Some checks failed ❌. Fix the failing items below.</div>
        <?php endif; ?>

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

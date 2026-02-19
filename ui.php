<?php

declare(strict_types=1);

require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function headerNotificationSettings(): array
{
    $file = __DIR__ . '/data/notification_settings.json';
    $defaults = [
        'reservation_made' => true,
        'checkout_tomorrow' => true,
        'sync_failed' => true,
    ];
    if (!file_exists($file)) {
        return $defaults;
    }
    $raw = file_get_contents($file);
    $json = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($json) ? array_merge($defaults, $json) : $defaults;
}

function getHeaderNotifications(): array
{
    $settings = headerNotificationSettings();
    $items = [];

    if (!isAuthenticated()) {
        return $items;
    }

    $pdo = getDbPdo();
    if ($pdo) {
        if (!empty($settings['reservation_made'])) {
            $stmt = $pdo->query("SELECT reservation_uuid, title, apartment_id FROM reservations WHERE archived_flag = 0 ORDER BY created_at DESC LIMIT 5");
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $r) {
                $items[] = [
                    'text' => 'Reservation: ' . (string) ($r['title'] ?? 'New reservation') . ' (' . (string) ($r['apartment_id'] ?? '') . ')',
                    'link' => 'index.php?open_reservation=' . urlencode((string) ($r['reservation_uuid'] ?? '')),
                ];
            }
        }

        if (!empty($settings['checkout_tomorrow'])) {
            $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
            $stmt = $pdo->prepare('SELECT reservation_uuid, apartment_id, title FROM reservations WHERE archived_flag = 0 AND end_date = :d ORDER BY apartment_id ASC LIMIT 10');
            $stmt->execute([':d' => $tomorrow]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) {
                $items[] = [
                    'text' => 'Checkout tomorrow: ' . (string) ($r['apartment_id'] ?? '') . ' • ' . (string) ($r['title'] ?? ''),
                    'link' => 'index.php?open_reservation=' . urlencode((string) ($r['reservation_uuid'] ?? '')),
                ];
            }
        }
    }

    if (!empty($settings['sync_failed'])) {
        $file = __DIR__ . '/data/sync_meta.json';
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $status = is_array($json['status'] ?? null) ? $json['status'] : [];
            foreach ($status as $name => $msg) {
                $text = (string) $msg;
                if (stripos($text, 'failed') !== false || stripos($text, 'skip') !== false) {
                    $items[] = ['text' => 'Sync issue: ' . (string) $name . ' - ' . $text, 'link' => 'healthcheck.php'];
                }
            }
        }
    }

    return array_slice($items, 0, 12);
}

function renderSiteHeader(string $pageTitle = 'CLR Calendar'): void
{
    $logo = currentLogoUrl();
    $user = isAuthenticated() ? getAdminUsername() : '';
    $notifications = getHeaderNotifications();
    ?>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="brand" href="index.php">
                <?php if ($logo !== ''): ?>
                    <img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="brand-logo">
                <?php else: ?>
                    <div class="brand-badge">CR</div>
                <?php endif; ?>
                <div class="brand-text">
                    <strong>CLR Calendar</strong>
                    <span><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </a>
            <nav class="top-nav">
                <?php if (isAuthenticated()): ?>
                    <a class="btn ghost" href="index.php">Calendar</a>
                    <a class="btn ghost" href="admin_tools.php">Admin Tools</a>
                    <a class="btn ghost" href="reservations.php">Reservations</a>
                    <a class="btn ghost" href="reports.php">Reports</a>
                    <a class="btn ghost" href="file_sharing.php">File sharing</a>
                    <a class="btn ghost" href="settings.php">Settings</a>
                    <a class="btn ghost" href="healthcheck.php">Health</a>
                    <details class="notif-wrap">
                        <summary class="btn ghost">🔔 <?= count($notifications) ?></summary>
                        <div class="notif-menu">
                            <?php if (empty($notifications)): ?>
                                <p class="tiny">No notifications.</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                    <a href="<?= htmlspecialchars((string) $n['link'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $n['text'], ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </details>
                    <span class="user-pill">👤 <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?></span>
                    <a class="btn" href="logout.php">Logout</a>
                <?php else: ?>
                    <a class="btn" href="login.php">Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <?php
}

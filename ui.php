<?php

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


function notificationReadFile(): string
{
    return __DIR__ . '/data/notification_reads.json';
}

function readNotificationReads(): array
{
    $file = notificationReadFile();
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $json = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($json) ? $json : [];
}

function writeNotificationReads(array $reads): void
{
    $file = notificationReadFile();
    @file_put_contents($file, json_encode($reads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function markNotificationReadFromRequest(array $knownIds = []): void
{
    if (!isAuthenticated()) {
        return;
    }

    $reads = readNotificationReads();

    if (isset($_GET['notif_mark_all']) && $_GET['notif_mark_all'] === '1') {
        $now = gmdate('c');
        foreach ($knownIds as $id) {
            $reads[(string) $id] = $now;
        }
        writeNotificationReads($reads);
        return;
    }

    $id = isset($_GET['notif_read']) ? trim((string) $_GET['notif_read']) : '';
    if ($id === '') {
        return;
    }
    if ($knownIds !== [] && !in_array($id, $knownIds, true)) {
        return;
    }

    $reads[$id] = gmdate('c');
    writeNotificationReads($reads);
}

function withNotifReadParam(string $url, string $notifId): string
{
    $sep = str_contains($url, '?') ? '&' : '?';

    return $url . $sep . 'notif_read=' . urlencode($notifId);
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
                $uuid = (string) ($r['reservation_uuid'] ?? '');
                $id = 'reservation:' . $uuid;
                $items[] = [
                    'id' => $id,
                    'text' => 'Reservation: ' . (string) ($r['title'] ?? 'New reservation') . ' (' . (string) ($r['apartment_id'] ?? '') . ')',
                    'link' => 'index.php?open_reservation=' . urlencode($uuid),
                ];
            }
        }

        if (!empty($settings['checkout_tomorrow'])) {
            $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
            $stmt = $pdo->prepare('SELECT reservation_uuid, apartment_id, title FROM reservations WHERE archived_flag = 0 AND end_date = :d ORDER BY apartment_id ASC LIMIT 10');
            $stmt->execute([':d' => $tomorrow]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) {
                $uuid = (string) ($r['reservation_uuid'] ?? '');
                $id = 'checkout_tomorrow:' . $uuid;
                $items[] = [
                    'id' => $id,
                    'text' => 'Checkout tomorrow: ' . (string) ($r['apartment_id'] ?? '') . ' • ' . (string) ($r['title'] ?? ''),
                    'link' => 'index.php?open_reservation=' . urlencode($uuid),
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
                    $id = 'sync_issue:' . sha1((string) $name . '|' . $text);
                    $items[] = ['id' => $id, 'text' => 'Sync issue: ' . (string) $name . ' - ' . $text, 'link' => 'healthcheck.php'];
                }
            }
        }
    }

    $items = array_slice($items, 0, 12);
    $reads = readNotificationReads();
    foreach ($items as &$item) {
        $item['read'] = isset($reads[(string) ($item['id'] ?? '')]);
    }
    unset($item);

    return $items;
}

function renderSiteHeader(string $pageTitle = 'CLR Calendar'): void
{
    $logo = currentLogoUrl();
    $user = isAuthenticated() ? getAdminUsername() : '';
    $notifications = getHeaderNotifications();
    $notifIds = array_map(static fn(array $n): string => (string) ($n['id'] ?? ''), $notifications);
    markNotificationReadFromRequest($notifIds);
    $notifications = getHeaderNotifications();
    $unreadCount = count(array_filter($notifications, static fn(array $n): bool => empty($n['read'])));
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
                        <summary class="btn ghost">🔔 <?= $unreadCount ?> unread</summary>
                        <div class="notif-menu">
                            <h4>Today's notifications</h4>
                            <?php if (!empty($notifications)): ?>
                                <a class="notif-mark-all" href="<?= htmlspecialchars((string) (basename((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)) . '?notif_mark_all=1'), ENT_QUOTES, 'UTF-8') ?>">Mark all as read</a>
                            <?php endif; ?>
                            <?php if (empty($notifications)): ?>
                                <p class="tiny">No notifications.</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                    <?php $notifId = (string) ($n['id'] ?? ''); ?>
                                    <?php $notifLink = withNotifReadParam((string) $n['link'], $notifId); ?>
                                    <div class="notif-item <?= empty($n['read']) ? 'unread' : 'read' ?>">
                                        <a href="<?= htmlspecialchars($notifLink, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $n['text'], ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php if (empty($n['read'])): ?>
                                            <a class="notif-mark-read" href="<?= htmlspecialchars((string) (basename((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)) . '?notif_read=' . urlencode($notifId)), ENT_QUOTES, 'UTF-8') ?>">Mark read</a>
                                        <?php endif; ?>
                                    </div>
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/ui.php';

requireAuth();

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/data';
const BUILDINGS_FILE = DATA_DIR . '/buildings.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const SYNC_META_FILE = DATA_DIR . '/sync_meta.json';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0775, true);
}

function readJson(string $file, array $default): array
{
    if (!file_exists($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return $default;
    }
    $json = json_decode($raw, true);

    return is_array($json) ? $json : $default;
}

function writeJson(string $file, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return is_string($json) && file_put_contents($file, $json) !== false;
}

function generateId(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(5));
}

function normalizeDate(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    $formats = ['Y-m-d', 'Ymd', 'Ymd\\THis\\Z', 'Ymd\\THis', 'Ymd\\THi'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    return null;
}

function parseIcs(string $ics, string $apartmentId, string $source): array
{
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $lines = explode("\n", $ics);
    $events = [];
    $inside = false;
    $current = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === 'BEGIN:VEVENT') {
            $inside = true;
            $current = [];
            continue;
        }
        if ($line === 'END:VEVENT' && $inside) {
            $inside = false;
            $start = isset($current['DTSTART']) ? normalizeDate($current['DTSTART']) : null;
            $end = isset($current['DTEND']) ? normalizeDate($current['DTEND']) : null;
            if ($start && $end && $end > $start) {
                $uid = (string) ($current['UID'] ?? md5($apartmentId . $start->format('Y-m-d')));
                $events[] = [
                    'id' => 'ical_' . md5($apartmentId . $uid . $start->format('Y-m-d')),
                    'external_uid' => $uid,
                    'apartment_id' => $apartmentId,
                    'title' => (string) ($current['SUMMARY'] ?? strtoupper($source . ' booking')),
                    'status' => 'booked',
                    'start' => $start->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                    'source' => $source,
                ];
            }
            continue;
        }
        if (!$inside || $line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $key = strtoupper(explode(';', $k)[0]);
        $current[$key] = trim($v);
    }

    return $events;
}

function fetchIcsUrl(string $url): string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'CRL-Calendar/3.1'],
        'https' => ['timeout' => 20, 'user_agent' => 'CRL-Calendar/3.1'],
    ]);

    $content = @file_get_contents($url, false, $ctx);

    return $content === false ? '' : $content;
}

function flattenApartments(array $buildings): array
{
    $rows = [];
    foreach ($buildings as $building) {
        foreach (($building['apartments'] ?? []) as $apartment) {
            $rows[] = [
                'building_name' => (string) ($building['name'] ?? 'Building'),
                'id' => (string) ($apartment['id'] ?? ''),
                'name' => (string) ($apartment['name'] ?? 'Apartment'),
                'airbnb_url' => (string) ($apartment['airbnb_url'] ?? ''),
                'booking_url' => (string) ($apartment['booking_url'] ?? ''),
            ];
        }
    }

    return $rows;
}

function runSync(array $buildings, array &$syncMeta): array
{
    $apartments = flattenApartments($buildings);
    $ical = [];
    $status = [];

    foreach ($apartments as $apartment) {
        foreach (['airbnb', 'booking'] as $source) {
            $url = trim((string) ($apartment[$source . '_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $ics = fetchIcsUrl($url);
            $key = $apartment['name'] . ' (' . ucfirst($source) . ')';
            if ($ics === '') {
                $status[$key] = 'Failed to download feed';
                continue;
            }
            $parsed = parseIcs($ics, $apartment['id'], $source);
            $ical = array_merge($ical, $parsed);
            $status[$key] = 'Imported ' . count($parsed) . ' event(s)';
        }
    }

    $pdo = getDbPdo();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM reservations WHERE source='ical'");
            $ins = $pdo->prepare('INSERT INTO reservations (reservation_uuid, apartment_id, source, external_uid, title, status, start_date, end_date, readonly_flag, booking_channel) VALUES (:uuid,:apartment,:source,:external_uid,:title,:status,:start,:end,1,:channel)');
            foreach ($ical as $r) {
                $ins->execute([
                    ':uuid' => (string) $r['id'],
                    ':apartment' => (string) $r['apartment_id'],
                    ':source' => 'ical',
                    ':external_uid' => (string) ($r['external_uid'] ?? ''),
                    ':title' => (string) $r['title'],
                    ':status' => (string) $r['status'],
                    ':start' => (string) $r['start'],
                    ':end' => (string) $r['end'],
                    ':channel' => (string) ($r['source'] ?? 'ical'),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $status['database'] = 'DB write failed while syncing.';
        }
    }

    $syncMeta['last_sync'] = gmdate('c');
    $syncMeta['status'] = $status;
    writeJson(SYNC_META_FILE, $syncMeta);

    return ['imported' => count($ical)];
}

$messages = [];
$errors = [];
$buildings = readJson(BUILDINGS_FILE, [
    ['id' => 'bld_main', 'name' => 'Main Building', 'apartments' => [['id' => 'apt_101', 'name' => 'Apartment 101', 'airbnb_url' => '', 'booking_url' => '']]],
]);
$settings = readJson(SETTINGS_FILE, ['sync_interval_minutes' => 30]);
$syncMeta = readJson(SYNC_META_FILE, ['last_sync' => null, 'status' => []]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = (string) ($_POST['intent'] ?? '');

    if ($intent === 'add_building') {
        $name = trim((string) ($_POST['building_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Building name is required.';
        } else {
            $buildings[] = ['id' => generateId('bld'), 'name' => $name, 'apartments' => []];
            writeJson(BUILDINGS_FILE, $buildings);
            $messages[] = 'Building added.';
        }
    }

    if ($intent === 'add_apartment') {
        $buildingId = (string) ($_POST['building_id'] ?? '');
        $name = trim((string) ($_POST['apartment_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Apartment name is required.';
        } else {
            foreach ($buildings as &$building) {
                if ((string) ($building['id'] ?? '') !== $buildingId) {
                    continue;
                }
                $building['apartments'][] = ['id' => generateId('apt'), 'name' => $name, 'airbnb_url' => '', 'booking_url' => ''];
            }
            unset($building);
            writeJson(BUILDINGS_FILE, $buildings);
            $messages[] = 'Apartment added.';
        }
    }

    if ($intent === 'save_feeds') {
        foreach ($buildings as &$building) {
            foreach ($building['apartments'] as &$apartment) {
                $id = (string) $apartment['id'];
                $apartment['airbnb_url'] = trim((string) ($_POST['airbnb_' . $id] ?? ''));
                $apartment['booking_url'] = trim((string) ($_POST['booking_' . $id] ?? ''));
            }
            unset($apartment);
        }
        unset($building);
        writeJson(BUILDINGS_FILE, $buildings);
        $messages[] = 'Apartment feed URLs saved.';
    }

    if ($intent === 'save_settings') {
        $minutes = (int) ($_POST['sync_interval_minutes'] ?? 30);
        $settings['sync_interval_minutes'] = max(1, min(1440, $minutes));
        writeJson(SETTINGS_FILE, $settings);
        $messages[] = 'Auto-sync interval updated.';
    }

    if ($intent === 'sync_now') {
        $res = runSync($buildings, $syncMeta);
        $messages[] = 'Sync completed. Imported ' . (int) $res['imported'] . ' iCal events.';
    }

    if ($intent === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!hash_equals($new, $confirm)) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            $result = changeAdminPassword($current, $new);
            if ($result['ok']) {
                $messages[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }
    }

    if ($intent === 'upload_logo') {
        if (!isset($_FILES['logo_file']) || !is_array($_FILES['logo_file'])) {
            $errors[] = 'No logo file uploaded.';
        } else {
            $file = $_FILES['logo_file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload failed. Please try again.';
            } else {
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
                $mime = mime_content_type((string) $file['tmp_name']);
                if (!isset($allowed[$mime])) {
                    $errors[] = 'Only PNG, JPG, WEBP, or SVG logos are allowed.';
                } else {
                    $uploadDir = __DIR__ . '/data/uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $filename = 'logo.' . $allowed[$mime];
                    $dest = $uploadDir . '/' . $filename;
                    if (move_uploaded_file((string) $file['tmp_name'], $dest)) {
                        writeBranding(['logo_path' => 'data/uploads/' . $filename]);
                        $messages[] = 'Logo uploaded successfully.';
                    } else {
                        $errors[] = 'Could not save uploaded logo.';
                    }
                }
            }
        }
    }
}

$apartments = flattenApartments($buildings);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Tools - CLR Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('Admin Tools'); ?>
<main class="container page-with-header">
    <section class="panel">
        <?php foreach ($errors as $error): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php foreach ($messages as $message): ?><div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

        <h2>Admin Tools</h2>
        <div class="admin-grid">
            <form method="post" class="admin-card">
                <h3>Add building</h3>
                <input type="hidden" name="intent" value="add_building">
                <input type="text" name="building_name" placeholder="e.g. Sunset Tower" required>
                <button class="btn" type="submit">Add building</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Add apartment</h3>
                <input type="hidden" name="intent" value="add_apartment">
                <select name="building_id" required>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="apartment_name" placeholder="e.g. Apt 302" required>
                <button class="btn" type="submit">Add apartment</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Sync settings</h3>
                <input type="hidden" name="intent" value="save_settings">
                <label>Auto-sync every <input type="number" min="1" max="1440" name="sync_interval_minutes" value="<?= (int) $settings['sync_interval_minutes'] ?>"> minute(s)</label>
                <button class="btn" type="submit">Save interval</button>
                <p class="tiny">Last sync: <?= htmlspecialchars((string) ($syncMeta['last_sync'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></p>
            </form>

            <form method="post" class="admin-card">
                <h3>Sync now</h3>
                <input type="hidden" name="intent" value="sync_now">
                <p>Pull Airbnb/Booking iCal updates now.</p>
                <button class="btn accent" type="submit">Sync now</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Change admin password</h3>
                <input type="hidden" name="intent" value="change_password">
                <input type="password" name="current_password" placeholder="Current password" required>
                <input type="password" name="new_password" placeholder="New password" required>
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                <button class="btn" type="submit">Update password</button>
            </form>

            <form method="post" enctype="multipart/form-data" class="admin-card">
                <h3>Upload header logo</h3>
                <input type="hidden" name="intent" value="upload_logo">
                <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,.svg" required>
                <button class="btn" type="submit">Upload logo</button>
            </form>

            <article class="admin-card">
                <h3>Make reservation</h3>
                <p>Create manual reservations and view reservation history on dedicated page.</p>
                <a class="btn accent" href="reservations.php" target="_blank" rel="noopener">Make Reservation</a>
            </article>

            <article class="admin-card">
                <h3>Server health check</h3>
                <p>Verify PHP, MySQL, and writable paths.</p>
                <a class="btn" href="healthcheck.php" target="_blank" rel="noopener">Open Health Check</a>
            </article>
        </div>

        <?php if (!empty($syncMeta['status']) && is_array($syncMeta['status'])): ?>
            <ul class="status-list">
                <?php foreach ($syncMeta['status'] as $feed => $text): ?>
                    <li><strong><?= htmlspecialchars((string) $feed, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Apartment feed mapping</h2>
        <form method="post">
            <input type="hidden" name="intent" value="save_feeds">
            <div class="feed-grid">
                <?php foreach ($buildings as $building): ?>
                    <article class="feed-building">
                        <h3><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php foreach ($building['apartments'] as $apartment): ?>
                            <div class="feed-row">
                                <h4><?= htmlspecialchars((string) $apartment['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                <input type="url" name="airbnb_<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) ($apartment['airbnb_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Airbnb iCal URL">
                                <input type="url" name="booking_<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) ($apartment['booking_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Booking.com iCal URL">
                            </div>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <button class="btn" type="submit">Save feed URLs</button>
        </form>
    </section>
</main>
</body>
</html>

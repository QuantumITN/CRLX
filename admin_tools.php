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
const DB_CONFIG_FILE = DATA_DIR . '/db_config.php';

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
                'building_id' => (string) ($building['id'] ?? ''),
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

function deleteReservationsByApartmentIds(array $apartmentIds): void
{
    $apartmentIds = array_values(array_filter(array_map('strval', $apartmentIds)));
    if ($apartmentIds === []) {
        return;
    }

    $pdo = getDbPdo();
    if (!$pdo) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($apartmentIds), '?'));

    try {
        $stmt = $pdo->prepare("DELETE FROM reservations WHERE apartment_id IN ($placeholders)");
        if ($stmt) {
            $stmt->execute($apartmentIds);
        }
    } catch (Throwable $e) {
        // swallow DB cleanup error, config file remains source of truth
    }
}


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

    $php = "<?php
return " . $export . ";
";

    return file_put_contents(DB_CONFIG_FILE, $php) !== false;
}

function runSync(array $buildings, array &$syncMeta, string $scopeType = 'all', string $scopeValue = ''): array
{
    $apartments = flattenApartments($buildings);
    $targets = [];

    foreach ($apartments as $apartment) {
        if ($scopeType === 'building' && $apartment['building_id'] !== $scopeValue) {
            continue;
        }
        if ($scopeType === 'apartment' && $apartment['id'] !== $scopeValue) {
            continue;
        }
        $targets[] = $apartment;
    }

    if ($scopeType !== 'all' && $targets === []) {
        return ['imported' => 0, 'status' => ['sync' => 'No apartments found for the selected scope.']];
    }

    $targetApartmentIds = array_map(static fn(array $a): string => (string) $a['id'], $targets);
    $ical = [];
    $status = [];

    foreach ($targets as $apartment) {
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
            if ($scopeType === 'all') {
                $pdo->exec("DELETE FROM reservations WHERE source='ical'");
            } else {
                $placeholders = implode(',', array_fill(0, count($targetApartmentIds), '?'));
                $del = $pdo->prepare("DELETE FROM reservations WHERE source='ical' AND apartment_id IN ($placeholders)");
                if ($del) {
                    $del->execute($targetApartmentIds);
                }
            }
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

    $scopeLabel = strtoupper($scopeType);
    $syncMeta['last_sync'] = gmdate('c');
    $syncMeta['status'] = array_merge([$scopeLabel => 'Imported ' . count($ical) . ' total event(s).'], $status);
    writeJson(SYNC_META_FILE, $syncMeta);

    return ['imported' => count($ical), 'status' => $status];
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

    if ($intent === 'edit_building') {
        $buildingId = (string) ($_POST['building_id'] ?? '');
        $name = trim((string) ($_POST['building_name'] ?? ''));
        if ($buildingId === '' || $name === '') {
            $errors[] = 'Building and new name are required.';
        } else {
            $updated = false;
            foreach ($buildings as &$building) {
                if ((string) ($building['id'] ?? '') !== $buildingId) {
                    continue;
                }
                $building['name'] = $name;
                $updated = true;
                break;
            }
            unset($building);
            if ($updated) {
                writeJson(BUILDINGS_FILE, $buildings);
                $messages[] = 'Building updated.';
            } else {
                $errors[] = 'Building not found.';
            }
        }
    }

    if ($intent === 'delete_building') {
        $buildingId = (string) ($_POST['building_id'] ?? '');
        if ($buildingId === '') {
            $errors[] = 'Building selection is required.';
        } else {
            $newBuildings = [];
            $deleted = false;
            $deletedApartmentIds = [];
            foreach ($buildings as $building) {
                if ((string) ($building['id'] ?? '') === $buildingId) {
                    $deleted = true;
                    foreach (($building['apartments'] ?? []) as $apartment) {
                        $deletedApartmentIds[] = (string) ($apartment['id'] ?? '');
                    }
                    continue;
                }
                $newBuildings[] = $building;
            }

            if ($deleted) {
                $buildings = $newBuildings;
                writeJson(BUILDINGS_FILE, $buildings);
                deleteReservationsByApartmentIds($deletedApartmentIds);
                $messages[] = 'Building deleted.';
            } else {
                $errors[] = 'Building not found.';
            }
        }
    }

    if ($intent === 'add_apartment') {
        $buildingId = (string) ($_POST['building_id'] ?? '');
        $name = trim((string) ($_POST['apartment_name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Apartment name is required.';
        } else {
            $added = false;
            foreach ($buildings as &$building) {
                if ((string) ($building['id'] ?? '') !== $buildingId) {
                    continue;
                }
                $building['apartments'][] = ['id' => generateId('apt'), 'name' => $name, 'airbnb_url' => '', 'booking_url' => ''];
                $added = true;
                break;
            }
            unset($building);
            if ($added) {
                writeJson(BUILDINGS_FILE, $buildings);
                $messages[] = 'Apartment added.';
            } else {
                $errors[] = 'Building not found for apartment add.';
            }
        }
    }

    if ($intent === 'edit_apartment') {
        $apartmentId = (string) ($_POST['apartment_id'] ?? '');
        $name = trim((string) ($_POST['apartment_name'] ?? ''));
        $targetBuildingId = (string) ($_POST['target_building_id'] ?? '');
        if ($apartmentId === '' || $name === '' || $targetBuildingId === '') {
            $errors[] = 'Apartment, target building, and new name are required.';
        } else {
            $apartmentPayload = null;
            $sourceBuildingId = '';
            foreach ($buildings as &$building) {
                foreach (($building['apartments'] ?? []) as $i => $apartment) {
                    if ((string) ($apartment['id'] ?? '') !== $apartmentId) {
                        continue;
                    }
                    $apartmentPayload = $apartment;
                    $sourceBuildingId = (string) ($building['id'] ?? '');
                    array_splice($building['apartments'], $i, 1);
                    break 2;
                }
            }
            unset($building);

            if (!is_array($apartmentPayload)) {
                $errors[] = 'Apartment not found.';
            } else {
                $apartmentPayload['name'] = $name;
                $inserted = false;
                foreach ($buildings as &$building) {
                    if ((string) ($building['id'] ?? '') !== $targetBuildingId) {
                        continue;
                    }
                    $building['apartments'][] = $apartmentPayload;
                    $inserted = true;
                    break;
                }
                unset($building);

                if ($inserted) {
                    writeJson(BUILDINGS_FILE, $buildings);
                    $messages[] = 'Apartment updated and reassigned.';
                } else {
                    // rollback to original building if target invalid
                    foreach ($buildings as &$building) {
                        if ((string) ($building['id'] ?? '') !== $sourceBuildingId) {
                            continue;
                        }
                        $building['apartments'][] = $apartmentPayload;
                        break;
                    }
                    unset($building);
                    $errors[] = 'Target building not found.';
                }
            }
        }
    }

    if ($intent === 'delete_apartment') {
        $apartmentId = (string) ($_POST['apartment_id'] ?? '');
        if ($apartmentId === '') {
            $errors[] = 'Apartment selection is required.';
        } else {
            $deleted = false;
            foreach ($buildings as &$building) {
                foreach (($building['apartments'] ?? []) as $i => $apartment) {
                    if ((string) ($apartment['id'] ?? '') !== $apartmentId) {
                        continue;
                    }
                    array_splice($building['apartments'], $i, 1);
                    $deleted = true;
                    break 2;
                }
            }
            unset($building);

            if ($deleted) {
                writeJson(BUILDINGS_FILE, $buildings);
                deleteReservationsByApartmentIds([$apartmentId]);
                $messages[] = 'Apartment deleted.';
            } else {
                $errors[] = 'Apartment not found.';
            }
        }
    }

    if ($intent === 'save_feeds') {
        $buildingId = (string) ($_POST['feed_building_id'] ?? '');
        $apartmentId = (string) ($_POST['feed_apartment_id'] ?? '');
        $airbnbUrl = trim((string) ($_POST['feed_airbnb_url'] ?? ''));
        $bookingUrl = trim((string) ($_POST['feed_booking_url'] ?? ''));

        if ($buildingId === '' || $apartmentId === '') {
            $errors[] = 'Select both building and apartment for feed mapping.';
        } else {
            $updated = false;
            foreach ($buildings as &$building) {
                if ((string) ($building['id'] ?? '') !== $buildingId) {
                    continue;
                }
                foreach ($building['apartments'] as &$apartment) {
                    if ((string) $apartment['id'] !== $apartmentId) {
                        continue;
                    }
                    $apartment['airbnb_url'] = $airbnbUrl;
                    $apartment['booking_url'] = $bookingUrl;
                    $updated = true;
                    break;
                }
                unset($apartment);
                break;
            }
            unset($building);
            if ($updated) {
                writeJson(BUILDINGS_FILE, $buildings);
                $messages[] = 'Apartment feed URLs saved.';
            } else {
                $errors[] = 'Could not find selected apartment in selected building.';
            }
        }
    }

    if ($intent === 'save_settings') {
        $minutes = (int) ($_POST['sync_interval_minutes'] ?? 30);
        $settings['sync_interval_minutes'] = max(1, min(1440, $minutes));
        writeJson(SETTINGS_FILE, $settings);
        $messages[] = 'Auto-sync interval updated.';
    }

    if ($intent === 'sync_now') {
        $scopeType = (string) ($_POST['sync_scope'] ?? 'all');
        $scopeValue = '';
        if ($scopeType === 'building') {
            $scopeValue = (string) ($_POST['sync_building_id'] ?? '');
        } elseif ($scopeType === 'apartment') {
            $scopeValue = (string) ($_POST['sync_apartment_id'] ?? '');
        } else {
            $scopeType = 'all';
        }

        $res = runSync($buildings, $syncMeta, $scopeType, $scopeValue);
        $messages[] = 'Sync completed. Imported ' . (int) $res['imported'] . ' iCal events.';
    }

    if ($intent === 'save_db_config') {
        $cfg = [
            'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
            'charset' => trim((string) ($_POST['db_charset'] ?? 'utf8mb4')),
        ];

        if (saveDbConfig($cfg)) {
            $messages[] = 'Database config saved.';
        } else {
            $errors[] = 'Could not save database config file.';
        }
    }

    if ($intent === 'test_db_config') {
        $cfg = [
            'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
            'charset' => trim((string) ($_POST['db_charset'] ?? 'utf8mb4')),
        ];
        $test = testDbConnection($cfg);
        if ($test['ok']) {
            $messages[] = 'DB test successful: ' . (string) $test['message'];
        } else {
            $errors[] = 'DB test failed: ' . (string) $test['message'];
        }
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

$dbConfig = loadDbConfig();
$apartments = flattenApartments($buildings);
$apartmentsByBuilding = [];
foreach ($buildings as $building) {
    $buildingId = (string) ($building['id'] ?? '');
    $apartmentsByBuilding[$buildingId] = array_map(
        static fn(array $a): array => [
            'id' => (string) ($a['id'] ?? ''),
            'name' => (string) ($a['name'] ?? 'Apartment'),
            'airbnb_url' => (string) ($a['airbnb_url'] ?? ''),
            'booking_url' => (string) ($a['booking_url'] ?? ''),
            'building_id' => $buildingId,
        ],
        $building['apartments'] ?? []
    );
}
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
                <h3>Edit building name</h3>
                <input type="hidden" name="intent" value="edit_building">
                <select name="building_id" required>
                    <option value="">Select building</option>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="building_name" placeholder="New building name" required>
                <button class="btn" type="submit">Save building</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Delete building</h3>
                <input type="hidden" name="intent" value="delete_building">
                <select name="building_id" required>
                    <option value="">Select building</option>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="tiny">Deletes the building and all apartments under it.</p>
                <button class="btn" type="submit">Delete building</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Add apartment</h3>
                <input type="hidden" name="intent" value="add_apartment">
                <select name="building_id" required>
                    <option value="">Select building</option>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="apartment_name" placeholder="e.g. Apt 302" required>
                <button class="btn" type="submit">Add apartment</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Edit / reassign apartment</h3>
                <input type="hidden" name="intent" value="edit_apartment">
                <select name="apartment_id" required>
                    <option value="">Select apartment</option>
                    <?php foreach ($apartments as $apartment): ?>
                        <option value="<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apartment['building_name'] . ' / ' . $apartment['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="target_building_id" required>
                    <option value="">Target building</option>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="apartment_name" placeholder="New apartment name" required>
                <button class="btn" type="submit">Save apartment</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Delete apartment</h3>
                <input type="hidden" name="intent" value="delete_apartment">
                <select name="apartment_id" required>
                    <option value="">Select apartment</option>
                    <?php foreach ($apartments as $apartment): ?>
                        <option value="<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apartment['building_name'] . ' / ' . $apartment['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Delete apartment</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Sync settings</h3>
                <input type="hidden" name="intent" value="save_settings">
                <label>Auto-sync every <input type="number" min="1" max="1440" name="sync_interval_minutes" value="<?= (int) $settings['sync_interval_minutes'] ?>"> minute(s)</label>
                <button class="btn" type="submit">Save interval</button>
                <p class="tiny">Last sync: <?= htmlspecialchars((string) ($syncMeta['last_sync'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></p>
            </form>

            <form method="post" class="admin-card" id="sync-scope-form">
                <h3>Sync now</h3>
                <input type="hidden" name="intent" value="sync_now">
                <select name="sync_scope" id="sync_scope" required>
                    <option value="all">All buildings + apartments</option>
                    <option value="building">One building</option>
                    <option value="apartment">One apartment</option>
                </select>
                <select name="sync_building_id" id="sync_building_id">
                    <option value="">Select building</option>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sync_apartment_id" id="sync_apartment_id">
                    <option value="">Select apartment</option>
                    <?php foreach ($apartments as $apartment): ?>
                        <option value="<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($apartment['building_name'] . ' / ' . $apartment['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn accent" type="submit">Sync now</button>
            </form>

            <form method="post" class="admin-card">
                <h3>Database configuration</h3>
                <input type="hidden" name="intent" value="save_db_config">
                <input type="text" name="db_host" value="<?= htmlspecialchars((string) ($dbConfig['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB host" required>
                <input type="number" name="db_port" value="<?= (int) ($dbConfig['port'] ?? 3306) ?>" placeholder="DB port" required>
                <input type="text" name="db_name" value="<?= htmlspecialchars((string) ($dbConfig['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB name" required>
                <input type="text" name="db_user" value="<?= htmlspecialchars((string) ($dbConfig['user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB user" required>
                <input type="password" name="db_pass" value="<?= htmlspecialchars((string) ($dbConfig['pass'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="DB password">
                <input type="text" name="db_charset" value="<?= htmlspecialchars((string) ($dbConfig['charset'] ?? 'utf8mb4'), ENT_QUOTES, 'UTF-8') ?>" placeholder="utf8mb4" required>
                <div class="reservation-inline-actions">
                    <button class="btn" type="submit">Save DB config</button>
                </div>
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
        <form method="post" id="feed-mapping-form" class="feed-mapping-form">
            <input type="hidden" name="intent" value="save_feeds">
            <div class="feed-grid compact-grid">
                <div>
                    <label for="feed_building_id">Building</label>
                    <select name="feed_building_id" id="feed_building_id" required>
                        <option value="">Select building</option>
                        <?php foreach ($buildings as $building): ?>
                            <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="feed_apartment_id">Apartment</label>
                    <select name="feed_apartment_id" id="feed_apartment_id" required>
                        <option value="">Select apartment</option>
                    </select>
                </div>
            </div>
            <div class="feed-grid compact-grid">
                <div>
                    <label for="feed_airbnb_url">Airbnb iCal URL</label>
                    <input type="url" name="feed_airbnb_url" id="feed_airbnb_url" placeholder="https://...">
                </div>
                <div>
                    <label for="feed_booking_url">Booking.com iCal URL</label>
                    <input type="url" name="feed_booking_url" id="feed_booking_url" placeholder="https://...">
                </div>
            </div>
            <button class="btn" type="submit">Save feed URLs</button>
        </form>
        <p class="tiny">Use dropdowns to manage one apartment mapping at a time (cleaner for large portfolios).</p>
    </section>
</main>
<script>
window.ADMIN_APARTMENTS_BY_BUILDING = <?= json_encode($apartmentsByBuilding, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}' ?>;
(() => {
    const byBuilding = window.ADMIN_APARTMENTS_BY_BUILDING || {};
    const feedBuilding = document.getElementById('feed_building_id');
    const feedApartment = document.getElementById('feed_apartment_id');
    const feedAirbnb = document.getElementById('feed_airbnb_url');
    const feedBooking = document.getElementById('feed_booking_url');

    function apartmentList(buildingId) {
        return Array.isArray(byBuilding[buildingId]) ? byBuilding[buildingId] : [];
    }

    function refreshApartmentOptions() {
        if (!feedBuilding || !feedApartment) return;
        const currentBuilding = feedBuilding.value;
        const list = apartmentList(currentBuilding);
        feedApartment.innerHTML = '<option value="">Select apartment</option>';
        list.forEach((apt) => {
            const opt = document.createElement('option');
            opt.value = apt.id;
            opt.textContent = apt.name;
            feedApartment.appendChild(opt);
        });
        feedAirbnb.value = '';
        feedBooking.value = '';
    }

    function loadSelectedApartmentFeeds() {
        if (!feedBuilding || !feedApartment) return;
        const list = apartmentList(feedBuilding.value);
        const selected = list.find((apt) => apt.id === feedApartment.value);
        feedAirbnb.value = selected ? (selected.airbnb_url || '') : '';
        feedBooking.value = selected ? (selected.booking_url || '') : '';
    }

    if (feedBuilding) {
        feedBuilding.addEventListener('change', refreshApartmentOptions);
    }
    if (feedApartment) {
        feedApartment.addEventListener('change', loadSelectedApartmentFeeds);
    }

    const syncScope = document.getElementById('sync_scope');
    const syncBuilding = document.getElementById('sync_building_id');
    const syncApartment = document.getElementById('sync_apartment_id');

    function refreshSyncScope() {
        if (!syncScope || !syncBuilding || !syncApartment) return;
        const scope = syncScope.value;
        syncBuilding.disabled = scope !== 'building';
        syncApartment.disabled = scope !== 'apartment';
        syncBuilding.required = scope === 'building';
        syncApartment.required = scope === 'apartment';
        if (scope !== 'building') syncBuilding.value = '';
        if (scope !== 'apartment') syncApartment.value = '';
    }

    if (syncScope) {
        syncScope.addEventListener('change', refreshSyncScope);
        refreshSyncScope();
    }
})();
</script>
</body>
</html>

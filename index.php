<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireAuth();

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/data';
const BUILDINGS_FILE = DATA_DIR . '/buildings.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const RESERVATIONS_FILE = DATA_DIR . '/reservations.json';
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

function unfoldIcsLines(string $ics): array
{
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $rows = explode("\n", $ics);
    $lines = [];

    foreach ($rows as $row) {
        if (($row[0] ?? '') === ' ' || ($row[0] ?? '') === "\t") {
            if (!empty($lines)) {
                $lines[count($lines) - 1] .= ltrim($row);
            }
            continue;
        }
        $lines[] = trim($row);
    }

    return $lines;
}

function parseIcs(string $ics, string $apartmentId, string $source): array
{
    $lines = unfoldIcsLines($ics);
    $events = [];
    $current = [];
    $inEvent = false;

    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $inEvent = true;
            $current = [];
            continue;
        }
        if ($line === 'END:VEVENT' && $inEvent) {
            $inEvent = false;
            $start = isset($current['DTSTART']) ? normalizeDate($current['DTSTART']) : null;
            $end = isset($current['DTEND']) ? normalizeDate($current['DTEND']) : null;

            if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable && $end > $start) {
                $uid = (string) ($current['UID'] ?? md5($apartmentId . $start->format('Y-m-d') . $source));
                $events[] = [
                    'id' => 'ical_' . md5($apartmentId . $uid . $start->format('Y-m-d')),
                    'external_uid' => $uid,
                    'apartment_id' => $apartmentId,
                    'title' => trim((string) ($current['SUMMARY'] ?? strtoupper($source . ' booking'))),
                    'status' => 'booked',
                    'start' => $start->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                    'source' => $source,
                    'readonly' => true,
                ];
            }
            continue;
        }
        if (!$inEvent || $line === '') {
            continue;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = strtoupper(explode(';', $parts[0])[0]);
        $current[$key] = trim($parts[1]);
    }

    return $events;
}

function fetchIcsUrl(string $url): string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'CRL-Calendar/3.0'],
        'https' => ['timeout' => 20, 'user_agent' => 'CRL-Calendar/3.0'],
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


function buildExportIcs(array $reservations): string
{
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//CLR Calendar//Portfolio Availability//EN',
        'CALSCALE:GREGORIAN',
    ];

    foreach ($reservations as $r) {
        if (!isset($r['start'], $r['end'])) {
            continue;
        }
        $start = str_replace('-', '', (string) $r['start']);
        $end = str_replace('-', '', (string) $r['end']);
        $uid = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($r['id'] ?? md5($start . $end)));
        $title = addcslashes((string) ($r['title'] ?? strtoupper((string) ($r['status'] ?? 'BOOKED'))), ",;\\");

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid . '@clr-calendar.local';
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART;VALUE=DATE:' . $start;
        $lines[] = 'DTEND;VALUE=DATE:' . $end;
        $lines[] = 'SUMMARY:' . $title;
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
}

function dbFetchReservationsForCalendar(): array
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->query("SELECT reservation_uuid, apartment_id, title, status, start_date, end_date, source, readonly_flag FROM reservations WHERE archived_flag = 0 ORDER BY start_date ASC");
    $rows = $stmt ? $stmt->fetchAll() : [];

    return array_map(static function (array $r): array {
        return [
            'id' => (string) $r['reservation_uuid'],
            'apartment_id' => (string) $r['apartment_id'],
            'title' => (string) $r['title'],
            'status' => (string) $r['status'],
            'start' => (string) $r['start_date'],
            'end' => (string) $r['end_date'],
            'source' => (string) $r['source'],
            'readonly' => ((int) $r['readonly_flag']) === 1,
        ];
    }, $rows);
}

function dbSaveDragUpdates(array $manual): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE reservations SET apartment_id = :apartment_id, start_date = :start_date, end_date = :end_date, updated_at = NOW() WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
        $event = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, :event_type, :payload)');

        foreach ($manual as $r) {
            if (!is_array($r)) {
                continue;
            }
            $uuid = (string) ($r['id'] ?? '');
            $apartmentId = (string) ($r['apartment_id'] ?? '');
            $start = (string) ($r['start'] ?? '');
            $end = (string) ($r['end'] ?? '');
            if ($uuid === '' || $apartmentId === '') {
                continue;
            }

            $update->execute([
                ':apartment_id' => $apartmentId,
                ':start_date' => $start,
                ':end_date' => $end,
                ':uuid' => $uuid,
            ]);

            $event->execute([
                ':uuid' => $uuid,
                ':event_type' => 'drag_update',
                ':payload' => json_encode(['apartment_id' => $apartmentId, 'start' => $start, 'end' => $end]),
            ]);
        }

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();

        return false;
    }
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
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM reservations WHERE source = 'ical'");
            $ins = $pdo->prepare('INSERT INTO reservations (reservation_uuid, apartment_id, source, external_uid, title, status, start_date, end_date, readonly_flag, booking_channel) VALUES (:uuid,:apartment,:source,:external_uid,:title,:status,:start_date,:end_date,1,:channel)');

            foreach ($ical as $r) {
                $ins->execute([
                    ':uuid' => (string) $r['id'],
                    ':apartment' => (string) $r['apartment_id'],
                    ':source' => 'ical',
                    ':external_uid' => (string) ($r['external_uid'] ?? ''),
                    ':title' => (string) $r['title'],
                    ':status' => (string) $r['status'],
                    ':start_date' => (string) $r['start'],
                    ':end_date' => (string) $r['end'],
                    ':channel' => (string) ($r['source'] ?? 'ical'),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $status['database'] = 'Could not write imported iCal reservations to MySQL.';
        }
    } else {
        $fallback = readJson(RESERVATIONS_FILE, ['manual' => [], 'ical' => []]);
        $fallback['ical'] = $ical;
        writeJson(RESERVATIONS_FILE, $fallback);
        $status['database'] = 'MySQL not configured, using JSON fallback for iCal records.';
    }

    $syncMeta['last_sync'] = gmdate('c');
    $syncMeta['status'] = $status;
    writeJson(SYNC_META_FILE, $syncMeta);

    return ['imported' => count($ical), 'status' => $status];
}

$errors = [];
$messages = [];

$buildings = readJson(BUILDINGS_FILE, [
    ['id' => 'bld_main', 'name' => 'Main Building', 'apartments' => [['id' => 'apt_101', 'name' => 'Apartment 101', 'airbnb_url' => '', 'booking_url' => '']]],
]);
$settings = readJson(SETTINGS_FILE, ['sync_interval_minutes' => 30]);
$syncMeta = readJson(SYNC_META_FILE, ['last_sync' => null, 'status' => []]);

$action = $_GET['action'] ?? '';
if ($action === 'export') {
    $all = dbFetchReservationsForCalendar();
    if (empty($all)) {
        $fallback = readJson(RESERVATIONS_FILE, ['manual' => [], 'ical' => []]);
        $all = array_merge($fallback['manual'] ?? [], $fallback['ical'] ?? []);
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="portfolio-availability.ics"');
    echo buildExportIcs($all);
    exit;
}
if ($action === 'api_save_manual' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $manual = is_array($payload['manual'] ?? null) ? $payload['manual'] : [];

    $ok = dbSaveDragUpdates($manual);
    if (!$ok) {
        $fallback = readJson(RESERVATIONS_FILE, ['manual' => [], 'ical' => []]);
        $fallback['manual'] = $manual;
        $ok = writeJson(RESERVATIONS_FILE, $fallback);
    }

    echo json_encode(['ok' => $ok]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['intent'] ?? '') === 'sync_now') {
    $result = runSync($buildings, $syncMeta);
    $messages[] = 'Sync completed. Imported ' . $result['imported'] . ' iCal event(s).';
}

$interval = (int) ($settings['sync_interval_minutes'] ?? 30);
$lastSyncTs = isset($syncMeta['last_sync']) ? strtotime((string) $syncMeta['last_sync']) : false;
if ($lastSyncTs === false || (time() - $lastSyncTs) >= ($interval * 60)) {
    runSync($buildings, $syncMeta);
    $messages[] = 'Auto-sync executed based on your interval setting.';
}

$reservations = dbFetchReservationsForCalendar();
if (empty($reservations)) {
    $fallback = readJson(RESERVATIONS_FILE, ['manual' => [], 'ical' => []]);
    $reservations = array_merge($fallback['manual'] ?? [], $fallback['ical'] ?? []);
    $messages[] = 'MySQL not configured. Using JSON fallback. Configure MySQL for full reservation history + customer details.';
}

$monthStart = new DateTimeImmutable(date('Y-m-01'));
$daysInMonth = (int) $monthStart->format('t');
$monthLabel = $monthStart->format('F Y');
$statusColors = [
    'booked' => '#2dc26b',
    'reserved' => '#3498ff',
    'checked_out' => '#ef4444',
    'checkout_tomorrow' => '#f59e0b',
    'maintenance' => '#8b5cf6',
    'service' => '#ff7f50',
];

$appData = [
    'apartments' => flattenApartments($buildings),
    'manualReservations' => array_values(array_filter($reservations, static fn(array $r): bool => ($r['source'] ?? '') === 'manual')),
    'icalReservations' => array_values(array_filter($reservations, static fn(array $r): bool => ($r['source'] ?? '') !== 'manual')),
    'monthStart' => $monthStart->format('Y-m-d'),
    'monthDays' => $daysInMonth,
    'monthLabel' => $monthLabel,
    'statusColors' => $statusColors,
];

$exportUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?')
    . '?action=export';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CLR Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="container">
    <header class="hero panel">
        <h1>CLR Calendar</h1>
        <p>Availability board for all properties with MySQL-backed reservation history.</p>
        <p><a class="btn" href="admin_tools.php" target="_blank" rel="noopener">Admin Tools</a> <a class="btn ghost" href="logout.php">Logout</a></p>
    </header>

    <section class="panel">
        <h2>Management</h2>
        <?php foreach ($errors as $error): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $message): ?>
            <div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="admin-grid">
            <article class="admin-card">
                <h3>Admin tools</h3>
                <p>Create buildings/apartments, run sync, map iCal, and create manual reservations.</p>
                <a class="btn" href="admin_tools.php" target="_blank" rel="noopener">Open Admin Tools</a>
            </article>

            <article class="admin-card">
                <h3>Provider connections</h3>
                <p>Configure Airbnb/Booking connectors and import listings.</p>
                <a class="btn ghost" href="settings.php" target="_blank" rel="noopener">Open Settings</a>
            </article>

            <article class="admin-card">
                <h3>Merged export URL</h3>
                <input readonly type="text" value="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" id="export-url">
                <button class="btn small" type="button" id="copy-export">Copy</button>
                <form method="post"><input type="hidden" name="intent" value="sync_now"><button class="btn accent" type="submit">Sync now</button></form>
            </article>
        </div>
    </section>

    <section class="panel">
        <div class="board-head">
            <h2>Availability board — <?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="legend">
                <span><i style="background:#2dc26b"></i>Booked</span>
                <span><i style="background:#3498ff"></i>Reserved</span>
                <span><i style="background:#ef4444"></i>Checked out</span>
                <span><i style="background:#f59e0b"></i>Checkout tomorrow</span>
                <span><i style="background:#8b5cf6"></i>Maintenance</span>
            </div>
        </div>
        <p class="tiny">Drag non-iCal bars to move reservation dates/apartments. Changes are stored in MySQL when configured.</p>
        <div id="scheduler" class="scheduler"></div>
    </section>
</main>
<script>
window.APP = <?= json_encode($appData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}' ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>

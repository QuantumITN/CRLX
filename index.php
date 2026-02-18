<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';

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

function fetchIcsUrl(string $url): string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'CRL-Calendar/3.1'],
        'https' => ['timeout' => 20, 'user_agent' => 'CRL-Calendar/3.1'],
    ]);

    $content = @file_get_contents($url, false, $ctx);

    return $content === false ? '' : $content;
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
                    'readonly' => true,
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

    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE reservations SET apartment_id = :apartment_id, start_date = :start_date, end_date = :end_date, updated_at = NOW() WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
        $event = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, :event_type, :payload)');

        foreach ($manual as $r) {
            $update->execute([
                ':apartment_id' => (string) ($r['apartment_id'] ?? ''),
                ':start_date' => (string) ($r['start'] ?? ''),
                ':end_date' => (string) ($r['end'] ?? ''),
                ':uuid' => (string) ($r['id'] ?? ''),
            ]);
            $event->execute([
                ':uuid' => (string) ($r['id'] ?? ''),
                ':event_type' => 'drag_update',
                ':payload' => json_encode($r),
            ]);
        }
        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();

        return false;
    }
}


function dbUpdateManualReservation(array $reservation): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE reservations SET apartment_id = :apartment_id, title = :title, status = :status, start_date = :start_date, end_date = :end_date, updated_at = NOW() WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
    $ok = $stmt->execute([
        ':apartment_id' => (string) ($reservation['apartment_id'] ?? ''),
        ':title' => (string) ($reservation['title'] ?? 'Manual reservation'),
        ':status' => (string) ($reservation['status'] ?? 'reserved'),
        ':start_date' => (string) ($reservation['start'] ?? ''),
        ':end_date' => (string) ($reservation['end'] ?? ''),
        ':uuid' => (string) ($reservation['id'] ?? ''),
    ]);

    if ($ok) {
        $event = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, :event_type, :payload)');
        $event->execute([
            ':uuid' => (string) ($reservation['id'] ?? ''),
            ':event_type' => 'manual_update',
            ':payload' => json_encode($reservation),
        ]);
    }

    return $ok;
}

function dbDeleteManualReservation(string $reservationId): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM reservations WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
    $ok = $stmt->execute([':uuid' => $reservationId]);

    if ($ok) {
        $event = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, :event_type, :payload)');
        $event->execute([
            ':uuid' => $reservationId,
            ':event_type' => 'manual_deleted',
            ':payload' => json_encode(['id' => $reservationId]),
        ]);
    }

    return $ok;
}

function buildExportIcs(array $reservations): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CLR Calendar//Portfolio Availability//EN', 'CALSCALE:GREGORIAN'];
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

function runSync(array $buildings, array &$syncMeta): void
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
        }
    }

    $syncMeta['last_sync'] = gmdate('c');
    $syncMeta['status'] = $status;
    file_put_contents(SYNC_META_FILE, json_encode($syncMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$messages = [];
$buildings = readJson(BUILDINGS_FILE, []);
$settings = readJson(SETTINGS_FILE, ['sync_interval_minutes' => 30]);
$syncMeta = readJson(SYNC_META_FILE, ['last_sync' => null, 'status' => []]);

$action = (string) ($_GET['action'] ?? '');
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
    echo json_encode(['ok' => $ok]);
    exit;
}


if ($action === 'api_update_reservation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $reservation = is_array($payload['reservation'] ?? null) ? $payload['reservation'] : [];
    $ok = dbUpdateManualReservation($reservation);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'api_delete_reservation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $reservationId = (string) ($payload['id'] ?? '');
    $ok = $reservationId !== '' && dbDeleteManualReservation($reservationId);
    echo json_encode(['ok' => $ok]);
    exit;
}

$interval = (int) ($settings['sync_interval_minutes'] ?? 30);
$lastSyncTs = isset($syncMeta['last_sync']) ? strtotime((string) $syncMeta['last_sync']) : false;
if ($lastSyncTs === false || (time() - $lastSyncTs) >= ($interval * 60)) {
    runSync($buildings, $syncMeta);
    $messages[] = 'Auto-sync executed in background.';
}

$reservations = dbFetchReservationsForCalendar();
if (empty($reservations)) {
    $fallback = readJson(RESERVATIONS_FILE, ['manual' => [], 'ical' => []]);
    $reservations = array_merge($fallback['manual'] ?? [], $fallback['ical'] ?? []);
    $messages[] = 'MySQL not configured. Using fallback data source.';
}

$monthParam = trim((string) ($_GET['month'] ?? ''));
$monthStart = DateTimeImmutable::createFromFormat('Y-m', $monthParam, new DateTimeZone('UTC'));
if (!$monthStart) {
    $monthStart = new DateTimeImmutable(date('Y-m-01'), new DateTimeZone('UTC'));
}
$monthStart = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), 1);
$prevMonth = $monthStart->modify('-1 month');
$nextMonth = $monthStart->modify('+1 month');
$appData = [
    'apartments' => flattenApartments($buildings),
    'manualReservations' => array_values(array_filter($reservations, static fn(array $r): bool => ($r['source'] ?? '') === 'manual')),
    'icalReservations' => array_values(array_filter($reservations, static fn(array $r): bool => ($r['source'] ?? '') !== 'manual')),
    'monthStart' => $monthStart->format('Y-m-d'),
    'monthDays' => (int) $monthStart->format('t'),
    'monthLabel' => $monthStart->format('F Y'),
    'statusColors' => [
        'booked' => '#2dc26b',
        'reserved' => '#3498ff',
        'checked_out' => '#ef4444',
        'checkout_tomorrow' => '#f59e0b',
        'maintenance' => '#8b5cf6',
        'service' => '#ff7f50',
    ],
];
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
<?php renderSiteHeader('Availability Calendar'); ?>
<main class="container page-with-header">
    <section class="panel">
        <?php foreach ($messages as $message): ?><div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <div class="board-head">
            <h2>Availability board — <?= htmlspecialchars($appData['monthLabel'], ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="month-nav">
                <a class="btn ghost small" href="?month=<?= urlencode($prevMonth->format('Y-m')) ?>">← Previous</a>
                <a class="btn ghost small" href="?month=<?= urlencode((new DateTimeImmutable(date('Y-m-01')))->format('Y-m')) ?>">Current</a>
                <a class="btn ghost small" href="?month=<?= urlencode($nextMonth->format('Y-m')) ?>">Next →</a>
            </div>
            <div class="legend">
                <span><i style="background:#2dc26b"></i>Booked</span>
                <span><i style="background:#3498ff"></i>Reserved</span>
                <span><i style="background:#ef4444"></i>Checked out</span>
                <span><i style="background:#f59e0b"></i>Checkout tomorrow</span>
                <span><i style="background:#8b5cf6"></i>Maintenance</span>
            </div>
        </div>
        <p class="tiny">Calendar-only view. All management is now in Admin Tools. Scroll inside the frame to see all apartments and days for the month.</p>
        <div class="scheduler-frame">
            <div id="scheduler" class="scheduler"></div>
        </div>
    </section>
</main>

<div id="reservation-modal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-modal="1"></div>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-label="Reservation details">
        <div class="modal-head">
            <h3>Reservation summary</h3>
            <button class="btn ghost small" type="button" id="modal-close">Close</button>
        </div>
        <form id="reservation-modal-form" class="modal-form">
            <div class="modal-grid">
                <label>Title <input type="text" id="modal-title"></label>
                <label>Status
                    <select id="modal-status">
                        <option value="reserved">Reserved</option>
                        <option value="booked">Booked</option>
                        <option value="checked_out">Checked out</option>
                        <option value="checkout_tomorrow">Checkout tomorrow</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </label>
                <label>Check-in <input type="date" id="modal-start"></label>
                <label>Check-out <input type="date" id="modal-end"></label>
                <label>Apartment
                    <select id="modal-apartment"></select>
                </label>
                <label>Source <input type="text" id="modal-source" disabled></label>
            </div>
            <p class="tiny" id="modal-note"></p>
            <div class="modal-actions">
                <button type="button" class="btn" id="modal-save">Update</button>
                <button type="button" class="btn" id="modal-cancel-status">Cancel reservation</button>
                <button type="button" class="btn" id="modal-delete">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
window.APP = <?= json_encode($appData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}' ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>

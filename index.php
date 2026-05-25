<?php



require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/reservation_documents.php';

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
                $price = extractIcsPrice($current);
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
                    'price_total' => $price['amount'],
                    'price_currency' => $price['currency'],
                    'booking_channel' => $source,
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

function extractIcsPrice(array $fields): array
{
    $currency = 'EUR';
    foreach (['X-CURRENCY', 'CURRENCY'] as $key) {
        if (!empty($fields[$key])) {
            $currency = strtoupper(trim((string) $fields[$key]));
            break;
        }
    }

    foreach (['X-PRICE', 'X-COST', 'X-AMOUNT', 'PRICE'] as $key) {
        if (!empty($fields[$key]) && preg_match('/(-?\d+(?:[\.,]\d+)?)/', (string) $fields[$key], $m)) {
            return ['amount' => (float) str_replace(',', '.', $m[1]), 'currency' => $currency];
        }
    }

    $desc = (string) ($fields['DESCRIPTION'] ?? '');
    if (preg_match('/(?:total|price|amount)\s*[:=]?\s*([A-Z]{3})?\s*(-?\d+(?:[\.,]\d+)?)/i', $desc, $m)) {
        if (!empty($m[1])) {
            $currency = strtoupper($m[1]);
        }
        return ['amount' => (float) str_replace(',', '.', $m[2]), 'currency' => $currency];
    }

    return ['amount' => null, 'currency' => $currency];
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
                'external_refs' => is_array($apartment['external_refs'] ?? null) ? $apartment['external_refs'] : [],
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
    $stmt = $pdo->query("SELECT reservation_uuid, apartment_id, title, status, start_date, end_date, source, readonly_flag, customer_first_name, customer_last_name, customer_email, customer_phone, customer_country, customer_document, adults, children, notes, price_total, price_currency, tax_amount, cleaning_fee, discount_amount, payment_status, payment_method, booking_channel FROM reservations WHERE archived_flag = 0 ORDER BY start_date ASC");
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
            'customer_first_name' => (string) ($r['customer_first_name'] ?? ''),
            'customer_last_name' => (string) ($r['customer_last_name'] ?? ''),
            'customer_email' => (string) ($r['customer_email'] ?? ''),
            'customer_phone' => (string) ($r['customer_phone'] ?? ''),
            'customer_country' => (string) ($r['customer_country'] ?? ''),
            'customer_document' => (string) ($r['customer_document'] ?? ''),
            'adults' => (int) ($r['adults'] ?? 1),
            'children' => (int) ($r['children'] ?? 0),
            'notes' => (string) ($r['notes'] ?? ''),
            'price_total' => (string) ($r['price_total'] ?? ''),
            'price_currency' => (string) ($r['price_currency'] ?? ''),
            'tax_amount' => (string) ($r['tax_amount'] ?? ''),
            'cleaning_fee' => (string) ($r['cleaning_fee'] ?? ''),
            'discount_amount' => (string) ($r['discount_amount'] ?? ''),
            'payment_status' => (string) ($r['payment_status'] ?? ''),
            'payment_method' => (string) ($r['payment_method'] ?? ''),
            'booking_channel' => (string) ($r['booking_channel'] ?? ''),
        ];
    }, $rows);
}


function dbHasOverlap(string $apartmentId, string $startDate, string $endDate, ?string $excludeUuid = null): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $sql = 'SELECT COUNT(*) FROM reservations WHERE archived_flag = 0 AND apartment_id = :apartment_id AND start_date < :end_date AND end_date > :start_date';
    if ($excludeUuid !== null && $excludeUuid !== '') {
        $sql .= ' AND reservation_uuid <> :exclude_uuid';
    }

    $stmt = $pdo->prepare($sql);
    $params = [
        ':apartment_id' => $apartmentId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ];
    if ($excludeUuid !== null && $excludeUuid !== '') {
        $params[':exclude_uuid'] = $excludeUuid;
    }
    $stmt->execute($params);

    return ((int) $stmt->fetchColumn()) > 0;
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
            $apartmentId = (string) ($r['apartment_id'] ?? '');
            $startDate = (string) ($r['start'] ?? '');
            $endDate = (string) ($r['end'] ?? '');
            $uuid = (string) ($r['id'] ?? '');

            if ($apartmentId === '' || $startDate === '' || $endDate === '' || $uuid === '') {
                throw new RuntimeException('Invalid reservation payload.');
            }
            if (dbHasOverlap($apartmentId, $startDate, $endDate, $uuid)) {
                throw new RuntimeException('Overlap detected for apartment.');
            }

            $update->execute([
                ':apartment_id' => $apartmentId,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':uuid' => $uuid,
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

    $apartmentId = (string) ($reservation['apartment_id'] ?? '');
    $startDate = (string) ($reservation['start'] ?? '');
    $endDate = (string) ($reservation['end'] ?? '');
    $uuid = (string) ($reservation['id'] ?? '');

    if ($apartmentId === '' || $startDate === '' || $endDate === '' || $uuid === '' || dbHasOverlap($apartmentId, $startDate, $endDate, $uuid)) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE reservations SET apartment_id = :apartment_id, title = :title, status = :status, start_date = :start_date, end_date = :end_date, customer_first_name = :first, customer_last_name = :last, customer_email = :email, customer_phone = :phone, customer_country = :country, customer_document = :document, adults = :adults, children = :children, notes = :notes, price_total = :price_total, price_currency = :currency, tax_amount = :tax, cleaning_fee = :cleaning, discount_amount = :discount, payment_status = :payment_status, payment_method = :payment_method, booking_channel = :booking_channel, updated_at = NOW() WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
    $ok = $stmt->execute([
        ':apartment_id' => $apartmentId,
        ':title' => (string) ($reservation['title'] ?? 'Manual reservation'),
        ':status' => (string) ($reservation['status'] ?? 'reserved'),
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':first' => (string) ($reservation['customer_first_name'] ?? ''),
        ':last' => (string) ($reservation['customer_last_name'] ?? ''),
        ':email' => (string) ($reservation['customer_email'] ?? ''),
        ':phone' => (string) ($reservation['customer_phone'] ?? ''),
        ':country' => (string) ($reservation['customer_country'] ?? ''),
        ':document' => (string) ($reservation['customer_document'] ?? ''),
        ':adults' => max(1, (int) ($reservation['adults'] ?? 1)),
        ':children' => max(0, (int) ($reservation['children'] ?? 0)),
        ':notes' => (string) ($reservation['notes'] ?? ''),
        ':price_total' => ((string) ($reservation['price_total'] ?? '') === '' ? null : (float) $reservation['price_total']),
        ':currency' => (string) ($reservation['price_currency'] ?? 'EUR'),
        ':tax' => ((string) ($reservation['tax_amount'] ?? '') === '' ? null : (float) $reservation['tax_amount']),
        ':cleaning' => ((string) ($reservation['cleaning_fee'] ?? '') === '' ? null : (float) $reservation['cleaning_fee']),
        ':discount' => ((string) ($reservation['discount_amount'] ?? '') === '' ? null : (float) $reservation['discount_amount']),
        ':payment_status' => (string) ($reservation['payment_status'] ?? ''),
        ':payment_method' => (string) ($reservation['payment_method'] ?? ''),
        ':booking_channel' => (string) ($reservation['booking_channel'] ?? ''),
        ':uuid' => $uuid,
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

function dbGetReservationByUuid(string $uuid): ?array
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT reservation_uuid, external_uid, booking_channel FROM reservations WHERE reservation_uuid = :uuid AND archived_flag = 0 LIMIT 1');
    $stmt->execute([':uuid' => $uuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function pushPricelabsReservationUpdate(array $reservation): array
{
    $settings = readJson(SETTINGS_FILE, []);
    $pl = is_array($settings['pricelabs'] ?? null) ? $settings['pricelabs'] : [];
    $apiKey = trim((string) ($pl['api_key'] ?? ''));
    $baseUrl = rtrim(trim((string) ($pl['base_url'] ?? 'https://api.pricelabs.co')), '/');
    $externalUid = trim((string) ($reservation['external_uid'] ?? ''));

    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'PriceLabs API key is missing in Settings.'];
    }
    if ($externalUid === '') {
        return ['ok' => false, 'message' => 'Reservation has no PriceLabs external ID.'];
    }

    $payload = [
        'reservation_id' => $externalUid,
        'check_in' => (string) ($reservation['start'] ?? ''),
        'check_out' => (string) ($reservation['end'] ?? ''),
        'status' => (string) ($reservation['status'] ?? ''),
        'guest_name' => trim(((string) ($reservation['customer_first_name'] ?? '')) . ' ' . ((string) ($reservation['customer_last_name'] ?? ''))),
        'guest_email' => (string) ($reservation['customer_email'] ?? ''),
        'guest_phone' => (string) ($reservation['customer_phone'] ?? ''),
        'price_total' => ((string) ($reservation['price_total'] ?? '') === '' ? null : (float) $reservation['price_total']),
        'currency' => (string) ($reservation['price_currency'] ?? ''),
        'notes' => (string) ($reservation['notes'] ?? ''),
    ];
    $json = json_encode($payload);
    if (!is_string($json)) {
        return ['ok' => false, 'message' => 'Could not encode PriceLabs payload.'];
    }

    $url = $baseUrl . '/v1/reservations/' . rawurlencode($externalUid);
    $headers = "Content-Type: application/json\r\nAccept: application/json\r\nx-api-key: {$apiKey}\r\nAuthorization: Bearer {$apiKey}\r\nUser-Agent: CRLX-PriceLabs-Bridge/1.0";
    $ctx = stream_context_create(['http' => ['method' => 'PUT', 'timeout' => 30, 'header' => $headers, 'content' => $json, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'message' => 'Failed to sync update to PriceLabs API.'];
    }

    return ['ok' => true, 'message' => 'Synced update to PriceLabs.'];
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
    $settings = readJson(SETTINGS_FILE, []);
    $plCfg = is_array($settings['pricelabs'] ?? null) ? $settings['pricelabs'] : [];
    $plKey = trim((string) ($plCfg['api_key'] ?? ''));
    $plBase = rtrim(trim((string) ($plCfg['base_url'] ?? 'https://api.pricelabs.co')), '/');
    $normalizeName = static function (string $name): string {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
        return trim($name);
    };
    if (!empty($plCfg['enabled']) && $plKey !== '') {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'header' => "Accept: application/json\r\nx-api-key: {$plKey}\r\nAuthorization: Bearer {$plKey}\r\nUser-Agent: CRLX-PriceLabs-Bridge/1.0"]]);
        $plListings = [];
        foreach (['/v1/listings', '/v1/listing_data', '/v1/listings_data'] as $endpoint) {
            $raw = @file_get_contents($plBase . $endpoint, false, $ctx);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($json)) {
                continue;
            }
            $rows = $json['listings'] ?? $json['data'] ?? $json['results'] ?? $json;
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string) ($row['id'] ?? $row['listing_id'] ?? $row['uuid'] ?? '');
                $name = trim((string) ($row['name'] ?? $row['listing_name'] ?? $row['title'] ?? ''));
                if ($id !== '' && $name !== '') {
                    $plListings[$normalizeName($name)] = ['id' => $id, 'name' => $name];
                }
            }
            if ($plListings !== []) {
                break;
            }
        }
        if ($plListings !== []) {
            foreach ($buildings as &$building) {
                foreach (($building['apartments'] ?? []) as &$apartment) {
                    $refs = is_array($apartment['external_refs'] ?? null) ? $apartment['external_refs'] : [];
                    if (trim((string) ($refs['pricelabs_listing_id'] ?? '')) !== '') {
                        continue;
                    }
                    $key = $normalizeName((string) ($apartment['name'] ?? ''));
                    if ($key !== '' && isset($plListings[$key])) {
                        $refs['pricelabs_listing_id'] = (string) $plListings[$key]['id'];
                        $refs['pricelabs_name'] = (string) $plListings[$key]['name'];
                        $apartment['external_refs'] = $refs;
                    }
                }
                unset($apartment);
            }
            unset($building);
            writeJson(BUILDINGS_FILE, $buildings);
        }
    }

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
    if (!empty($plCfg['enabled']) && $plKey !== '') {
        foreach ($apartments as $apartment) {
            $refs = is_array($apartment['external_refs'] ?? null) ? $apartment['external_refs'] : [];
            $listingId = trim((string) ($refs['pricelabs_listing_id'] ?? ''));
            if ($listingId === '') {
                continue;
            }
            $plEvents = [];
            foreach ([
                '/v1/reservations?listing_id=' . rawurlencode($listingId),
                '/v1/bookings?listing_id=' . rawurlencode($listingId),
                '/v1/listings/' . rawurlencode($listingId) . '/reservations',
            ] as $endpoint) {
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'header' => "Accept: application/json\r\nx-api-key: {$plKey}\r\nAuthorization: Bearer {$plKey}\r\nUser-Agent: CRLX-PriceLabs-Bridge/1.0"]]);
                $raw = @file_get_contents($plBase . $endpoint, false, $ctx);
                $json = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($json)) {
                    continue;
                }
                $rows = $json['reservations'] ?? $json['bookings'] ?? $json['data'] ?? $json['results'] ?? $json;
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $start = (string) ($row['check_in'] ?? $row['start_date'] ?? $row['arrival_date'] ?? '');
                    $end = (string) ($row['check_out'] ?? $row['end_date'] ?? $row['departure_date'] ?? '');
                    if ($start === '' || $end === '') {
                        continue;
                    }
                    $plEvents[] = [
                        'id' => 'pl_' . md5($listingId . '|' . ($row['id'] ?? $row['reservation_id'] ?? $start . $end)),
                        'external_uid' => (string) ($row['id'] ?? $row['reservation_id'] ?? ''),
                        'apartment_id' => (string) ($apartment['id'] ?? ''),
                        'title' => (string) ($row['guest_name'] ?? $row['title'] ?? $apartment['name'] . ' PriceLabs booking'),
                        'status' => (string) ($row['status'] ?? 'booked'),
                        'start' => substr($start, 0, 10),
                        'end' => substr($end, 0, 10),
                        'source' => 'pricelabs',
                        'price_total' => ((string) ($row['price_total'] ?? $row['amount'] ?? '') === '' ? null : (float) ($row['price_total'] ?? $row['amount'])),
                        'price_currency' => (string) ($row['currency'] ?? 'EUR'),
                    ];
                }
                break;
            }
            $ical = array_merge($ical, $plEvents);
            $status[$apartment['name'] . ' (PriceLabs)'] = 'Imported ' . count($plEvents) . ' reservation(s)';
        }
    }

    $pdo = getDbPdo();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM reservations WHERE source='ical' OR booking_channel='pricelabs'");
            $ins = $pdo->prepare('INSERT INTO reservations (reservation_uuid, apartment_id, source, external_uid, title, status, start_date, end_date, readonly_flag, booking_channel, price_total, price_currency) VALUES (:uuid,:apartment,:source,:external_uid,:title,:status,:start,:end,1,:channel,:price_total,:currency)');
            foreach ($ical as $r) {
                $apt = (string) $r['apartment_id'];
                $start = (string) $r['start'];
                $end = (string) $r['end'];
                if (function_exists('dbHasOverlap') && dbHasOverlap($apt, $start, $end)) {
                    $status[$r['title'] . ' (' . $apt . ')'] = 'Skipped due to overlap protection';
                    continue;
                }

                $ins->execute([
                    ':uuid' => (string) $r['id'],
                    ':apartment' => $apt,
                    ':source' => 'ical',
                    ':external_uid' => (string) ($r['external_uid'] ?? ''),
                    ':title' => (string) $r['title'],
                    ':status' => (string) $r['status'],
                    ':start' => $start,
                    ':end' => $end,
                    ':channel' => (string) ($r['source'] ?? 'ical'),
                    ':price_total' => ((string) ($r['price_total'] ?? '') === '' ? null : (float) $r['price_total']),
                    ':currency' => (string) ($r['price_currency'] ?? 'EUR'),
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
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Saved.' : 'Update rejected due to overlap or invalid payload.']);
    exit;
}


if ($action === 'api_update_reservation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $reservation = is_array($payload['reservation'] ?? null) ? $payload['reservation'] : [];
    $ok = dbUpdateManualReservation($reservation);
    $message = $ok ? 'Updated.' : 'Update rejected due to overlap or invalid payload.';
    if ($ok) {
        $existing = dbGetReservationByUuid((string) ($reservation['id'] ?? ''));
        if ($existing && strtolower((string) ($existing['booking_channel'] ?? '')) === 'pricelabs') {
            $reservation['external_uid'] = (string) ($existing['external_uid'] ?? '');
            $sync = pushPricelabsReservationUpdate($reservation);
            if (!$sync['ok']) {
                $message .= ' Local save OK, but PriceLabs sync failed: ' . (string) ($sync['message'] ?? '');
            }
        }
    }
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($action === 'api_delete_reservation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $reservationId = (string) ($payload['id'] ?? '');
    $ok = $reservationId !== '' && dbDeleteManualReservation($reservationId);
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Deleted.' : 'Delete failed.']);
    exit;
}

if ($action === 'api_upload_reservation_docs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $reservationId = trim((string) ($_POST['reservation_id'] ?? ''));
    $upload = $_FILES['documents'] ?? null;

    if ($reservationId === '' || !is_array($upload)) {
        echo json_encode(['ok' => false, 'message' => 'Reservation ID and files are required.']);
        exit;
    }

    $error = null;
    $uploaded = uploadReservationDocuments($reservationId, $upload, $error);
    $docs = listReservationDocuments($reservationId);
    echo json_encode([
        'ok' => $uploaded !== [],
        'message' => $uploaded !== [] ? 'Document(s) uploaded.' : ($error ?? 'Upload failed.'),
        'documents' => $docs,
    ]);
    exit;
}

if ($action === 'api_delete_reservation_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $reservationId = trim((string) ($payload['reservation_id'] ?? ''));
    $filename = trim((string) ($payload['filename'] ?? ''));

    if ($reservationId === '' || $filename === '') {
        echo json_encode(['ok' => false, 'message' => 'Reservation ID and filename are required.']);
        exit;
    }

    $ok = deleteReservationDocument($reservationId, $filename);
    echo json_encode([
        'ok' => $ok,
        'message' => $ok ? 'Document deleted.' : 'Could not delete document.',
        'documents' => listReservationDocuments($reservationId),
    ]);
    exit;
}

if ($action === 'api_replace_reservation_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $reservationId = trim((string) ($_POST['reservation_id'] ?? ''));
    $filename = trim((string) ($_POST['filename'] ?? ''));
    $upload = $_FILES['replacement'] ?? null;

    if ($reservationId === '' || $filename === '' || !is_array($upload)) {
        echo json_encode(['ok' => false, 'message' => 'Reservation, document and replacement file are required.']);
        exit;
    }

    $error = null;
    $replaced = replaceReservationDocument($reservationId, $filename, $upload, $error);
    echo json_encode([
        'ok' => $replaced !== [],
        'message' => $replaced !== [] ? 'Document replaced.' : ($error ?? 'Could not replace document.'),
        'documents' => listReservationDocuments($reservationId),
    ]);
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
    'openReservationId' => trim((string) ($_GET['open_reservation'] ?? '')),
    'reservationDocuments' => reservationDocumentsByIds(array_column($reservations, 'id')),
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
<main class="container page-with-header calendar-page">
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
            <button class="btn ghost small" type="button" id="modal-close">Cancel</button>
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
                <label>Building
                    <select id="modal-building"></select>
                </label>
                <label>Apartment
                    <select id="modal-apartment"></select>
                </label>
                <label>Source <input type="text" id="modal-source" disabled></label>
                <label>First name <input type="text" id="modal-first-name"></label>
                <label>Last name <input type="text" id="modal-last-name"></label>
                <label>Email <input type="email" id="modal-email"></label>
                <label>Phone <input type="text" id="modal-phone"></label>
                <label>Country <input type="text" id="modal-country"></label>
                <label>Passport/ID <input type="text" id="modal-document"></label>
                <label>Adults <input type="number" min="1" id="modal-adults"></label>
                <label>Children <input type="number" min="0" id="modal-children"></label>
                <label>Total price <input type="number" step="0.01" id="modal-price-total"></label>
                <label>Currency <input type="text" id="modal-currency"></label>
                <label>Tax amount <input type="number" step="0.01" id="modal-tax"></label>
                <label>Cleaning fee <input type="number" step="0.01" id="modal-cleaning"></label>
                <label>Discount <input type="number" step="0.01" id="modal-discount"></label>
                <label>Payment status <input type="text" id="modal-payment-status"></label>
                <label>Payment method <input type="text" id="modal-payment-method"></label>
                <label>Booking channel <input type="text" id="modal-booking-channel"></label>
                <label style="grid-column:1/-1;">Notes <textarea id="modal-notes"></textarea></label>
            </div>
            <section class="doc-section">
                <div class="doc-section-head">
                    <strong>Customer documents (max 5)</strong>
                    <span class="tiny">PNG, JPG/JPEG or PDF</span>
                </div>
                <label class="doc-dropzone" for="modal-doc-upload" id="modal-doc-dropzone">Click to upload documents</label>
                <input type="file" id="modal-doc-upload" multiple accept=".png,.jpg,.jpeg,.jpn,.pdf" style="display:none">
                <div id="modal-doc-list" class="doc-grid"></div>
            </section>
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

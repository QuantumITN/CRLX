<?php



require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';
requireAuth();

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/data';
const BUILDINGS_FILE = DATA_DIR . '/buildings.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';

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

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $default;
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

function fetchProviderListings(string $provider, array $cfg): array
{
    $apiUrl = trim((string) ($cfg['api_url'] ?? ''));
    if ($apiUrl === '') {
        return ['ok' => false, 'message' => 'API URL is empty.', 'listings' => []];
    }

    $headers = [
        'Accept: application/json',
        'User-Agent: CRLX-Provider-Connector/1.0',
    ];

    $token = trim((string) ($cfg['token'] ?? ''));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $opts = [
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'header' => implode("\r\n", $headers),
        ],
        'https' => [
            'method' => 'GET',
            'timeout' => 25,
            'header' => implode("\r\n", $headers),
        ],
    ];

    $context = stream_context_create($opts);
    $raw = @file_get_contents($apiUrl, false, $context);

    if ($raw === false) {
        return [
            'ok' => false,
            'message' => 'Could not reach the provider API URL. Check URL/token/host firewall.',
            'listings' => [],
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'message' => 'Provider response is not valid JSON.', 'listings' => []];
    }

    $listings = $json['listings'] ?? $json['data'] ?? $json;
    if (!is_array($listings)) {
        return ['ok' => false, 'message' => 'No listings array found in provider response.', 'listings' => []];
    }

    $normalized = [];
    foreach ($listings as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string) ($item['name'] ?? $item['title'] ?? $item['listing_name'] ?? ''));
        $externalId = trim((string) ($item['id'] ?? $item['listing_id'] ?? ''));
        $ical = trim((string) ($item['ical_url'] ?? $item['iCalUrl'] ?? $item['calendar_url'] ?? ''));

        if ($name === '' || $externalId === '') {
            continue;
        }

        $normalized[] = [
            'external_id' => $externalId,
            'name' => $name,
            'ical_url' => $ical,
            'building_name' => trim((string) ($item['building'] ?? $item['building_name'] ?? ucfirst($provider) . ' Imported')),
        ];
    }

    return [
        'ok' => true,
        'message' => 'Fetched ' . count($normalized) . ' listing(s).',
        'listings' => $normalized,
    ];
}

function importListingsIntoBuildings(string $provider, array $listings, array &$buildings): int
{
    $imported = 0;
    foreach ($listings as $listing) {
        $buildingName = (string) ($listing['building_name'] ?? ucfirst($provider) . ' Imported');
        $buildingIndex = null;

        foreach ($buildings as $idx => $building) {
            if (strcasecmp((string) ($building['name'] ?? ''), $buildingName) === 0) {
                $buildingIndex = $idx;
                break;
            }
        }

        if ($buildingIndex === null) {
            $buildings[] = [
                'id' => generateId('bld'),
                'name' => $buildingName,
                'apartments' => [],
            ];
            $buildingIndex = count($buildings) - 1;
        }

        $foundApartmentIndex = null;
        foreach ($buildings[$buildingIndex]['apartments'] as $aIdx => $apartment) {
            $refs = is_array($apartment['external_refs'] ?? null) ? $apartment['external_refs'] : [];
            if (($refs[$provider] ?? '') === $listing['external_id']) {
                $foundApartmentIndex = $aIdx;
                break;
            }
        }

        if ($foundApartmentIndex === null) {
            $buildings[$buildingIndex]['apartments'][] = [
                'id' => generateId('apt'),
                'name' => $listing['name'],
                'airbnb_url' => '',
                'booking_url' => '',
                'external_refs' => [$provider => $listing['external_id']],
            ];
            $foundApartmentIndex = count($buildings[$buildingIndex]['apartments']) - 1;
        }

        $apt = &$buildings[$buildingIndex]['apartments'][$foundApartmentIndex];
        $apt['name'] = $listing['name'];

        if ($provider === 'airbnb' && $listing['ical_url'] !== '') {
            $apt['airbnb_url'] = $listing['ical_url'];
        }
        if ($provider === 'booking' && $listing['ical_url'] !== '') {
            $apt['booking_url'] = $listing['ical_url'];
        }

        $refs = is_array($apt['external_refs'] ?? null) ? $apt['external_refs'] : [];
        $refs[$provider] = $listing['external_id'];
        $apt['external_refs'] = $refs;
        unset($apt);

        $imported++;
    }

    return $imported;
}

function parseBulkIcalLines(string $input): array
{
    $rows = preg_split('/\r\n|\r|\n/', $input) ?: [];
    $parsed = [];
    $errors = [];

    foreach ($rows as $index => $row) {
        $line = trim($row);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            $errors[] = 'Line ' . ($index + 1) . ' is invalid. Use: Apartment name | iCal URL';
            continue;
        }

        $url = $parts[1];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Line ' . ($index + 1) . ' has invalid URL.';
            continue;
        }

        $parsed[] = [
            'name' => $parts[0],
            'ical_url' => $url,
        ];
    }

    return ['items' => $parsed, 'errors' => $errors];
}

function importBulkIcalListings(string $provider, string $buildingName, array $items, array &$buildings): int
{
    $imported = 0;
    $providerField = $provider === 'booking' ? 'booking_url' : 'airbnb_url';
    $targetBuilding = trim($buildingName) !== '' ? trim($buildingName) : ucfirst($provider) . ' Imported';
    $buildingIndex = null;

    foreach ($buildings as $idx => $building) {
        if (strcasecmp((string) ($building['name'] ?? ''), $targetBuilding) === 0) {
            $buildingIndex = $idx;
            break;
        }
    }

    if ($buildingIndex === null) {
        $buildings[] = [
            'id' => generateId('bld'),
            'name' => $targetBuilding,
            'apartments' => [],
        ];
        $buildingIndex = count($buildings) - 1;
    }

    foreach ($items as $item) {
        $aptIndex = null;
        foreach ($buildings[$buildingIndex]['apartments'] as $aIdx => $apartment) {
            if (strcasecmp((string) ($apartment['name'] ?? ''), (string) $item['name']) === 0) {
                $aptIndex = $aIdx;
                break;
            }
        }

        if ($aptIndex === null) {
            $buildings[$buildingIndex]['apartments'][] = [
                'id' => generateId('apt'),
                'name' => (string) $item['name'],
                'airbnb_url' => '',
                'booking_url' => '',
                'external_refs' => [],
            ];
            $aptIndex = count($buildings[$buildingIndex]['apartments']) - 1;
        }

        $buildings[$buildingIndex]['apartments'][$aptIndex][$providerField] = (string) $item['ical_url'];
        $imported++;
    }

    return $imported;
}

$messages = [];
$errors = [];

$settings = readJson(SETTINGS_FILE, [
    'sync_interval_minutes' => 30,
    'provider_connectors' => [
        'airbnb' => ['api_url' => '', 'token' => '', 'enabled' => false],
        'booking' => ['api_url' => '', 'token' => '', 'enabled' => false],
    ],
    'pricelabs' => ['api_key' => '', 'base_url' => 'https://api.pricelabs.co', 'enabled' => false],
]);

if (!isset($settings['provider_connectors']) || !is_array($settings['provider_connectors'])) {
    $settings['provider_connectors'] = [
        'airbnb' => ['api_url' => '', 'token' => '', 'enabled' => false],
        'booking' => ['api_url' => '', 'token' => '', 'enabled' => false],
    ];
}
if (!isset($settings['pricelabs']) || !is_array($settings['pricelabs'])) {
    $settings['pricelabs'] = ['api_key' => '', 'base_url' => 'https://api.pricelabs.co', 'enabled' => false];
}

$buildings = readJson(BUILDINGS_FILE, []);
$previewListings = ['airbnb' => [], 'booking' => []];

function normalizeListingName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    return trim($name);
}

function findBestPricelabsListingMatch(string $apartmentName, array $plListings): ?array
{
    $aptNorm = normalizeListingName($apartmentName);
    if ($aptNorm === '') {
        return null;
    }

    $best = null;
    $bestScore = 0;
    $aptTokens = array_values(array_filter(explode(' ', $aptNorm)));

    foreach ($plListings as $listing) {
        $name = (string) ($listing['name'] ?? '');
        $plNorm = normalizeListingName($name);
        if ($plNorm === '') {
            continue;
        }

        if ($plNorm === $aptNorm) {
            return $listing;
        }

        $score = 0;
        if (str_contains($plNorm, $aptNorm) || str_contains($aptNorm, $plNorm)) {
            $score += 100;
        }

        foreach ($aptTokens as $token) {
            if (strlen($token) < 2) {
                continue;
            }
            if (str_contains($plNorm, $token)) {
                $score += 10;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $listing;
        }
    }

    return $bestScore >= 30 ? $best : null;
}

function fetchPricelabsListings(array $cfg): array
{
    $apiKey = trim((string) ($cfg['api_key'] ?? ''));
    $baseUrl = rtrim(trim((string) ($cfg['base_url'] ?? 'https://api.pricelabs.co')), '/');
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'PriceLabs API key is empty.', 'listings' => []];
    }

    $endpoints = ['/v1/listings', '/v1/listing_data', '/v1/listings_data'];
    $headers = "Accept: application/json\r\nx-api-key: {$apiKey}\r\nAuthorization: Bearer {$apiKey}\r\nUser-Agent: CRLX-PriceLabs-Bridge/1.0";
    foreach ($endpoints as $ep) {
        $url = $baseUrl . $ep;
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'header' => $headers]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            continue;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            continue;
        }
        $items = $json['listings'] ?? $json['data'] ?? $json['results'] ?? $json;
        if (!is_array($items)) {
            continue;
        }
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? $item['listing_id'] ?? $item['uuid'] ?? '');
            $name = trim((string) ($item['name'] ?? $item['listing_name'] ?? $item['title'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $normalized[] = ['external_id' => $id, 'name' => $name];
        }
        return ['ok' => true, 'message' => 'Fetched ' . count($normalized) . ' listing(s).', 'listings' => $normalized];
    }
    return ['ok' => false, 'message' => 'Could not fetch listings from PriceLabs API.', 'listings' => []];
}

function matchPricelabsListings(array $plListings, array &$buildings): int
{
    $mapped = 0;
    foreach ($buildings as &$building) {
        foreach (($building['apartments'] ?? []) as &$apartment) {
            $match = findBestPricelabsListingMatch((string) ($apartment['name'] ?? ''), $plListings);
            if (!$match) {
                continue;
            }
            $refs = is_array($apartment['external_refs'] ?? null) ? $apartment['external_refs'] : [];
            $refs['pricelabs_listing_id'] = (string) ($match['external_id'] ?? '');
            $refs['pricelabs_name'] = (string) ($match['name'] ?? '');
            $apartment['external_refs'] = $refs;
            $mapped++;
        }
        unset($apartment);
    }
    unset($building);
    return $mapped;
}

function syncPricelabsReservations(array $buildings, array $cfg): array
{
    $apiKey = trim((string) ($cfg['api_key'] ?? ''));
    $baseUrl = rtrim(trim((string) ($cfg['base_url'] ?? 'https://api.pricelabs.co')), '/');
    if ($apiKey === '') {
        return ['ok' => false, 'imported' => 0, 'message' => 'PriceLabs API key is empty.'];
    }

    $pdo = getDbPdo();
    if (!$pdo) {
        return ['ok' => false, 'imported' => 0, 'message' => 'Database is not configured.'];
    }

    $rowsToInsert = [];
    $fromDate = (new DateTimeImmutable('first day of last month', new DateTimeZone('UTC')))->format('Y-m-d');
    $toDate = (new DateTimeImmutable('last day of next month', new DateTimeZone('UTC')))->format('Y-m-d');
    foreach ($buildings as $building) {
        foreach (($building['apartments'] ?? []) as $apartment) {
            $refs = is_array($apartment['external_refs'] ?? null) ? $apartment['external_refs'] : [];
            $listingId = trim((string) ($refs['pricelabs_listing_id'] ?? ''));
            if ($listingId === '') {
                continue;
            }
            $queryVariants = [
                'listing_id=' . rawurlencode($listingId) . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate),
                'listing_id=' . rawurlencode($listingId) . '&start_date=' . rawurlencode($fromDate) . '&end_date=' . rawurlencode($toDate),
                'listing_id=' . rawurlencode($listingId) . '&check_in_from=' . rawurlencode($fromDate) . '&check_out_to=' . rawurlencode($toDate),
                'listing_id=' . rawurlencode($listingId),
            ];
            $endpoints = [];
            foreach ($queryVariants as $q) {
                $endpoints[] = '/v1/reservations?' . $q;
                $endpoints[] = '/v1/bookings?' . $q;
            }
            $endpoints[] = '/v1/listings/' . rawurlencode($listingId) . '/reservations?from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate);
            $endpoints[] = '/v1/listings/' . rawurlencode($listingId) . '/reservations';

            foreach ($endpoints as $endpoint) {
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'header' => "Accept: application/json\r\nx-api-key: {$apiKey}\r\nAuthorization: Bearer {$apiKey}\r\nUser-Agent: CRLX-PriceLabs-Bridge/1.0"]]);
                $raw = @file_get_contents($baseUrl . $endpoint, false, $ctx);
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
                    $start = substr((string) ($row['check_in'] ?? $row['start_date'] ?? $row['arrival_date'] ?? ''), 0, 10);
                    $end = substr((string) ($row['check_out'] ?? $row['end_date'] ?? $row['departure_date'] ?? ''), 0, 10);
                    if ($start === '' || $end === '') {
                        continue;
                    }
                    $rowsToInsert[] = [
                        'id' => 'pl_' . md5($listingId . '|' . ($row['id'] ?? $row['reservation_id'] ?? $start . $end)),
                        'external_uid' => (string) ($row['id'] ?? $row['reservation_id'] ?? ''),
                        'apartment_id' => (string) ($apartment['id'] ?? ''),
                        'title' => (string) ($row['guest_name'] ?? $row['title'] ?? ((string) ($apartment['name'] ?? 'PriceLabs booking'))),
                        'status' => (string) ($row['status'] ?? 'booked'),
                        'start' => $start,
                        'end' => $end,
                        'price_total' => ((string) ($row['price_total'] ?? $row['amount'] ?? '') === '' ? null : (float) ($row['price_total'] ?? $row['amount'])),
                        'price_currency' => (string) ($row['currency'] ?? 'EUR'),
                    ];
                }
                break;
            }
        }
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM reservations WHERE booking_channel='pricelabs'");
        $ins = $pdo->prepare('INSERT INTO reservations (reservation_uuid, apartment_id, source, external_uid, title, status, start_date, end_date, readonly_flag, booking_channel, price_total, price_currency) VALUES (:uuid,:apartment,:source,:external_uid,:title,:status,:start,:end,1,:channel,:price_total,:currency)');
        foreach ($rowsToInsert as $r) {
            $ins->execute([
                ':uuid' => (string) $r['id'],
                ':apartment' => (string) $r['apartment_id'],
                ':source' => 'ical',
                ':external_uid' => (string) $r['external_uid'],
                ':title' => (string) $r['title'],
                ':status' => (string) $r['status'],
                ':start' => (string) $r['start'],
                ':end' => (string) $r['end'],
                ':channel' => 'pricelabs',
                ':price_total' => $r['price_total'],
                ':currency' => (string) $r['price_currency'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'imported' => 0, 'message' => 'PriceLabs reservations sync failed in DB write.'];
    }

    return ['ok' => true, 'imported' => count($rowsToInsert), 'message' => 'PriceLabs reservations synced.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = $_POST['intent'] ?? '';

    if ($intent === 'save_connectors') {
        foreach (['airbnb', 'booking'] as $provider) {
            $settings['provider_connectors'][$provider] = [
                'api_url' => trim((string) ($_POST[$provider . '_api_url'] ?? '')),
                'token' => trim((string) ($_POST[$provider . '_token'] ?? '')),
                'enabled' => isset($_POST[$provider . '_enabled']),
            ];
        }
        $settings['pricelabs'] = [
            'api_key' => trim((string) ($_POST['pricelabs_api_key'] ?? '')),
            'base_url' => trim((string) ($_POST['pricelabs_base_url'] ?? 'https://api.pricelabs.co')),
            'enabled' => isset($_POST['pricelabs_enabled']),
        ];

        if (writeJson(SETTINGS_FILE, $settings)) {
            $messages[] = 'Provider connector settings saved.';
        } else {
            $errors[] = 'Could not save provider connector settings.';
        }
    }
    if ($intent === 'connect_pricelabs_bridge') {
        $res = fetchPricelabsListings($settings['pricelabs'] ?? []);
        if (!$res['ok']) {
            $errors[] = 'PriceLabs: ' . $res['message'];
        } else {
            $mapped = matchPricelabsListings($res['listings'], $buildings);
            if (writeJson(BUILDINGS_FILE, $buildings)) {
                $sync = syncPricelabsReservations($buildings, $settings['pricelabs'] ?? []);
                $messages[] = 'PriceLabs Bridge connected. Matched ' . $mapped . ' listing(s) to existing apartments.';
                $messages[] = $sync['message'] . ' Imported ' . (int) ($sync['imported'] ?? 0) . ' reservation(s).';
            } else {
                $errors[] = 'PriceLabs listings matched but could not be saved.';
            }
        }
    }

    if ($intent === 'enumerate_provider') {
        $provider = (string) ($_POST['provider'] ?? '');
        if (!in_array($provider, ['airbnb', 'booking'], true)) {
            $errors[] = 'Invalid provider.';
        } else {
            $res = fetchProviderListings($provider, $settings['provider_connectors'][$provider] ?? []);
            if (!$res['ok']) {
                $errors[] = ucfirst($provider) . ': ' . $res['message'];
            } else {
                $previewListings[$provider] = $res['listings'];
                $messages[] = ucfirst($provider) . ': ' . $res['message'];

                $imported = importListingsIntoBuildings($provider, $res['listings'], $buildings);
                if (writeJson(BUILDINGS_FILE, $buildings)) {
                    $messages[] = ucfirst($provider) . ': Imported/updated ' . $imported . ' listing(s) into buildings/apartments.';
                } else {
                    $errors[] = 'Could not write imported listings to buildings file.';
                }
            }
        }
    }

    if ($intent === 'import_bulk_ical') {
        $provider = (string) ($_POST['provider'] ?? '');
        $buildingName = trim((string) ($_POST['building_name'] ?? ''));
        $bulkLines = (string) ($_POST['bulk_lines'] ?? '');

        if (!in_array($provider, ['airbnb', 'booking'], true)) {
            $errors[] = 'Invalid provider selected for bulk import.';
        } else {
            $parsed = parseBulkIcalLines($bulkLines);
            foreach ($parsed['errors'] as $parseError) {
                $errors[] = $parseError;
            }

            if (!empty($parsed['items'])) {
                $count = importBulkIcalListings($provider, $buildingName, $parsed['items'], $buildings);
                if (writeJson(BUILDINGS_FILE, $buildings)) {
                    $messages[] = ucfirst($provider) . ': Imported/updated ' . $count . ' apartment iCal mapping(s) without API.';
                } else {
                    $errors[] = 'Could not save bulk iCal import to buildings file.';
                }
            } elseif (empty($parsed['errors'])) {
                $errors[] = 'No valid lines found to import.';
            }
        }
    }
}

$connectors = $settings['provider_connectors'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Settings - Provider Connections</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('Provider Settings'); ?>
<main class="container page-with-header">

    <section class="panel">
        <h2>Provider connection settings</h2>
        <div class="flash notice">
            <strong>How this works:</strong> Use Airbnb/Booking host APIs (or your channel manager connector endpoint) that return JSON listings.
            The response can be either an array or <code>{"listings": [...]}</code>. Each listing should include <code>id</code>, <code>name</code>, and optional <code>ical_url</code>.
        </div>
        <div class="flash notice">
            <strong>No API key/token?</strong> That is normal for many hosts. You can skip API connectors and use the
            <strong>Bulk iCal import (no API)</strong> section below by pasting each listing as
            <code>Apartment name | iCal URL</code>.
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages as $message): ?>
            <div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <form method="post" class="admin-grid">
            <input type="hidden" name="intent" value="save_connectors">

            <article class="admin-card">
                <h3>Airbnb connector</h3>
                <label><input type="checkbox" name="airbnb_enabled" <?= !empty($connectors['airbnb']['enabled']) ? 'checked' : '' ?>> Enabled</label>
                <input type="url" name="airbnb_api_url" value="<?= htmlspecialchars((string) ($connectors['airbnb']['api_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://.../airbnb/listings">
                <input type="text" name="airbnb_token" value="<?= htmlspecialchars((string) ($connectors['airbnb']['token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Bearer token / API key">
            </article>
            <article class="admin-card">
                <h3>PriceLabs Bridge</h3>
                <label><input type="checkbox" name="pricelabs_enabled" <?= !empty($settings['pricelabs']['enabled']) ? 'checked' : '' ?>> Enabled</label>
                <input type="url" name="pricelabs_base_url" value="<?= htmlspecialchars((string) ($settings['pricelabs']['base_url'] ?? 'https://api.pricelabs.co'), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://api.pricelabs.co">
                <input type="text" name="pricelabs_api_key" value="<?= htmlspecialchars((string) ($settings['pricelabs']['api_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="PriceLabs API key">
            </article>

            <article class="admin-card">
                <h3>Booking.com connector</h3>
                <label><input type="checkbox" name="booking_enabled" <?= !empty($connectors['booking']['enabled']) ? 'checked' : '' ?>> Enabled</label>
                <input type="url" name="booking_api_url" value="<?= htmlspecialchars((string) ($connectors['booking']['api_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://.../booking/listings">
                <input type="text" name="booking_token" value="<?= htmlspecialchars((string) ($connectors['booking']['token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Bearer token / API key">
            </article>

            <div class="admin-card" style="grid-column:1/-1;">
                <button class="btn" type="submit">Save connector settings</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>PriceLabs Bridge</h2>
        <button class="btn accent" type="button" onclick="document.getElementById('pricelabsBridgeModal').showModal()">Connect to the Bridge</button>
        <dialog id="pricelabsBridgeModal">
            <form method="dialog" style="margin-bottom:1rem;">
                <button class="btn" type="submit">Close</button>
            </form>
            <p>Login to PriceLabs in a new tab first, then run bridge matching here.</p>
            <p><a class="btn" href="https://app.pricelabs.co" target="_blank" rel="noopener">Open PriceLabs Login</a></p>
            <form method="post">
                <input type="hidden" name="intent" value="connect_pricelabs_bridge">
                <button class="btn accent" type="submit">Enumerate & match PriceLabs listings</button>
            </form>
        </dialog>
    </section>

    <section class="panel">
        <h2>Enumerate and import listings</h2>
        <div class="admin-grid">
            <form method="post" class="admin-card">
                <input type="hidden" name="intent" value="enumerate_provider">
                <input type="hidden" name="provider" value="airbnb">
                <h3>Airbnb</h3>
                <p>Fetch listings from the configured Airbnb connector and import them.</p>
                <button class="btn accent" type="submit">Enumerate + import Airbnb listings</button>
            </form>

            <form method="post" class="admin-card">
                <input type="hidden" name="intent" value="enumerate_provider">
                <input type="hidden" name="provider" value="booking">
                <h3>Booking.com</h3>
                <p>Fetch listings from the configured Booking.com connector and import them.</p>
                <button class="btn accent" type="submit">Enumerate + import Booking.com listings</button>
            </form>
        </div>

        <?php foreach (['airbnb', 'booking'] as $provider): ?>
            <?php if (!empty($previewListings[$provider])): ?>
                <h3><?= htmlspecialchars(ucfirst($provider), ENT_QUOTES, 'UTF-8') ?> preview</h3>
                <div class="feed-building">
                    <?php foreach ($previewListings[$provider] as $l): ?>
                        <div class="feed-row">
                            <strong><?= htmlspecialchars((string) ($l['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span>ID: <?= htmlspecialchars((string) ($l['external_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <span>Building: <?= htmlspecialchars((string) ($l['building_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <span>iCal: <?= htmlspecialchars((string) ($l['ical_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Bulk iCal import (no API)</h2>
        <p class="muted">Use this when Airbnb/Booking.com API credentials are not available. One line per apartment: <code>Apartment name | iCal URL</code>.</p>
        <form method="post" class="admin-card">
            <input type="hidden" name="intent" value="import_bulk_ical">
            <label for="bulk_provider">Source</label>
            <select id="bulk_provider" name="provider" required>
                <option value="airbnb">Airbnb</option>
                <option value="booking">Booking.com</option>
            </select>
            <label for="bulk_building">Building name (optional)</label>
            <input id="bulk_building" type="text" name="building_name" placeholder="e.g. Building A">
            <label for="bulk_lines">Listings</label>
            <textarea id="bulk_lines" name="bulk_lines" rows="8" placeholder="Apartment 101 | https://www.airbnb.com/calendar/ical/...\nApartment 102 | https://admin.booking.com/hotel/hoteladmin/ical.html?..."></textarea>
            <button class="btn accent" type="submit">Import iCal mappings</button>
        </form>
    </section>
</main>
</body>
</html>

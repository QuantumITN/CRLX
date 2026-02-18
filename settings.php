<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
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

$messages = [];
$errors = [];

$settings = readJson(SETTINGS_FILE, [
    'sync_interval_minutes' => 30,
    'provider_connectors' => [
        'airbnb' => ['api_url' => '', 'token' => '', 'enabled' => false],
        'booking' => ['api_url' => '', 'token' => '', 'enabled' => false],
    ],
]);

if (!isset($settings['provider_connectors']) || !is_array($settings['provider_connectors'])) {
    $settings['provider_connectors'] = [
        'airbnb' => ['api_url' => '', 'token' => '', 'enabled' => false],
        'booking' => ['api_url' => '', 'token' => '', 'enabled' => false],
    ];
}

$buildings = readJson(BUILDINGS_FILE, []);
$previewListings = ['airbnb' => [], 'booking' => []];

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

        if (writeJson(SETTINGS_FILE, $settings)) {
            $messages[] = 'Provider connector settings saved.';
        } else {
            $errors[] = 'Could not save provider connector settings.';
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
<main class="container">
    <header class="hero panel">
        <h1>⚙️ Admin Settings</h1>
        <p>Connect provider listing APIs, enumerate your listings, and import them into buildings/apartments.</p>
        <p><a class="btn ghost" href="index.php">← Back to Availability Board</a> <a class="btn ghost" href="logout.php">Logout</a></p>
    </header>

    <section class="panel">
        <h2>Provider connection settings</h2>
        <div class="flash notice">
            <strong>How this works:</strong> Use Airbnb/Booking host APIs (or your channel manager connector endpoint) that return JSON listings.
            The response can be either an array or <code>{"listings": [...]}</code>. Each listing should include <code>id</code>, <code>name</code>, and optional <code>ical_url</code>.
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
</main>
</body>
</html>

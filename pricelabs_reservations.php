<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui.php';

requireAuth();

function readJsonLocal(string $file, array $default): array
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

$settings = readJsonLocal(__DIR__ . '/data/settings.json', []);
$pl = is_array($settings['pricelabs'] ?? null) ? $settings['pricelabs'] : [];
$apiKey = trim((string) ($pl['api_key'] ?? ''));
$baseUrl = rtrim(trim((string) ($pl['base_url'] ?? 'https://api.pricelabs.co')), '/');

$fromDate = (new DateTimeImmutable('first day of last month', new DateTimeZone('UTC')))->format('Y-m-d');
$toDate = (new DateTimeImmutable('last day of next month', new DateTimeZone('UTC')))->format('Y-m-d');
$rows = [];
$error = '';
$pagesFetched = 0;

if ($apiKey === '') {
    $error = 'PriceLabs API key is not configured in Settings.';
} else {
    for ($offset = 0; $offset <= 2000; $offset += 100) {
        $url = $baseUrl . '/v1/reservation_data?pms=igms&start_date=' . rawurlencode($fromDate) . '&end_date=' . rawurlencode($toDate) . '&limit=100&offset=' . $offset;
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'header' => "Accept: application/json\r\nx-api-key: {$apiKey}\r\nAuthorization: Bearer {$apiKey}\r\nUser-Agent: CRLX-PriceLabs-Viewer/1.0"]]);
        $raw = @file_get_contents($url, false, $ctx);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            $error = 'Could not decode PriceLabs reservation_data response.';
            break;
        }
        $pageRows = $json['data'] ?? $json['reservations'] ?? $json['results'] ?? $json;
        if (!is_array($pageRows) || $pageRows === []) {
            break;
        }
        $pagesFetched++;
        foreach ($pageRows as $r) {
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
        $nextPage = $json['next_page'] ?? $json['has_more'] ?? false;
        if (!$nextPage) {
            break;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PriceLabs Reservations</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('PriceLabs Reservations'); ?>
<main class="container page-with-header">
    <section class="panel">
        <h2>PriceLabs reservation_data feed</h2>
        <p class="tiny">Range: <?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?> (pms=igms)</p>
        <p class="tiny">Pages fetched: <?= (int) $pagesFetched ?> | Rows: <?= count($rows) ?></p>
        <?php if ($error !== ''): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (empty($rows)): ?>
            <div class="flash notice">No reservations returned by PriceLabs for this range.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="reservation-table">
                    <thead>
                    <tr>
                        <th>Listing</th>
                        <th>Reservation ID</th>
                        <th>Status</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Guest</th>
                        <th>Revenue</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($r['listing_name'] ?? $r['property_name'] ?? $r['room_name'] ?? $r['unit_name'] ?? $r['listing_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['id'] ?? $r['reservation_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['booking_status'] ?? $r['status'] ?? $r['reservation_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['check_in'] ?? $r['start_date'] ?? $r['arrival_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['check_out'] ?? $r['end_date'] ?? $r['departure_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['guest_name'] ?? $r['guest'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($r['rental_revenue'] ?? $r['price_total'] ?? $r['amount'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>


<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';

requireAuth();

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/data';
const BUILDINGS_FILE = DATA_DIR . '/buildings.json';

function readJson(string $file, array $default): array
{
    if (!file_exists($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $json = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($json) ? $json : $default;
}

function flattenApartments(array $buildings): array
{
    $rows = [];
    foreach ($buildings as $building) {
        foreach (($building['apartments'] ?? []) as $apartment) {
            $rows[] = [
                'id' => (string) ($apartment['id'] ?? ''),
                'name' => (string) ($apartment['name'] ?? 'Apartment'),
                'building_id' => (string) ($building['id'] ?? ''),
                'building_name' => (string) ($building['name'] ?? 'Building'),
            ];
        }
    }

    return $rows;
}

function fetchAccountingRows(array $filters): array
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT reservation_uuid, apartment_id, source, booking_channel, title, start_date, end_date, adults, children, price_total, price_currency, cleaning_fee FROM reservations WHERE archived_flag = 0';
    $params = [];

    if (($filters['from'] ?? '') !== '') {
        $sql .= ' AND start_date >= :from';
        $params[':from'] = (string) $filters['from'];
    }
    if (($filters['to'] ?? '') !== '') {
        $sql .= ' AND end_date <= :to';
        $params[':to'] = (string) $filters['to'];
    }
    if (($filters['apartment_id'] ?? '') !== '') {
        $sql .= ' AND apartment_id = :apartment_id';
        $params[':apartment_id'] = (string) $filters['apartment_id'];
    }

    $sql .= ' ORDER BY start_date ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

$buildings = readJson(BUILDINGS_FILE, []);
$apartments = flattenApartments($buildings);
$aptMap = [];
foreach ($apartments as $a) {
    $aptMap[(string) $a['id']] = $a;
}

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-t')));
$buildingId = trim((string) ($_GET['building_id'] ?? ''));
$apartmentId = trim((string) ($_GET['apartment_id'] ?? ''));
$bucket = trim((string) ($_GET['bucket'] ?? 'auto'));
$cleaningDefault = (float) ($_GET['cleaning_default'] ?? 0);
$extraPersonPerNight = (float) ($_GET['extra_person_fee'] ?? 0);
$extraBedPerNight = (float) ($_GET['extra_bed_fee'] ?? 0);

$filters = ['from' => $from, 'to' => $to, 'apartment_id' => $apartmentId];
$rows = fetchAccountingRows($filters);
if ($buildingId !== '' && $apartmentId === '') {
    $allowed = array_values(array_map(static fn(array $a): string => (string) $a['id'], array_filter($apartments, static fn(array $a): bool => (string) $a['building_id'] === $buildingId)));
    $rows = array_values(array_filter($rows, static fn(array $r): bool => in_array((string) ($r['apartment_id'] ?? ''), $allowed, true)));
}

$fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $from, new DateTimeZone('UTC')) ?: new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
$toDt = DateTimeImmutable::createFromFormat('Y-m-d', $to, new DateTimeZone('UTC')) ?: new DateTimeImmutable('last day of this month', new DateTimeZone('UTC'));
$daysSpan = (int) $fromDt->diff($toDt)->format('%a') + 1;
if ($bucket === 'auto') {
    $bucket = $daysSpan <= 45 ? 'day' : ($daysSpan <= 180 ? 'week' : 'month');
}

$totalBase = 0.0;
$totalCleaning = 0.0;
$totalExtraPerson = 0.0;
$totalExtraBed = 0.0;
$series = [];
$sourceTotals = [];

foreach ($rows as &$r) {
    $start = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($r['start_date'] ?? ''), new DateTimeZone('UTC'));
    $end = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($r['end_date'] ?? ''), new DateTimeZone('UTC'));
    $nights = 1;
    if ($start && $end) {
        $nights = max(1, (int) $start->diff($end)->format('%a'));
    }

    $adults = max(0, (int) ($r['adults'] ?? 0));
    $children = max(0, (int) ($r['children'] ?? 0));

    $base = (float) ($r['price_total'] ?? 0);
    $cleaning = ((string) ($r['cleaning_fee'] ?? '') === '' ? $cleaningDefault : (float) $r['cleaning_fee']);
    $extraPerson = max(0, $adults - 2) * $extraPersonPerNight * $nights;
    $extraBed = $children * $extraBedPerNight * $nights;
    $total = $base + $cleaning + $extraPerson + $extraBed;

    $source = strtolower((string) ($r['booking_channel'] ?: $r['source'] ?: 'manual'));
    $bucketLabel = '';
    if ($start) {
        if ($bucket === 'day') {
            $bucketLabel = $start->format('Y-m-d');
        } elseif ($bucket === 'week') {
            $bucketLabel = $start->format('o-\\WW');
        } else {
            $bucketLabel = $start->format('Y-m');
        }
    }

    $series[$bucketLabel] = ($series[$bucketLabel] ?? 0) + $total;
    $sourceTotals[$source] = ($sourceTotals[$source] ?? 0) + $total;

    $totalBase += $base;
    $totalCleaning += $cleaning;
    $totalExtraPerson += $extraPerson;
    $totalExtraBed += $extraBed;

    $r['nights'] = $nights;
    $r['computed_total'] = $total;
    $r['source_tag'] = $source;
}
unset($r);

ksort($series);
arsort($sourceTotals);
$grand = $totalBase + $totalCleaning + $totalExtraPerson + $totalExtraBed;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accounting - CLR Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('Accounting'); ?>
<main class="container page-with-header">
    <section class="panel">
        <h2>Accounting</h2>
        <form method="get" class="feed-row reservation-form-grid">
            <label>From <input type="date" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>To <input type="date" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>"></label>
            <select name="building_id">
                <option value="">Overall (all buildings)</option>
                <?php foreach ($buildings as $b): ?>
                    <option value="<?= htmlspecialchars((string) ($b['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= ((string) ($b['id'] ?? '') === $buildingId) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? 'Building'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select name="apartment_id">
                <option value="">All apartments</option>
                <?php foreach ($apartments as $a): ?>
                    <?php if ($buildingId !== '' && (string) $a['building_id'] !== $buildingId) { continue; } ?>
                    <option value="<?= htmlspecialchars((string) $a['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ((string) $a['id'] === $apartmentId) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($a['building_name'] . ' - ' . $a['name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select name="bucket">
                <?php foreach (['auto', 'day', 'week', 'month'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $bucket === $opt ? 'selected' : '' ?>>Bucket: <?= strtoupper($opt) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" step="0.01" name="cleaning_default" value="<?= htmlspecialchars((string) $cleaningDefault, ENT_QUOTES, 'UTF-8') ?>" placeholder="Default cleaning per stay">
            <input type="number" step="0.01" name="extra_person_fee" value="<?= htmlspecialchars((string) $extraPersonPerNight, ENT_QUOTES, 'UTF-8') ?>" placeholder="Extra person fee/night">
            <input type="number" step="0.01" name="extra_bed_fee" value="<?= htmlspecialchars((string) $extraBedPerNight, ENT_QUOTES, 'UTF-8') ?>" placeholder="Extra bed fee/night">
            <button class="btn" type="submit">Generate accounting report</button>
        </form>
    </section>

    <section class="panel report-rank-grid">
        <div class="report-card"><h3>Base reservation sales</h3><p><strong><?= number_format($totalBase, 2) ?></strong></p></div>
        <div class="report-card"><h3>Cleaning fees</h3><p><strong><?= number_format($totalCleaning, 2) ?></strong></p></div>
        <div class="report-card"><h3>Extra person fees</h3><p><strong><?= number_format($totalExtraPerson, 2) ?></strong></p></div>
        <div class="report-card"><h3>Extra bed fees</h3><p><strong><?= number_format($totalExtraBed, 2) ?></strong></p></div>
        <div class="report-card"><h3>Total sales</h3><p><strong><?= number_format($grand, 2) ?></strong></p></div>
    </section>

    <section class="panel report-rank-grid">
        <div class="report-card">
            <h3>Sales trend (<?= htmlspecialchars(strtoupper($bucket), ENT_QUOTES, 'UTF-8') ?>)</h3>
            <div class="report-rows">
                <?php foreach ($series as $label => $amount): ?>
                    <div class="report-row-item">
                        <div class="report-row-head"><span><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format((float) $amount, 2) ?></strong></div>
                        <div class="report-row-bar"><div style="width: <?= $grand > 0 ? max(2, ($amount / $grand) * 100) : 2 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="report-card">
            <h3>Sales by source/channel</h3>
            <div class="report-rows">
                <?php foreach ($sourceTotals as $source => $amount): ?>
                    <div class="report-row-item">
                        <div class="report-row-head"><span><span class="source-tag source-<?= htmlspecialchars((string) $source, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper((string) $source), ENT_QUOTES, 'UTF-8') ?></span></span><strong><?= number_format((float) $amount, 2) ?></strong></div>
                        <div class="report-row-bar"><div style="width: <?= $grand > 0 ? max(2, ($amount / $grand) * 100) : 2 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <h3>Reservation accounting lines</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>Reservation</th><th>Source</th><th>Apartment</th><th>Dates</th><th>Nights</th><th>Base</th><th>Cleaning</th><th>Extra person</th><th>Extra bed</th><th>Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($r['title'] ?? $r['reservation_uuid']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="source-tag source-<?= htmlspecialchars((string) ($r['source_tag'] ?? 'manual'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper((string) ($r['source_tag'] ?? 'manual')), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($aptMap[(string) ($r['apartment_id'] ?? '')]['name'] ?? (string) ($r['apartment_id'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) (($r['start_date'] ?? '') . ' → ' . ($r['end_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) ($r['nights'] ?? 1) ?></td>
                        <td><?= number_format((float) ($r['price_total'] ?? 0), 2) ?></td>
                        <td><?= number_format(((string) ($r['cleaning_fee'] ?? '') === '' ? $cleaningDefault : (float) $r['cleaning_fee']), 2) ?></td>
                        <td><?= number_format(max(0, ((int) ($r['adults'] ?? 0) - 2)) * $extraPersonPerNight * (int) ($r['nights'] ?? 1), 2) ?></td>
                        <td><?= number_format(max(0, (int) ($r['children'] ?? 0)) * $extraBedPerNight * (int) ($r['nights'] ?? 1), 2) ?></td>
                        <td><strong><?= number_format((float) ($r['computed_total'] ?? 0), 2) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>

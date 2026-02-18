<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireAuth();

date_default_timezone_set('UTC');

const DATA_DIR = __DIR__ . '/data';
const BUILDINGS_FILE = DATA_DIR . '/buildings.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const SYNC_META_FILE = DATA_DIR . '/sync_meta.json';
const RESERVATIONS_FILE = DATA_DIR . '/reservations.json';

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

function dbInsertManualReservation(array $r): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare('INSERT INTO reservations (reservation_uuid, apartment_id, source, title, status, start_date, end_date, customer_first_name, customer_last_name, customer_email, customer_phone, customer_country, customer_document, adults, children, notes, price_total, price_currency, tax_amount, cleaning_fee, discount_amount, payment_status, payment_method, booking_channel, readonly_flag) VALUES (:uuid,:apartment_id,"manual",:title,:status,:start_date,:end_date,:first,:last,:email,:phone,:country,:document,:adults,:children,:notes,:price_total,:currency,:tax,:cleaning,:discount,:payment_status,:payment_method,:channel,0)');

    $ok = $stmt->execute([
        ':uuid' => (string) ($r['id'] ?? generateId('m')),
        ':apartment_id' => (string) $r['apartment_id'],
        ':title' => (string) $r['title'],
        ':status' => (string) $r['status'],
        ':start_date' => (string) $r['start_date'],
        ':end_date' => (string) $r['end_date'],
        ':first' => (string) ($r['customer_first_name'] ?? ''),
        ':last' => (string) ($r['customer_last_name'] ?? ''),
        ':email' => (string) ($r['customer_email'] ?? ''),
        ':phone' => (string) ($r['customer_phone'] ?? ''),
        ':country' => (string) ($r['customer_country'] ?? ''),
        ':document' => (string) ($r['customer_document'] ?? ''),
        ':adults' => (int) ($r['adults'] ?? 1),
        ':children' => (int) ($r['children'] ?? 0),
        ':notes' => (string) ($r['notes'] ?? ''),
        ':price_total' => ($r['price_total'] === '' ? null : (float) $r['price_total']),
        ':currency' => (string) ($r['price_currency'] ?? 'EUR'),
        ':tax' => ($r['tax_amount'] === '' ? null : (float) $r['tax_amount']),
        ':cleaning' => ($r['cleaning_fee'] === '' ? null : (float) $r['cleaning_fee']),
        ':discount' => ($r['discount_amount'] === '' ? null : (float) $r['discount_amount']),
        ':payment_status' => (string) ($r['payment_status'] ?? ''),
        ':payment_method' => (string) ($r['payment_method'] ?? ''),
        ':channel' => (string) ($r['booking_channel'] ?? 'direct'),
    ]);

    if ($ok) {
        $ev = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, "manual_created", :payload)');
        $ev->execute([':uuid' => (string) ($r['id'] ?? ''), ':payload' => json_encode($r)]);
    }

    return $ok;
}

function dbRecentReservations(int $limit = 120): array
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT reservation_uuid, apartment_id, title, status, start_date, end_date, customer_first_name, customer_last_name, customer_email, customer_phone, price_total, price_currency, payment_status, booking_channel, created_at FROM reservations WHERE archived_flag = 0 ORDER BY start_date DESC LIMIT :lim');
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

$messages = [];
$errors = [];

$buildings = readJson(BUILDINGS_FILE, [
    ['id' => 'bld_main', 'name' => 'Main Building', 'apartments' => [['id' => 'apt_101', 'name' => 'Apartment 101', 'airbnb_url' => '', 'booking_url' => '']]],
]);
$settings = readJson(SETTINGS_FILE, ['sync_interval_minutes' => 30]);
$syncMeta = readJson(SYNC_META_FILE, ['last_sync' => null, 'status' => []]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = $_POST['intent'] ?? '';

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


    if ($intent === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($new === '' || $confirm === '') {
            $errors[] = 'New password and confirmation are required.';
        } elseif (!hash_equals($new, $confirm)) {
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

    if ($intent === 'create_manual_reservation') {
        $reservation = [
            'id' => generateId('m'),
            'apartment_id' => trim((string) ($_POST['apartment_id'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? 'Direct Booking')),
            'status' => trim((string) ($_POST['status'] ?? 'reserved')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'customer_first_name' => trim((string) ($_POST['customer_first_name'] ?? '')),
            'customer_last_name' => trim((string) ($_POST['customer_last_name'] ?? '')),
            'customer_email' => trim((string) ($_POST['customer_email'] ?? '')),
            'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
            'customer_country' => trim((string) ($_POST['customer_country'] ?? '')),
            'customer_document' => trim((string) ($_POST['customer_document'] ?? '')),
            'adults' => (int) ($_POST['adults'] ?? 1),
            'children' => (int) ($_POST['children'] ?? 0),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'price_total' => trim((string) ($_POST['price_total'] ?? '')),
            'price_currency' => trim((string) ($_POST['price_currency'] ?? 'EUR')),
            'tax_amount' => trim((string) ($_POST['tax_amount'] ?? '')),
            'cleaning_fee' => trim((string) ($_POST['cleaning_fee'] ?? '')),
            'discount_amount' => trim((string) ($_POST['discount_amount'] ?? '')),
            'payment_status' => trim((string) ($_POST['payment_status'] ?? 'pending')),
            'payment_method' => trim((string) ($_POST['payment_method'] ?? '')),
            'booking_channel' => trim((string) ($_POST['booking_channel'] ?? 'direct')),
        ];

        if ($reservation['apartment_id'] === '' || $reservation['start_date'] === '' || $reservation['end_date'] === '') {
            $errors[] = 'Apartment, check-in, and check-out are required.';
        } elseif (!dbIsReady()) {
            $errors[] = 'MySQL is not configured. Manual full booking storage requires MySQL.';
        } elseif (dbInsertManualReservation($reservation)) {
            $messages[] = 'Manual reservation saved with customer/pricing details.';
        } else {
            $errors[] = 'Could not save reservation in MySQL.';
        }
    }
}

$apartments = flattenApartments($buildings);
$recentReservations = dbRecentReservations();
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
<main class="container">
    <header class="hero panel">
        <h1>Admin Tools</h1>
        <p>Buildings, sync tools, and complete manual booking entry with guest and price details.</p>
        <p class="tiny">Logged in as: <?= htmlspecialchars(getAdminUsername(), ENT_QUOTES, "UTF-8") ?></p>
        <p>
            <a class="btn ghost" href="index.php">← Back to CLR Calendar</a>
            <a class="btn ghost" href="settings.php" target="_blank" rel="noopener">Provider Settings</a>
         <a class="btn ghost" href="logout.php">Logout</a></p>
    </header>

    <section class="panel">
        <?php if (!dbIsReady()): ?>
            <div class="flash notice">MySQL is not configured. Create tables from <code>database.sql</code> and setup <code>data/db_config.php</code> for full booking + history storage.</div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php foreach ($messages as $message): ?><div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

        <h2>Core setup</h2>
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
                <h3>Auto-sync interval</h3>
                <input type="hidden" name="intent" value="save_settings">
                <label>Pull iCal every <input type="number" min="1" max="1440" name="sync_interval_minutes" value="<?= (int) $settings['sync_interval_minutes'] ?>"> minute(s)</label>
                <button class="btn" type="submit">Save interval</button>
                <p class="tiny">Last sync: <?= htmlspecialchars((string) ($syncMeta['last_sync'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></p>
            </form>

            <article class="admin-card">
                <h3>Server health check</h3>
                <p>Verify PHP, MySQL connection, required tables, and writable paths.</p>
                <a class="btn" href="healthcheck.php" target="_blank" rel="noopener">Open Health Check</a>
            </article>

            <form method="post" class="admin-card">
                <h3>Change admin password</h3>
                <input type="hidden" name="intent" value="change_password">
                <input type="password" name="current_password" placeholder="Current password" required>
                <input type="password" name="new_password" placeholder="New password (min 10 chars)" required>
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                <button class="btn" type="submit">Update password</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <h2>Manual reservation / booking (full details)</h2>
        <form method="post" class="feed-row">
            <input type="hidden" name="intent" value="create_manual_reservation">
            <select name="apartment_id" required>
                <option value="">Select apartment</option>
                <?php foreach ($apartments as $apartment): ?>
                    <option value="<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($apartment['building_name'] . ' - ' . $apartment['name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="title" placeholder="Reservation title / reference">
            <select name="status">
                <option value="reserved">Reserved</option>
                <option value="booked">Booked</option>
                <option value="checked_out">Checked out</option>
                <option value="checkout_tomorrow">Checkout tomorrow</option>
            </select>
            <label>Check-in <input type="date" name="start_date" required></label>
            <label>Check-out <input type="date" name="end_date" required></label>

            <input type="text" name="customer_first_name" placeholder="Customer first name">
            <input type="text" name="customer_last_name" placeholder="Customer last name">
            <input type="email" name="customer_email" placeholder="Customer email">
            <input type="text" name="customer_phone" placeholder="Customer phone">
            <input type="text" name="customer_country" placeholder="Country">
            <input type="text" name="customer_document" placeholder="Passport/ID">

            <label>Adults <input type="number" min="1" name="adults" value="1"></label>
            <label>Children <input type="number" min="0" name="children" value="0"></label>
            <input type="text" name="booking_channel" placeholder="Channel (direct/airbnb/booking)">
            <input type="text" name="payment_method" placeholder="Payment method">
            <input type="text" name="payment_status" placeholder="Payment status" value="pending">

            <input type="number" step="0.01" name="price_total" placeholder="Total price">
            <input type="text" name="price_currency" value="EUR" placeholder="Currency">
            <input type="number" step="0.01" name="tax_amount" placeholder="Tax amount">
            <input type="number" step="0.01" name="cleaning_fee" placeholder="Cleaning fee">
            <input type="number" step="0.01" name="discount_amount" placeholder="Discount">
            <textarea name="notes" placeholder="Notes"></textarea>
            <button class="btn accent" type="submit">Save manual reservation</button>
        </form>
    </section>

    <section class="panel">
        <h2>Past & recent reservations</h2>
        <?php if (empty($recentReservations)): ?>
            <p class="tiny">No reservations found (or MySQL not configured).</p>
        <?php else: ?>
            <div class="feed-building">
                <?php foreach ($recentReservations as $r): ?>
                    <div class="feed-row">
                        <strong><?= htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span>Apartment ID: <?= htmlspecialchars((string) $r['apartment_id'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Dates: <?= htmlspecialchars((string) $r['start_date'], ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) $r['end_date'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Guest: <?= htmlspecialchars(trim((string) (($r['customer_first_name'] ?? '') . ' ' . ($r['customer_last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Email: <?= htmlspecialchars((string) ($r['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Phone: <?= htmlspecialchars((string) ($r['customer_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Price: <?= htmlspecialchars((string) (($r['price_total'] ?? '') . ' ' . ($r['price_currency'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Payment: <?= htmlspecialchars((string) ($r['payment_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Channel: <?= htmlspecialchars((string) ($r['booking_channel'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
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
            <button class="btn" type="submit">Save all feed URLs</button>
        </form>
    </section>
</main>
</body>
</html>

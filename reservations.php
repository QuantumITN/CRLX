<?php

declare(strict_types=1);

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
    if ($raw === false) {
        return $default;
    }
    $json = json_decode($raw, true);

    return is_array($json) ? $json : $default;
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

function dbRecentReservations(int $limit = 150): array
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
$buildings = readJson(BUILDINGS_FILE, []);
$apartments = flattenApartments($buildings);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['intent'] ?? '') === 'create_manual_reservation') {
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
        $errors[] = 'MySQL is not configured. Configure DB first.';
    } elseif (dbInsertManualReservation($reservation)) {
        $messages[] = 'Manual reservation saved successfully.';
    } else {
        $errors[] = 'Could not save reservation in MySQL.';
    }
}

$recentReservations = dbRecentReservations();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservations - CLR Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('Reservations'); ?>
<main class="container page-with-header">
    <section class="panel">
        <h2>Make Reservation</h2>
        <?php foreach ($errors as $error): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php foreach ($messages as $message): ?><div class="flash success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

        <form method="post" class="feed-row reservation-form-grid">
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
            <button class="btn accent" type="submit">Save reservation</button>
        </form>
    </section>

    <section class="panel">
        <h2>Recent reservations</h2>
        <?php if (empty($recentReservations)): ?>
            <p class="tiny">No reservations found.</p>
        <?php else: ?>
            <div class="feed-building">
                <?php foreach ($recentReservations as $r): ?>
                    <div class="feed-row">
                        <strong><?= htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span>Apartment ID: <?= htmlspecialchars((string) $r['apartment_id'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Dates: <?= htmlspecialchars((string) $r['start_date'], ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) $r['end_date'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Guest: <?= htmlspecialchars(trim((string) (($r['customer_first_name'] ?? '') . ' ' . ($r['customer_last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Price: <?= htmlspecialchars((string) (($r['price_total'] ?? '') . ' ' . ($r['price_currency'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

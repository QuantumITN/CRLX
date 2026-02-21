<?php



require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/reservation_documents.php';

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
                'building_id' => (string) ($building['id'] ?? ''),
                'building_name' => (string) ($building['name'] ?? 'Building'),
                'id' => (string) ($apartment['id'] ?? ''),
                'name' => (string) ($apartment['name'] ?? 'Apartment'),
            ];
        }
    }

    return $rows;
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

function dbUpdateManualReservation(array $r): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE reservations SET apartment_id = :apartment_id, title = :title, status = :status, start_date = :start_date, end_date = :end_date, customer_first_name = :first, customer_last_name = :last, customer_email = :email, customer_phone = :phone, booking_channel = :channel, payment_status = :payment_status, updated_at = NOW() WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
    $ok = $stmt->execute([
        ':apartment_id' => (string) ($r['apartment_id'] ?? ''),
        ':title' => (string) ($r['title'] ?? 'Manual reservation'),
        ':status' => (string) ($r['status'] ?? 'reserved'),
        ':start_date' => (string) ($r['start_date'] ?? ''),
        ':end_date' => (string) ($r['end_date'] ?? ''),
        ':first' => (string) ($r['customer_first_name'] ?? ''),
        ':last' => (string) ($r['customer_last_name'] ?? ''),
        ':email' => (string) ($r['customer_email'] ?? ''),
        ':phone' => (string) ($r['customer_phone'] ?? ''),
        ':channel' => (string) ($r['booking_channel'] ?? ''),
        ':payment_status' => (string) ($r['payment_status'] ?? ''),
        ':uuid' => (string) ($r['reservation_uuid'] ?? ''),
    ]);

    if ($ok) {
        $ev = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, "manual_updated", :payload)');
        $ev->execute([':uuid' => (string) ($r['reservation_uuid'] ?? ''), ':payload' => json_encode($r)]);
    }

    return $ok;
}

function dbCancelManualReservation(string $reservationId): bool
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE reservations SET status = "cancelled", updated_at = NOW() WHERE reservation_uuid = :uuid AND source = "manual" AND archived_flag = 0');
    $ok = $stmt->execute([':uuid' => $reservationId]);
    if ($ok) {
        $ev = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, "manual_cancelled", :payload)');
        $ev->execute([':uuid' => $reservationId, ':payload' => json_encode(['id' => $reservationId])]);
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
        $ev = $pdo->prepare('INSERT INTO reservation_events (reservation_uuid, event_type, payload_json) VALUES (:uuid, "manual_deleted", :payload)');
        $ev->execute([':uuid' => $reservationId, ':payload' => json_encode(['id' => $reservationId])]);
    }

    return $ok;
}

function dbFilteredReservations(array $filters, int $limit = 300): array
{
    $pdo = getDbPdo();
    if (!$pdo) {
        return [];
    }

    $sql = 'SELECT id, reservation_uuid, apartment_id, source, title, status, start_date, end_date, customer_first_name, customer_last_name, customer_email, customer_phone, price_total, price_currency, payment_status, booking_channel, created_at FROM reservations WHERE archived_flag = 0';
    $params = [];

    if (($filters['from'] ?? '') !== '') {
        $sql .= ' AND start_date >= :from_date';
        $params[':from_date'] = (string) $filters['from'];
    }
    if (($filters['to'] ?? '') !== '') {
        $sql .= ' AND end_date <= :to_date';
        $params[':to_date'] = (string) $filters['to'];
    }
    if (($filters['apartment_id'] ?? '') !== '') {
        $sql .= ' AND apartment_id = :apartment_id';
        $params[':apartment_id'] = (string) $filters['apartment_id'];
    }

    $sql .= ' ORDER BY start_date DESC LIMIT ' . max(10, min(1000, $limit));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

$messages = [];
$errors = [];
$buildings = readJson(BUILDINGS_FILE, []);
$apartments = flattenApartments($buildings);
$apartmentNameById = [];
$buildingNameByApartmentId = [];
foreach ($apartments as $apartment) {
    $apartmentNameById[(string) $apartment['id']] = (string) ($apartment['building_name'] . ' - ' . $apartment['name']);
    $buildingNameByApartmentId[(string) $apartment['id']] = (string) ($apartment['building_name'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = (string) ($_POST['intent'] ?? '');

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
            $errors[] = 'MySQL is not configured. Configure DB first.';
        } elseif (dbHasOverlap($reservation['apartment_id'], $reservation['start_date'], $reservation['end_date'])) {
            $errors[] = 'Could not save reservation: overlap detected (double-booking protection).';
        } elseif (dbInsertManualReservation($reservation)) {
            $messages[] = 'Manual reservation saved successfully.';
        } else {
            $errors[] = 'Could not save reservation in MySQL.';
        }
    }

    if ($intent === 'update_manual_reservation') {
        $update = [
            'reservation_uuid' => trim((string) ($_POST['reservation_uuid'] ?? '')),
            'apartment_id' => trim((string) ($_POST['apartment_id'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'reserved')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'customer_first_name' => trim((string) ($_POST['customer_first_name'] ?? '')),
            'customer_last_name' => trim((string) ($_POST['customer_last_name'] ?? '')),
            'customer_email' => trim((string) ($_POST['customer_email'] ?? '')),
            'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
            'booking_channel' => trim((string) ($_POST['booking_channel'] ?? '')),
            'payment_status' => trim((string) ($_POST['payment_status'] ?? '')),
        ];

        if ($update['reservation_uuid'] === '' || $update['apartment_id'] === '' || $update['start_date'] === '' || $update['end_date'] === '') {
            $errors[] = 'Reservation ID, apartment, check-in, and check-out are required for update.';
        } elseif (dbHasOverlap($update['apartment_id'], $update['start_date'], $update['end_date'], $update['reservation_uuid'])) {
            $errors[] = 'Could not update reservation: overlap detected (double-booking protection).';
        } elseif (dbUpdateManualReservation($update)) {
            $messages[] = 'Reservation updated successfully.';
        } else {
            $errors[] = 'Could not update reservation. Only manual reservations can be edited.';
        }
    }

    if ($intent === 'cancel_manual_reservation') {
        $reservationId = trim((string) ($_POST['reservation_uuid'] ?? ''));
        if ($reservationId === '') {
            $errors[] = 'Reservation ID required to cancel.';
        } elseif (dbCancelManualReservation($reservationId)) {
            $messages[] = 'Reservation cancelled.';
        } else {
            $errors[] = 'Could not cancel reservation. Only manual reservations can be cancelled.';
        }
    }

    if ($intent === 'delete_manual_reservation') {
        $reservationId = trim((string) ($_POST['reservation_uuid'] ?? ''));
        if ($reservationId === '') {
            $errors[] = 'Reservation ID required to delete.';
        } elseif (dbDeleteManualReservation($reservationId)) {
            $messages[] = 'Reservation deleted.';
        } else {
            $errors[] = 'Could not delete reservation. Only manual reservations can be deleted.';
        }
    }

    if ($intent === 'upload_reservation_documents') {
        $reservationId = trim((string) ($_POST['reservation_uuid'] ?? ''));
        $upload = $_FILES['reservation_documents'] ?? null;
        if ($reservationId === '' || !is_array($upload)) {
            $errors[] = 'Reservation and files are required.';
        } else {
            $error = null;
            $uploaded = uploadReservationDocuments($reservationId, $upload, $error);
            if ($uploaded !== []) {
                $messages[] = 'Reservation documents uploaded.';
            } else {
                $errors[] = $error ?? 'No documents uploaded.';
            }
        }
    }

    if ($intent === 'delete_reservation_document') {
        $reservationId = trim((string) ($_POST['reservation_uuid'] ?? ''));
        $filename = trim((string) ($_POST['document_name'] ?? ''));
        if ($reservationId === '' || $filename === '') {
            $errors[] = 'Reservation and document are required.';
        } elseif (deleteReservationDocument($reservationId, $filename)) {
            $messages[] = 'Reservation document deleted.';
        } else {
            $errors[] = 'Could not delete reservation document.';
        }
    }

    if ($intent === 'replace_reservation_document') {
        $reservationId = trim((string) ($_POST['reservation_uuid'] ?? ''));
        $filename = trim((string) ($_POST['document_name'] ?? ''));
        $upload = $_FILES['replacement_document'] ?? null;
        if ($reservationId === '' || $filename === '' || !is_array($upload)) {
            $errors[] = 'Reservation, document and replacement file are required.';
        } else {
            $error = null;
            $replaced = replaceReservationDocument($reservationId, $filename, $upload, $error);
            if ($replaced !== []) {
                $messages[] = 'Reservation document replaced.';
            } else {
                $errors[] = $error ?? 'Could not replace document.';
            }
        }
    }
}

$filterFrom = trim((string) ($_GET['from_date'] ?? ''));
$filterTo = trim((string) ($_GET['to_date'] ?? ''));
$filterBuilding = trim((string) ($_GET['building_id'] ?? ''));
$filterApartment = trim((string) ($_GET['apartment_id'] ?? ''));
if ($filterBuilding !== '' && $filterApartment === '') {
    $aptIdsForBuilding = array_map(static fn(array $a): string => $a['id'], array_filter($apartments, static fn(array $a): bool => $a['building_id'] === $filterBuilding));
} else {
    $aptIdsForBuilding = [];
}

$filters = ['from' => $filterFrom, 'to' => $filterTo, 'apartment_id' => $filterApartment];
$recentReservations = dbFilteredReservations($filters);
if ($filterBuilding !== '' && $filterApartment === '') {
    $recentReservations = array_values(array_filter($recentReservations, static fn(array $r): bool => in_array((string) $r['apartment_id'], $aptIdsForBuilding, true)));
}
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
                <option value="cancelled">Cancelled</option>
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
        <h2>Past reservations</h2>
        <form method="get" class="feed-row reservation-form-grid" id="reservations-filter-form">
            <label>From <input type="date" name="from_date" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()"></label>
            <label>To <input type="date" name="to_date" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()"></label>
            <select name="building_id" onchange="this.form.submit()">
                <option value="">All buildings</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?= htmlspecialchars((string) ($building['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= ((string) ($building['id'] ?? '') === $filterBuilding) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($building['name'] ?? 'Building'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select name="apartment_id" onchange="this.form.submit()">
                <option value="">All apartments</option>
                <?php foreach ($apartments as $apartment): ?>
                    <?php if ($filterBuilding !== '' && (string) $apartment['building_id'] !== $filterBuilding) { continue; } ?>
                    <option value="<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ((string) $apartment['id'] === $filterApartment) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($apartment['building_name'] . ' - ' . $apartment['name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (empty($recentReservations)): ?>
            <p class="tiny">No reservations found for selected filters.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Unique ID</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Building</th>
                            <th>Apartment</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Guest</th>
                            <th>Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReservations as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars('RSV-' . str_pad((string) ((int) ($r['id'] ?? 0)), 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) strtoupper((string) ($r['source'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($buildingNameByApartmentId[(string) ($r['apartment_id'] ?? '')] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($apartmentNameById[(string) ($r['apartment_id'] ?? '')] ?? (string) ($r['apartment_id'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($r['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) ($r['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(trim((string) (($r['customer_first_name'] ?? '') . ' ' . ($r['customer_last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) (($r['price_total'] ?? '') . ' ' . ($r['price_currency'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ((string) ($r['source'] ?? '') === 'manual'): ?>
                                        <details>
                                            <summary class="btn ghost small">Manage</summary>
                                            <form method="post" class="reservation-actions-grid">
                                                <input type="hidden" name="intent" value="update_manual_reservation">
                                                <input type="hidden" name="reservation_uuid" value="<?= htmlspecialchars((string) $r['reservation_uuid'], ENT_QUOTES, 'UTF-8') ?>">
                                                <select name="apartment_id" required>
                                                    <?php foreach ($apartments as $apartment): ?>
                                                        <option value="<?= htmlspecialchars((string) $apartment['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ((string) $apartment['id'] === (string) $r['apartment_id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($apartment['building_name'] . ' - ' . $apartment['name']), ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="title" value="<?= htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Title">
                                                <select name="status">
                                                    <?php foreach (['reserved', 'booked', 'checked_out', 'checkout_tomorrow', 'cancelled'] as $status): ?>
                                                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= ((string) $r['status'] === $status) ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="date" name="start_date" value="<?= htmlspecialchars((string) $r['start_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                <input type="date" name="end_date" value="<?= htmlspecialchars((string) $r['end_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                <input type="text" name="customer_first_name" value="<?= htmlspecialchars((string) ($r['customer_first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="First name">
                                                <input type="text" name="customer_last_name" value="<?= htmlspecialchars((string) ($r['customer_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Last name">
                                                <input type="email" name="customer_email" value="<?= htmlspecialchars((string) ($r['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Email">
                                                <input type="text" name="customer_phone" value="<?= htmlspecialchars((string) ($r['customer_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Phone">
                                                <input type="text" name="booking_channel" value="<?= htmlspecialchars((string) ($r['booking_channel'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Channel">
                                                <input type="text" name="payment_status" value="<?= htmlspecialchars((string) ($r['payment_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Payment status">
                                                <button class="btn" type="submit" onclick="return confirm('Confirm reservation update?')">Update</button>
                                                <button class="btn ghost" type="button" onclick="this.closest('details').open=false">Cancel</button>
                                            </form>
                                            <div class="reservation-inline-actions">
                                                <form method="post"><input type="hidden" name="intent" value="cancel_manual_reservation"><input type="hidden" name="reservation_uuid" value="<?= htmlspecialchars((string) $r['reservation_uuid'], ENT_QUOTES, 'UTF-8') ?>"><button class="btn" type="submit" onclick="return confirm('Confirm cancellation of this reservation?')">Cancel reservation</button></form>
                                                <form method="post"><input type="hidden" name="intent" value="delete_manual_reservation"><input type="hidden" name="reservation_uuid" value="<?= htmlspecialchars((string) $r['reservation_uuid'], ENT_QUOTES, 'UTF-8') ?>"><button class="btn" type="submit" onclick="return confirm('Delete this reservation permanently?')">Delete</button></form>
                                            </div>

                                            <?php $docs = listReservationDocuments((string) $r['reservation_uuid']); ?>
                                            <div class="doc-section">
                                                <div class="doc-section-head">
                                                    <strong>Customer documents</strong>
                                                    <span class="tiny"><?= count($docs) ?>/5 files</span>
                                                </div>
                                                <form method="post" enctype="multipart/form-data" class="reservation-inline-actions">
                                                    <input type="hidden" name="intent" value="upload_reservation_documents">
                                                    <input type="hidden" name="reservation_uuid" value="<?= htmlspecialchars((string) $r['reservation_uuid'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="file" name="reservation_documents[]" accept=".png,.jpg,.jpeg,.jpn,.pdf" multiple>
                                                    <button class="btn ghost" type="submit">Upload document(s)</button>
                                                </form>
                                                <div class="doc-grid">
                                                    <?php if (empty($docs)): ?>
                                                        <div class="doc-placeholder">No documents uploaded yet.</div>
                                                    <?php else: ?>
                                                        <?php foreach ($docs as $doc): ?>
                                                            <div class="doc-card">
                                                                <?php if (in_array((string) ($doc['ext'] ?? ''), ['png', 'jpg', 'jpeg', 'jpn'], true)): ?>
                                                                    <a href="<?= htmlspecialchars((string) ($doc['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars((string) ($doc['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="document preview" class="doc-thumb"></a>
                                                                <?php else: ?>
                                                                    <a class="doc-pdf" href="<?= htmlspecialchars((string) ($doc['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">PDF</a>
                                                                <?php endif; ?>
                                                                <form method="post" onsubmit="return confirm('Delete this document?')">
                                                                    <input type="hidden" name="intent" value="delete_reservation_document">
                                                                    <input type="hidden" name="reservation_uuid" value="<?= htmlspecialchars((string) $r['reservation_uuid'], ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="document_name" value="<?= htmlspecialchars((string) ($doc['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <button class="btn small" type="submit">Delete document</button>
                                                                </form>
                                                                <form method="post" enctype="multipart/form-data" class="doc-replace-form">
                                                                    <input type="hidden" name="intent" value="replace_reservation_document">
                                                                    <input type="hidden" name="reservation_uuid" value="<?= htmlspecialchars((string) $r['reservation_uuid'], ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="document_name" value="<?= htmlspecialchars((string) ($doc['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="file" name="replacement_document" accept=".png,.jpg,.jpeg,.jpn,.pdf" required>
                                                                    <button class="btn ghost small" type="submit">Replace document</button>
                                                                </form>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <span class="tiny">Read only (synced)</span>
                                    <?php endif; ?>
                                </td>
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

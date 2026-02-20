<?php

const RESERVATION_DOCS_ROOT = __DIR__ . '/data/fileshare/Reservations documents';
const RESERVATION_DOCS_MAX_FILES = 5;
const RESERVATION_DOC_ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'jpn', 'pdf'];

function ensureReservationDocsRoot(): void
{
    if (!is_dir(RESERVATION_DOCS_ROOT)) {
        mkdir(RESERVATION_DOCS_ROOT, 0775, true);
    }
}

function sanitizeReservationId(string $reservationId): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '_', trim($reservationId)) ?? '';
}

function reservationDocsDir(string $reservationId): string
{
    $safeId = sanitizeReservationId($reservationId);

    return RESERVATION_DOCS_ROOT . '/' . $safeId;
}

function reservationDocUrl(string $reservationId, string $filename): string
{
    $safeId = sanitizeReservationId($reservationId);

    return 'data/fileshare/Reservations%20documents/' . rawurlencode($safeId) . '/' . rawurlencode($filename);
}

function listReservationDocuments(string $reservationId): array
{
    $safeId = sanitizeReservationId($reservationId);
    if ($safeId === '') {
        return [];
    }

    $dir = reservationDocsDir($safeId);
    if (!is_dir($dir)) {
        return [];
    }

    $items = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $full = $dir . '/' . $name;
        if (!is_file($full)) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $items[] = [
            'name' => $name,
            'ext' => $ext,
            'size' => filesize($full) ?: 0,
            'url' => reservationDocUrl($safeId, $name),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) $a['name'], (string) $b['name']);
    });

    return $items;
}

function normalizeUploadSet(array $input): array
{
    if (!isset($input['name'])) {
        return [];
    }

    $names = is_array($input['name']) ? $input['name'] : [$input['name']];
    $tmpNames = is_array($input['tmp_name'] ?? null) ? $input['tmp_name'] : [($input['tmp_name'] ?? '')];
    $errors = is_array($input['error'] ?? null) ? $input['error'] : [($input['error'] ?? UPLOAD_ERR_NO_FILE)];

    $files = [];
    foreach ($names as $idx => $name) {
        $files[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($tmpNames[$idx] ?? ''),
            'error' => (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE),
        ];
    }

    return $files;
}

function safeUploadName(string $rawName): string
{
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($rawName)) ?? '';

    return trim($clean, '._-');
}

function uploadReservationDocuments(string $reservationId, array $uploadInput, ?string &$error = null): array
{
    ensureReservationDocsRoot();

    $safeId = sanitizeReservationId($reservationId);
    if ($safeId === '') {
        $error = 'Invalid reservation ID.';

        return [];
    }

    $files = normalizeUploadSet($uploadInput);
    if ($files === []) {
        $error = 'No files selected.';

        return [];
    }

    $existing = listReservationDocuments($safeId);
    $incomingCount = 0;
    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $incomingCount++;
        }
    }

    if ((count($existing) + $incomingCount) > RESERVATION_DOCS_MAX_FILES) {
        $error = 'Maximum ' . RESERVATION_DOCS_MAX_FILES . ' documents allowed per reservation.';

        return [];
    }

    $dir = reservationDocsDir($safeId);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $uploaded = [];
    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $original = safeUploadName((string) ($file['name'] ?? 'document'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, RESERVATION_DOC_ALLOWED_EXT, true)) {
            $error = 'Only PNG, JPG/JPEG, or PDF files are allowed.';
            continue;
        }

        $base = pathinfo($original, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'document';
        }
        $name = $base . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (move_uploaded_file((string) ($file['tmp_name'] ?? ''), $dest)) {
            $uploaded[] = [
                'name' => $name,
                'ext' => $ext,
                'size' => filesize($dest) ?: 0,
                'url' => reservationDocUrl($safeId, $name),
            ];
        }
    }

    return $uploaded;
}

function deleteReservationDocument(string $reservationId, string $filename): bool
{
    $safeId = sanitizeReservationId($reservationId);
    $safeName = basename($filename);
    if ($safeId === '' || $safeName === '' || $safeName !== $filename) {
        return false;
    }

    $path = reservationDocsDir($safeId) . '/' . $safeName;
    if (!is_file($path)) {
        return false;
    }

    return unlink($path);
}

function reservationDocumentsByIds(array $reservationIds): array
{
    $result = [];
    foreach ($reservationIds as $id) {
        $sid = sanitizeReservationId((string) $id);
        if ($sid === '') {
            continue;
        }
        $result[$sid] = listReservationDocuments($sid);
    }

    return $result;
}

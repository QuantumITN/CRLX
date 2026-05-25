<?php



const BRANDING_FILE = __DIR__ . '/data/branding.json';

function readBranding(): array
{
    if (!file_exists(BRANDING_FILE)) {
        return ['logo_path' => ''];
    }

    $raw = file_get_contents(BRANDING_FILE);
    if ($raw === false) {
        return ['logo_path' => ''];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : ['logo_path' => ''];
}

function writeBranding(array $branding): bool
{
    $json = json_encode($branding, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return is_string($json) && file_put_contents(BRANDING_FILE, $json) !== false;
}

function currentLogoUrl(): string
{
    $branding = readBranding();
    $path = (string) ($branding['logo_path'] ?? '');

    if ($path === '') {
        return '';
    }

    return $path;
}

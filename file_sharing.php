<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui.php';

requireAuth();

const FS_BASE = __DIR__ . '/data/fileshare';
const FS_META = __DIR__ . '/data/fileshare_meta.json';

if (!is_dir(FS_BASE)) {
    mkdir(FS_BASE, 0775, true);
}

function readMeta(): array
{
    if (!file_exists(FS_META)) {
        return [];
    }
    $raw = file_get_contents(FS_META);
    $json = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($json) ? $json : [];
}

function writeMeta(array $meta): void
{
    file_put_contents(FS_META, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function safeName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($name)) ?? '';
    return trim($name, '._-');
}

$messages = [];
$errors = [];
$meta = readMeta();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intent = (string) ($_POST['intent'] ?? '');

    if ($intent === 'create_folder') {
        $folder = safeName((string) ($_POST['folder_name'] ?? ''));
        if ($folder === '') {
            $errors[] = 'Folder name is required.';
        } else {
            $path = FS_BASE . '/' . $folder;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
                $messages[] = 'Folder created.';
            }
        }
    }

    if ($intent === 'upload_file') {
        $folder = safeName((string) ($_POST['folder'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'general'));
        if ($folder === '' || !is_dir(FS_BASE . '/' . $folder)) {
            $errors[] = 'Select a valid folder.';
        } elseif (!isset($_FILES['file_item']) || !is_array($_FILES['file_item'])) {
            $errors[] = 'No file uploaded.';
        } else {
            $file = $_FILES['file_item'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload failed.';
            } else {
                $name = safeName((string) ($file['name'] ?? ''));
                if ($name === '') {
                    $errors[] = 'Invalid filename.';
                } else {
                    $destRel = $folder . '/' . $name;
                    $dest = FS_BASE . '/' . $destRel;
                    if (move_uploaded_file((string) $file['tmp_name'], $dest)) {
                        $meta[$destRel] = [
                            'category' => $category,
                            'uploaded_at' => gmdate('c'),
                        ];
                        writeMeta($meta);
                        $messages[] = 'File uploaded.';
                    } else {
                        $errors[] = 'Could not store uploaded file.';
                    }
                }
            }
        }
    }

    if ($intent === 'delete_file') {
        $rel = (string) ($_POST['file_rel'] ?? '');
        $file = FS_BASE . '/' . $rel;
        if ($rel !== '' && str_starts_with(realpath(dirname($file)) ?: '', realpath(FS_BASE) ?: '')) {
            if (file_exists($file)) {
                unlink($file);
                unset($meta[$rel]);
                writeMeta($meta);
                $messages[] = 'File deleted.';
            }
        }
    }

    if ($intent === 'save_text_file') {
        $rel = (string) ($_POST['file_rel'] ?? '');
        $content = (string) ($_POST['file_content'] ?? '');
        $file = FS_BASE . '/' . $rel;
        if ($rel !== '' && file_exists($file) && is_writable($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['txt', 'md', 'json', 'csv', 'xml', 'log', 'php', 'html', 'css', 'js'], true)) {
                file_put_contents($file, $content);
                $messages[] = 'File saved.';
            } else {
                $errors[] = 'This file type is not editable here.';
            }
        }
    }
}

$folders = array_values(array_filter(scandir(FS_BASE) ?: [], static fn(string $n): bool => $n !== '.' && $n !== '..' && is_dir(FS_BASE . '/' . $n)));
$selectedFolder = safeName((string) ($_GET['folder'] ?? ($folders[0] ?? '')));
$files = [];
if ($selectedFolder !== '' && is_dir(FS_BASE . '/' . $selectedFolder)) {
    foreach (array_values(array_filter(scandir(FS_BASE . '/' . $selectedFolder) ?: [], static fn(string $n): bool => $n !== '.' && $n !== '..')) as $f) {
        $rel = $selectedFolder . '/' . $f;
        $files[] = [
            'name' => $f,
            'rel' => $rel,
            'path' => FS_BASE . '/' . $rel,
            'category' => (string) ($meta[$rel]['category'] ?? 'general'),
        ];
    }
}

$editRel = (string) ($_GET['edit'] ?? '');
$editContent = '';
$editable = false;
if ($editRel !== '') {
    $editPath = FS_BASE . '/' . $editRel;
    if (file_exists($editPath)) {
        $ext = strtolower(pathinfo($editPath, PATHINFO_EXTENSION));
        $editable = in_array($ext, ['txt', 'md', 'json', 'csv', 'xml', 'log', 'php', 'html', 'css', 'js'], true);
        if ($editable) {
            $raw = file_get_contents($editPath);
            $editContent = is_string($raw) ? $raw : '';
        }
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>File sharing - CLR Calendar</title>
<link rel="stylesheet" href="assets/styles.css"></head>
<body>
<?php renderSiteHeader('File sharing'); ?>
<main class="container page-with-header">
<section class="panel">
<h2>File sharing service</h2>
<?php foreach ($errors as $e): ?><div class="flash error"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<?php foreach ($messages as $m): ?><div class="flash success"><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<div class="admin-grid">
<form method="post" class="admin-card">
<h3>Create folder</h3>
<input type="hidden" name="intent" value="create_folder">
<input type="text" name="folder_name" placeholder="Folder name" required>
<button class="btn" type="submit">Create folder</button>
</form>

<form method="post" enctype="multipart/form-data" class="admin-card">
<h3>Upload file</h3>
<input type="hidden" name="intent" value="upload_file">
<select name="folder" required>
<?php foreach ($folders as $f): ?><option value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
</select>
<input type="text" name="category" placeholder="Category (photos/docs/contracts)" value="general">
<input type="file" name="file_item" required>
<button class="btn accent" type="submit">Upload</button>
</form>
</div>
</section>

<section class="panel">
<h2>Folder files</h2>
<form method="get" class="feed-row">
<select name="folder" onchange="this.form.submit()">
<?php foreach ($folders as $f): ?><option value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>" <?= $f === $selectedFolder ? 'selected' : '' ?>><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
</select>
</form>
<div class="feed-building">
<?php foreach ($files as $file): ?>
<div class="feed-row">
<strong><?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?></strong>
<span>Category: <?= htmlspecialchars($file['category'], ENT_QUOTES, 'UTF-8') ?></span>
<div class="reservation-inline-actions">
<a class="btn ghost" href="<?= htmlspecialchars('data/fileshare/' . $file['rel'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a>
<a class="btn ghost" href="?folder=<?= urlencode($selectedFolder) ?>&edit=<?= urlencode($file['rel']) ?>">Edit</a>
<form method="post" onsubmit="return confirm('Delete file?')">
<input type="hidden" name="intent" value="delete_file"><input type="hidden" name="file_rel" value="<?= htmlspecialchars($file['rel'], ENT_QUOTES, 'UTF-8') ?>">
<button class="btn" type="submit">Delete</button>
</form>
</div>
</div>
<?php endforeach; ?>
</div>
</section>

<?php if ($editable): ?>
<section class="panel">
<h2>Edit file: <?= htmlspecialchars($editRel, ENT_QUOTES, 'UTF-8') ?></h2>
<form method="post" class="feed-row">
<input type="hidden" name="intent" value="save_text_file"><input type="hidden" name="file_rel" value="<?= htmlspecialchars($editRel, ENT_QUOTES, 'UTF-8') ?>">
<textarea name="file_content" style="min-height:320px"><?= htmlspecialchars($editContent, ENT_QUOTES, 'UTF-8') ?></textarea>
<button class="btn accent" type="submit">Save file</button>
</form>
</section>
<?php endif; ?>
</main></body></html>

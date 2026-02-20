<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui.php';

$errors = [];
$redirect = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? '/index.php');
if ($redirect === '' || str_starts_with($redirect, 'http')) {
    $redirect = '/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if (verifyAdminCredentials($user, $pass)) {
        $_SESSION['auth_user'] = getAdminUsername();
        if (!headers_sent()) {
            header('Location: ' . $redirect);
            exit;
        }
        echo '<script>window.location.href=' . json_encode($redirect) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }

    $errors[] = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - CLR Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('Login'); ?>
<main class="container page-with-header">
    <section class="panel login-panel">
        <h1>CLR Calendar Login</h1>
        <p class="tiny">Single-admin access required.</p>
        <?php foreach ($errors as $error): ?>
            <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <form method="post" class="feed-row">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button class="btn" type="submit">Login</button>
        </form>
    </section>
</main>
</body>
</html>

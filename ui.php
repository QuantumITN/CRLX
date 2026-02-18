<?php

declare(strict_types=1);

require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/auth.php';

function renderSiteHeader(string $pageTitle = 'CLR Calendar'): void
{
    $logo = currentLogoUrl();
    $user = isAuthenticated() ? getAdminUsername() : '';
    ?>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="brand" href="index.php">
                <?php if ($logo !== ''): ?>
                    <img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="brand-logo">
                <?php else: ?>
                    <div class="brand-badge">CR</div>
                <?php endif; ?>
                <div class="brand-text">
                    <strong>CLR Calendar</strong>
                    <span><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </a>
            <nav class="top-nav">
                <?php if (isAuthenticated()): ?>
                    <a class="btn ghost" href="index.php">Calendar</a>
                    <a class="btn ghost" href="admin_tools.php">Admin Tools</a>
                    <a class="btn ghost" href="reservations.php">Reservations</a>
                    <a class="btn ghost" href="settings.php">Settings</a>
                    <a class="btn ghost" href="healthcheck.php">Health</a>
                    <span class="user-pill">👤 <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?></span>
                    <a class="btn" href="logout.php">Logout</a>
                <?php else: ?>
                    <a class="btn" href="login.php">Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <?php
}

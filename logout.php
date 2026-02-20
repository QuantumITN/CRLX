<?php

require_once __DIR__ . '/auth.php';
logoutAdminUser();
header('Location: login.php');
exit;

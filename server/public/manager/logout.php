<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../manager/common.php';

manager_logout();
header('Location: ' . manager_base_url('login.php'));
exit;

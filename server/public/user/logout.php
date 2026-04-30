<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$gid = (int)($_GET['gid'] ?? ($_SESSION['gid'] ?? 0));
$bid = (int)($_GET['bid'] ?? ($_SESSION['bid'] ?? 0));

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();

if ($gid > 0 && $bid > 0) {
    header('Location: ../join_battle.php?gid=' . $gid . '&bid=' . $bid);
    exit;
}

header('Location: ../battle.php');
exit;

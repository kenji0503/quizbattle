<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$gid = (int)($_GET['gid'] ?? ($_SESSION['gid'] ?? 0));
$bid = (int)($_GET['bid'] ?? ($_SESSION['bid'] ?? 0));

if ($gid > 0 && $bid > 0) {
    header('Location: ../join_battle.php?gid=' . $gid . '&bid=' . $bid);
    exit;
}

header('Location: ../battle.php');
exit;

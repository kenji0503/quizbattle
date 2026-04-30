<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/common/battle_common.php';

$bid = (int)($_GET['bid'] ?? 0);
$gid = (int)($_GET['gid'] ?? 0);

if ($bid <= 0) {
    header('Location: battle.php');
    exit;
}

if ($gid <= 0) {
    try {
        $pdo = dbConnectPDO();
        $stmt = $pdo->prepare("SELECT gid FROM qb_battle WHERE bid = ? LIMIT 1");
        $stmt->execute([$bid]);
        $gid = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $gid = 0;
    }
}

if ($gid <= 0) {
    header('Location: battle.php?mode=show&bid=' . $bid);
    exit;
}

header('Location: ready.php?gid=' . $gid . '&bid=' . $bid);
exit;

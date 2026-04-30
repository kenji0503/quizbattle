<?php
header('Content-Type: application/json; charset=UTF-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$gid = filter_input(INPUT_GET, 'gid', FILTER_VALIDATE_INT);
$bid = filter_input(INPUT_GET, 'bid', FILTER_VALIDATE_INT);
if (!$gid) {
    echo json_encode(['participants' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
    SELECT name
      FROM qb_battle_participants
     WHERE gid = :gid
";
$params = [':gid' => $gid];

if ($bid) {
    $sql .= " AND bid = :bid";
    $params[':bid'] = $bid;
}

$sql .= " AND last_ping > NOW() - INTERVAL 60 SECOND ORDER BY joined_at ASC";

$st = $pdo->prepare($sql);
$st->execute($params);

$names = $st->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['participants' => $names], JSON_UNESCAPED_UNICODE);

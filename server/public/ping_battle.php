<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/common/battle_common.php';

try {
    $gid = (int)($_GET['gid'] ?? 0);
    $bid = (int)($_GET['bid'] ?? 0);
    $uid = (int)($_SESSION['uid'] ?? 0);
    $name = trim((string)($_SESSION['name'] ?? ''));

    if ($gid <= 0 || $bid <= 0 || $uid <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'invalid session']);
        exit;
    }

    $pdo = dbConnectPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        INSERT INTO qb_battle_participants (bid, gid, uid, name, joined_at, last_ping)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            last_ping = NOW()
    ");
    $stmt->execute([$bid, $gid, $uid, $name !== '' ? $name : ('Player#' . $uid)]);

    battle_ws_publish(
        ["lobby:{$bid}:{$gid}"],
        'lobby.snapshot',
        battle_collect_lobby_snapshot($pdo, $bid, $gid)
    );

    echo json_encode(['ok' => true, 'ts' => (int)floor(microtime(true) * 1000)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'server error']);
}

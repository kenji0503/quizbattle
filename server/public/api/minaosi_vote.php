<?php
// /test/api/minaosi_vote.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../common/battle_common.php'; // dbConnectPDO()

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);

    $cate1 = (int)($json['cate1'] ?? 0);
    $cate2 = (int)($json['cate2'] ?? 0);
    $id    = (int)($json['id']    ?? 0);
    $num   = (int)($json['num']   ?? 0);
    $act   = (string)($json['action'] ?? '');

    if (!$cate1 || !$cate2 || !$id || !$num || !in_array($act, ['good', 'bad'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid params']);
        exit;
    }

    // comment を決める
    $comment = ($act === 'good') ? 'いいね' : 'よくない';

    // 名前の決定（優先：セッション → 現在の参加者 → 匿名）
    $uid  = (int)($_SESSION['uid'] ?? 0);
    $name = trim((string)($_SESSION['name'] ?? ''));

    $pdo = dbConnectPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($name === '' && $uid > 0) {
        $st = $pdo->prepare("
            SELECT name
              FROM qb_battle_participants
             WHERE uid = :uid
             ORDER BY last_ping DESC, joined_at DESC
             LIMIT 1
        ");
        $st->execute([':uid' => $uid]);
        $name = (string)($st->fetchColumn() ?: '');
    }
    if ($name === '') {
        $name = '匿名';
    }

    // INSERT
    $ins = $pdo->prepare("
        INSERT INTO q_minaosi
            (reqdate, cate1, cate2, id, num, name, comment, taiou, status)
        VALUES
            (NOW(), :c1, :c2, :id, :num, :name, :comment, 'バトル', 0)
    ");
    $ins->execute([
        ':c1' => $cate1,
        ':c2' => $cate2,
        ':id' => $id,
        ':num' => $num,
        ':name' => $name,
        ':comment' => $comment,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error']);
}
